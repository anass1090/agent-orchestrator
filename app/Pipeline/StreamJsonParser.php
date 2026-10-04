<?php

namespace App\Pipeline;

/**
 * Turns arbitrary stdout chunks into complete decoded JSON lines.
 */
class StreamJsonParser
{
    protected string $buffer = '';

    /**
     * @return list<array<string, mixed>>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $messages = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            if (($message = $this->decode($line)) !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Decode whatever is left once the process has exited.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $line = $this->buffer;
        $this->buffer = '';

        $message = $this->decode($line);

        return $message === null ? [] : [$message];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decode(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);

        if (! is_array($decoded) || ! isset($decoded['type'])) {
            return ['type' => 'stream.invalid', 'raw' => $line];
        }

        return $decoded;
    }
}
