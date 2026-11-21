<?php

declare(strict_types=1);

namespace Crab\Mail\Smtp;

/**
 * One SMTP reply: its code and the text of each of its lines.
 *
 * @internal
 */
final readonly class Reply
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public int $code,
        public array $lines,
    ) {}

    public function text(): string
    {
        return $this->code . ' ' . implode(' ', $this->lines);
    }

    public function accepted(): bool
    {
        return $this->code >= 200 && $this->code < 300;
    }

    /**
     * Whether an EHLO reply lists the extension, such as STARTTLS, and
     * what follows its name.
     *
     * @return list<string>|null The extension's parameters, upper-cased, or null when it is not listed.
     */
    public function extension(string $name): ?array
    {
        foreach (array_slice($this->lines, 1) as $line) {
            $words = preg_split('/[ =]+/', strtoupper(trim($line))) ?: [];
            if (($words[0] ?? '') === $name) {
                return array_slice($words, 1);
            }
        }
        return null;
    }
}
