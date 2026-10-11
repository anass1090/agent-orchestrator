<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row tracking an in-progress `claude auth login` attempt (its
 * process id and the FIFO its stdin is waiting on), so the login widget can
 * be driven across separate HTTP requests. Whether Claude is actually
 * connected is never stored here — that's always read live from `claude
 * auth status`.
 *
 * @property int $id
 * @property int|null $pid
 * @property string|null $fifo_path
 * @property string|null $log_path
 * @property string|null $authorize_url
 */
#[Fillable(['pid', 'fifo_path', 'log_path', 'authorize_url'])]
class ClaudeLogin extends Model
{
    public static function current(): self
    {
        return self::query()->first() ?? self::create();
    }

    /**
     * Whether the `claude auth login` process started by `start()` is still
     * running (retrying after an invalid code counts as still running).
     */
    public function hasLiveProcess(): bool
    {
        if ($this->pid === null || ! is_readable("/proc/{$this->pid}/cmdline")) {
            return false;
        }

        $commandLine = (string) @file_get_contents("/proc/{$this->pid}/cmdline");

        return str_contains($commandLine, 'claude') && str_contains($commandLine, 'login');
    }
}
