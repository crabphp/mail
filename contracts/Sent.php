<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

/**
 * What a transport says about a message it has handed on.
 */
final readonly class Sent
{
    /**
     * @param string|null $messageId The id the provider gave the message, or the Message-ID it was written with; null when neither is known.
     * @param string $transport The name of the transport that sent it, such as smtp.
     */
    public function __construct(
        public ?string $messageId,
        public string $transport,
    ) {}
}
