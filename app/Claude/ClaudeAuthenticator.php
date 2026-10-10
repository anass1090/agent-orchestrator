<?php

namespace App\Claude;

use App\Models\ClaudeLogin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Drives `claude auth login` from the web UI instead of a shell: the CLI
 * prints an Anthropic-hosted authorize URL and then blocks reading a pasted
 * code from stdin, so we start it detached with its stdin wired to a FIFO,
 * capture the URL from its log, and later write the pasted code to that FIFO.
 */
class ClaudeAuthenticator
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $result = Process::run([config('pipeline.claude.binary'), 'auth', 'status', '--json']);
        $decoded = json_decode($result->output(), true);
        $status = is_array($decoded) ? $decoded : ['loggedIn' => false];

        $status['attempt_alive'] = ClaudeLogin::current()->hasLiveProcess();

        return $status;
    }

    public function start(): string
    {
        $this->terminatePreviousAttempt();

        $id = Str::random(12);
        $fifoPath = storage_path("app/claude-auth/{$id}.fifo");
        $logPath = storage_path("app/claude-auth/{$id}.log");

        $this->createFifo($fifoPath);
        File::put($logPath, '');

        $pid = $this->launchLoginProcess($fifoPath, $logPath);
        $url = $this->captureAuthorizeUrl($logPath);

        ClaudeLogin::current()->forceFill([
            'pid' => $pid,
            'fifo_path' => $fifoPath,
            'log_path' => $logPath,
            'authorize_url' => $url,
        ])->save();

        return $url;
    }

    public function submitCode(string $code): void
    {
        $fifoPath = ClaudeLogin::current()->fifo_path;

        if ($fifoPath === null) {
            throw new RuntimeException('Er loopt geen inlogpoging die op een code wacht.');
        }

        File::put($fifoPath, $code."\n");
    }

    public function logout(): void
    {
        Process::run([config('pipeline.claude.binary'), 'auth', 'logout']);

        ClaudeLogin::current()->delete();
    }

    /**
     * A previous, abandoned attempt would otherwise be left running forever,
     * blocked reading a FIFO nobody will ever write to again.
     */
    protected function terminatePreviousAttempt(): void
    {
        $login = ClaudeLogin::current();

        if ($login->hasLiveProcess()) {
            Process::run(['kill', '-9', (string) $login->pid]);
        }
    }

    protected function createFifo(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));

        if (File::exists($path)) {
            File::delete($path);
        }

        Process::run(['mkfifo', '-m', '600', $path])->throw();
    }

    protected function launchLoginProcess(string $fifoPath, string $logPath): int
    {
        $command = sprintf('%s auth login --claudeai', escapeshellarg(config('pipeline.claude.binary')));

        // Opening a FIFO for reading alone blocks until a writer appears, which
        // would stall claude's own stdin setup before it even prints the URL.
        // Opening it read-write on fd 3 first sidesteps that, and keeps the pipe
        // from ever hitting EOF before a code is submitted.
        $script = sprintf(
            'exec 3<>%s; %s <&3 >%s 2>&1 & echo $!',
            $fifoPath,
            $command,
            $logPath,
        );

        $pid = Process::timeout(5)->run(sprintf('setsid sh -c %s', escapeshellarg($script)))->output();

        return (int) trim($pid);
    }

    protected function captureAuthorizeUrl(string $logPath): string
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $contents = File::exists($logPath) ? File::get($logPath) : '';

            if (preg_match('/visit:\s*(\S+)/', $contents, $matches)) {
                return $matches[1];
            }

            usleep(150_000);
        }

        throw new RuntimeException('Claude Code liet geen inlog-URL zien binnen de tijd.');
    }
}
