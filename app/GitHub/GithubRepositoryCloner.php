<?php

namespace App\GitHub;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Clones a GitHub repository so it can be registered as a Project. Runs git
 * directly via Process, never through Shell/CommandRunner: that records the
 * full command as a visible pipeline event, which would leak the token
 * embedded in the clone URL.
 *
 * The clone URL is always derived from the validated "owner/repo" full name,
 * never taken from client input: a client-supplied URL would let the token
 * be sent to an arbitrary host (SSRF plus token exfiltration).
 */
class GithubRepositoryCloner
{
    public function clone(string $fullName, string $token): string
    {
        $path = $this->destinationPath($fullName);

        $this->ensureDestinationIsFree($path);
        $this->cloneWithToken($fullName, $token, $path);
        $this->stripTokenFromRemote($path, $fullName);

        return $path;
    }

    protected function destinationPath(string $fullName): string
    {
        $root = rtrim((string) config('pipeline.github_projects_path'), '/');

        return $root.'/'.str_replace('/', '-', $fullName);
    }

    protected function ensureDestinationIsFree(string $path): void
    {
        if (is_dir($path)) {
            throw new RuntimeException("[{$path}] bestaat al. Verwijder de map of registreer het project handmatig.");
        }

        File::ensureDirectoryExists(dirname($path));
    }

    protected function cloneWithToken(string $fullName, string $token, string $path): void
    {
        $url = "https://x-access-token:{$token}@github.com/{$fullName}.git";

        $result = Process::timeout(120)->run(['git', 'clone', $url, $path]);

        if (! $result->successful()) {
            throw new RuntimeException('Clonen van de repository is mislukt: '.str_replace($token, '***', $result->errorOutput()));
        }
    }

    /**
     * The clone leaves the token in the origin remote's URL, in plain text in
     * .git/config. An agent with shell access in this project's worktree
     * could read it straight out of there, so it's wiped immediately after.
     */
    protected function stripTokenFromRemote(string $path, string $fullName): void
    {
        Process::run(['git', '-C', $path, 'remote', 'set-url', 'origin', "https://github.com/{$fullName}.git"])->throw();
    }
}
