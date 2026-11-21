<?php

declare(strict_types=1);

namespace Crab\Mail;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Mailer as MailerContract;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\Sent;
use Crab\Contracts\Mail\Transport;
use Crab\Contracts\Queue\Queue;
use Crab\Engine\Async\Cancellation;
use Psr\Log\LoggerInterface;

/**
 * Fills in the default from, writes the message and hands it to the
 * transport. It logs one line per message sent, with the transport, the
 * id and how many recipients, never an address, the subject or the body.
 */
final readonly class Mailer implements MailerContract
{
    /**
     * @param Address|null $from Who a message without a from is sent from; such a message is refused when null.
     * @param \Closure(): ?Queue|null $queue Gives the queue a message is queued on, or null when there is none.
     */
    public function __construct(
        private Transport $transport,
        private Mime $mime = new Mime(),
        private ?Address $from = null,
        private ?LoggerInterface $logger = null,
        private ?\Closure $queue = null,
    ) {}

    /**
     * A mailer whose queue is the container's, asked for when a message is
     * queued, so that a mailer can be made without one.
     */
    public static function queueFrom(Container $container): \Closure
    {
        return static fn(): ?Queue => $container->has(Queue::class) ? $container->get(Queue::class) : null;
    }

    public function send(Message $message, ?Cancellation $cancellation = null): Sent
    {
        $message = $this->withFrom($message);
        $mime = $this->mime->render($message);
        $sent = $this->transport->send($message, $mime, $cancellation);
        $this->logger?->info('Mail sent through {transport} as {id} to {recipients} recipients.', [
            'transport' => $sent->transport,
            'id' => $sent->messageId ?? '(no id)',
            'recipients' => $message->recipientCount(),
        ]);
        return $sent;
    }

    public function queue(Message $message): int|string
    {
        $queue = $this->queue === null ? null : ($this->queue)();
        if ($queue === null) {
            throw new \LogicException(
                'Queueing mail needs the queue: add QueuePlugin before MailPlugin in withPlugins() in app.php, or send the message now with send().',
            );
        }
        // Checked now, so that a message without a sender fails where it is queued.
        $message = $this->withFrom($message);
        return $queue->push(SendMail::class, message: $message->toArray());
    }

    private function withFrom(Message $message): Message
    {
        if ($message->from !== null) {
            return $message;
        }
        if ($this->from === null) {
            throw new \InvalidArgumentException(
                'The message has no from address and mail.from.address is not set: give the message a from, or set MAIL_FROM_ADDRESS.',
            );
        }
        return $message->withFrom($this->from);
    }
}
