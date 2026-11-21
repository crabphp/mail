<?php

declare(strict_types=1);

namespace Crab\Mail;

use Crab\Contracts\Mail\Mailer;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use Crab\Engine\Async\Cancellation;
use Psr\Log\LoggerInterface;

/**
 * The job Mailer::queue() pushes: sends one message given as
 * Message::toArray() data. A temporary failure is thrown, so the queue
 * tries the job again under queue.tries and queue.backoff. A permanent
 * one, or a message that can no longer be written, is logged as an
 * error and the job ends: trying again would only fail again.
 *
 * Like every job it runs at least once, so a message can be sent twice
 * when a worker dies between sending it and reporting the job done.
 */
final readonly class SendMail
{
    public function __construct(
        private Mailer $mailer,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $message
     */
    public function handle(array $message, ?Cancellation $cancellation = null): void
    {
        try {
            $this->mailer->send(Message::fromArray($message), $cancellation);
        } catch (NotSent $error) {
            if (!$error->permanent) {
                throw $error;
            }
            $this->logger?->error('Queued mail was refused for good and will not be tried again: {reason}', [
                'reason' => $error->reason,
                'code' => $error->errorCode,
            ]);
        } catch (\InvalidArgumentException $error) {
            $this->logger?->error('Queued mail cannot be sent and will not be tried again: {reason}', [
                'reason' => $error->getMessage(),
            ]);
        }
    }
}
