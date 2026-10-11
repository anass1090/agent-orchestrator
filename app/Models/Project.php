<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $path
 * @property string $branch
 * @property string|null $remote
 * @property string|null $github_full_name
 * @property array<string, string>|null $env_overrides
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $succeeded_count Aggregate, loaded with withCount()
 * @property-read int|null $failed_count Aggregate, loaded with withCount()
 * @property-read int|null $error_count Aggregate, loaded with withCount()
 * @property-read string|null $runs_sum_cost_usd Aggregate, loaded with withSum()
 */
#[Fillable(['name', 'path', 'branch', 'remote', 'github_full_name', 'env_overrides'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'env_overrides' => 'array',
        ];
    }
}
