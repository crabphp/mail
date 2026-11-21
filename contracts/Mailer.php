<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

use Crab\Engine\Async\Cancellation;

/**
 * Sends email through the transport the application configured
 * (mail.transport). An action or a job takes it in its constructor:
 *
 *     public function __construct(private Mailer $mailer) {}
 *
 *     $this->mailer->send(new Message(to: [new Address('ada@example.com')], subject: 'Hello', text: 'Hi Ada'));
 *
 * A message without a from is sent from mail.from.
 */
interface Mailer
{
    /**
     * Writes the message and sends it now, waiting for the transport's
     * answer. Inside a worker only the fiber that called waits.
     *
     * @param Cancellation|null $cancellation Gives the sending up when cancelled, as a job's or a request's.
     * @throws NotSent When the transport could not send it; its permanent says whether trying again can help.
     * @throws \InvalidArgumentException When the message cannot be written or sent as it is: no from, too large, too many recipients.
     */
    public function send(Message $message, ?Cancellation $cancellation = null): Sent;

    /**
     * Pushes a job that sends the message later, through the application's
     * queue. The message travels as JSON. A queued message is sent at
     * least once: when the worker dies after sending, it is sent again.
     *
     * @return int|string The id of the job.
     * @throws \LogicException When the application has no queue.
     */
    public function queue(Message $message): int|string;
}
