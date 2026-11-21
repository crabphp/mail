<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

use Crab\Engine\Async\Cancellation;

/**
 * Hands a written message to whatever delivers it: an SMTP server, a
 * provider's API, the log, or memory in a test. A transport sends what
 * it is given; the mailer has already filled in from and written the
 * message.
 */
interface Transport
{
    /**
     * @param Message $message The message, for its envelope (from and every recipient, bcc included) and for a transport that wants its fields.
     * @param string $mime The message written as RFC 5322 text with CRLF line ends, without Bcc.
     * @throws NotSent When it could not be handed on.
     */
    public function send(Message $message, string $mime, ?Cancellation $cancellation = null): Sent;
}
