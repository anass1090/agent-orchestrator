<?php

namespace App\Pipeline;

use App\Enums\Phase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Loads per-phase prompts from resources/prompts/{phase}.system.md and
 * {phase}.user.md, replacing {{placeholders}}.
 */
class PromptRepository
{
    /**
     * @param  array<string, string|int>  $variables
     */
    public function system(Phase $phase, array $variables = []): string
    {
        return $this->render("{$phase->value}.system.md", $variables);
    }

    /**
     * @param  array<string, string|int>  $variables
     */
    public function user(Phase $phase, array $variables = []): string
    {
        return $this->render("{$phase->value}.user.md", $variables);
    }

    /**
     * @param  array<string, string|int>  $variables
     */
    protected function render(string $file, array $variables): string
    {
        $path = resource_path("prompts/{$file}");

        if (! File::exists($path)) {
            throw new InvalidArgumentException("Prompt file [{$path}] does not exist.");
        }

        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return trim(strtr(File::get($path), $replacements));
    }
}
