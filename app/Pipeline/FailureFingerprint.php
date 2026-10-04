<?php

namespace App\Pipeline;

/**
 * Identifies "the same failure" across fix attempts by hashing check output
 * with the volatile parts (timings, memory, paths, colours) removed.
 */
class FailureFingerprint
{
    public function make(string $check, string $output, string $worktreePath = ''): string
    {
        return sha1($check."\n".$this->normalize($output, $worktreePath));
    }

    public function normalize(string $output, string $worktreePath = ''): string
    {
        $output = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $output) ?? $output;

        if ($worktreePath !== '') {
            $output = str_replace(rtrim($worktreePath, '/'), '<worktree>', $output);
        }

        $patterns = [
            '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?/' => '<time>',
            '/\b\d+(\.\d+)?\s?(ms|s|sec|secs|seconds|minutes?)\b/i' => '<duration>',
            '/\b\d+(\.\d+)?\s?(B|KB|KiB|MB|MiB|GB|GiB)\b/' => '<memory>',
            '/\/tmp\/[\w.\/-]+/' => '<tmp>',
            '/[ \t]+/' => ' ',
            '/\n{2,}/' => "\n",
        ];

        return trim(preg_replace(array_keys($patterns), array_values($patterns), $output) ?? $output);
    }
}
