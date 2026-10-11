<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton row holding the GitHub OAuth App credentials (set up once, from
 * the Connections page) and the access token that results from connecting
 * with them, used to list and clone the account's repositories, including
 * private ones.
 *
 * @property int $id
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $access_token
 * @property string|null $username
 * @property string|null $avatar_url
 * @property Carbon|null $connected_at
 */
#[Fillable(['client_id', 'client_secret', 'access_token', 'username', 'avatar_url', 'connected_at'])]
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

    public static function configured(): bool
    {
        $connection = self::current();

        return $connection?->client_id !== null && $connection->client_secret !== null;
    }

    /**
     * Clears the token, keeping the OAuth App credentials: those are a
     * one-time setup, not part of a single connect/disconnect cycle.
     */
    public function disconnect(): void
    {
        $this->forceFill(['access_token' => null, 'username' => null, 'avatar_url' => null, 'connected_at' => null])->save();
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
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }
}
