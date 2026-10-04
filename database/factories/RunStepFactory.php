<?php

namespace Database\Factories;

use App\Enums\Phase;
use App\Enums\StepStatus;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunStep>
 */
class RunStepFactory extends Factory
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
            'attempt' => 0,
            'status' => StepStatus::Succeeded,
            'started_at' => now(),
        ];
    }
}
