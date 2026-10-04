<?php

namespace Database\Factories;

use App\Enums\Phase;
use App\Models\Run;
use App\Models\RunEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunEvent>
 */
class RunEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'phase' => Phase::Plan,
            'sequence' => fake()->unique()->numberBetween(1, 100000),
            'type' => 'assistant.text',
            'summary' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
