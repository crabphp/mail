<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

/**
 * An email address, with the name shown beside it when there is one:
 *
 *     new Address('ada@example.com', 'Ada Lovelace')
 *     Address::parse('Ada Lovelace <ada@example.com>')
 *
 * The address is checked when it is made: at most 254 bytes, no line
 * break, and a form PHP's FILTER_VALIDATE_EMAIL accepts. The name may be
 * any UTF-8 text without a line break; it is encoded when it is written.
 */
final readonly class Address
{
    /**
     * @throws \InvalidArgumentException When the address or the name cannot be written in a message.
     */
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {
        if (
            strlen($email) > 254
            || preg_match('/[\x00-\x20\x7f]/', $email) === 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new \InvalidArgumentException(
                'An email address must be one address such as ada@example.com, of at most 254 bytes, with no spaces or line breaks.',
            );
        }
        if (
            $name !== null
            && (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $name) === 1 || !mb_check_encoding($name, 'UTF-8'))
        ) {
            throw new \InvalidArgumentException(
                'The name beside an email address must be UTF-8 text with no line breaks or control characters.',
            );
        }
        if ($name !== null && strlen($name) > 255) {
            throw new \InvalidArgumentException('The name beside an email address must be at most 255 bytes.');
        }
    }

    /**
     * Reads "ada@example.com", "<ada@example.com>" or
     * "Ada Lovelace <ada@example.com>", with the name optionally in
     * double quotes.
     *
     * @throws \InvalidArgumentException When the text is not one address in one of those forms.
     */
    public static function parse(string $text): self
    {
        $text = trim($text);
        if (preg_match('/^(.*?)\s*<([^<>]*)>$/sD', $text, $parts) === 1) {
            $name = trim($parts[1]);
            if (strlen($name) >= 2 && str_starts_with($name, '"') && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }
            return new self($parts[2], $name === '' ? null : $name);
        }
        return new self($text);
    }

    /**
     * The address as a person would write it: "Ada Lovelace <ada@example.com>".
     */
    public function __toString(): string
    {
        return $this->name === null || $this->name === '' ? $this->email : "{$this->name} <{$this->email}>";
    }
}
