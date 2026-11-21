<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

/**
 * A message the transport could not hand on. A permanent failure will
 * fail again when tried again (a refused address, refused credentials, a
 * sender the provider does not allow); a temporary one may succeed later
 * (a network failure, a busy server, a rate limit).
 *
 * The reason is the provider's own text, with control characters made
 * spaces and cut to 1,000 bytes. It never holds a password, a token or
 * the message's body.
 */
final class NotSent extends \RuntimeException
{
    /**
     * What went wrong, cleaned as clean() does.
     */
    public readonly string $reason;

    /**
     * @param string $reason What went wrong, in the provider's words where it gave some.
     * @param bool $permanent Whether trying again cannot help.
     * @param int|null $errorCode The SMTP reply code or the provider's error code, when there is one; also getCode(), as 0 when there is none.
     */
    public function __construct(
        string $reason,
        public readonly bool $permanent,
        public readonly ?int $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        $this->reason = self::clean($reason);
        parent::__construct($this->reason, $errorCode ?? 0, $previous);
    }

    /**
     * Makes provider text safe to log: control characters become spaces,
     * and it is cut to 1,000 bytes without splitting a UTF-8 character.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text);
        return strlen($text) > 1000 ? mb_strcut($text, 0, 1000, 'UTF-8') : $text;
    }
}
