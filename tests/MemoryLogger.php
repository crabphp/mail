<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Psr\Log\AbstractLogger;

/**
 * Keeps what is logged, for a test to read.
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Everything logged, as one text: messages with their context as JSON.
     */
    public function text(): string
    {
        $lines = [];
        foreach ($this->records as $record) {
            $replacements = [];
            foreach ($record['context'] as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $replacements['{' . $key . '}'] = (string) $value;
            }
            $message = strtr($record['message'], $replacements);
            $lines[] = $record['level'] . ' ' . $message . ' ' . json_encode($record['context']);
        }
        return implode("\n", $lines);
    }
}
