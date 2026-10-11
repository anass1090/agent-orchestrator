<?php

namespace App\Enums;

enum Phase: string
{
    case Setup = 'setup';
    case Plan = 'plan';
    case Build = 'build';
    case Pint = 'pint';
    case Phpstan = 'phpstan';
    case Tests = 'tests';
    case Fix = 'fix';
    case Commit = 'commit';
    case Push = 'push';
    case Merge = 'merge';
    case Cleanup = 'cleanup';

    /**
     * The quality checks, in the order they must run.
     *
     * @return list<self>
     */
    public static function checks(): array
    {
        return [self::Pint, self::Phpstan, self::Tests];
    }

    public function isCheck(): bool
    {
        return in_array($this, self::checks(), true);
    }

    public function isAgent(): bool
    {
        return in_array($this, [self::Plan, self::Build, self::Fix], true);
    }
}
