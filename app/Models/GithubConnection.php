<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton row holding the GitHub access token used to list and clone the
 * connected account's repositories, including private ones.
 *
 * @property int $id
 * @property string|null $access_token
 * @property string|null $username
 * @property string|null $avatar_url
 * @property Carbon|null $connected_at
 */
#[Fillable(['access_token', 'username', 'avatar_url', 'connected_at'])]
class GithubConnection extends Model
{
    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function connected(): bool
    {
        return $this->access_token !== null;
    }

    /**
     * Null when not connected, so pages never need to shape this themselves.
     *
     * @return array{username: string, avatar_url: ?string, connected_at: ?string}|null
     */
    public static function summary(): ?array
    {
        $connection = self::current();

        if (! $connection?->connected()) {
            return null;
        }

        return [
            'username' => $connection->username,
            'avatar_url' => $connection->avatar_url,
            'connected_at' => $connection->connected_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }
}
