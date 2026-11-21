<?php

declare(strict_types=1);

namespace Crab\Mail\Transports;

use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\Sent;
use Crab\Contracts\Mail\Transport;
use Crab\Engine\Async\Cancellation;
use Crab\Mail\Mime;

/**
 * Keeps every message in memory instead of sending it, for tests. With
 * mail.transport set to array, a test asks the container for this class
 * and looks at what was sent:
 *
 *     $sent = $app->container->get(ArrayTransport::class)->last();
 *     self::assertSame('ada@example.com', $sent['message']->to[0]->email);
 *     self::assertStringContainsString('Subject: Confirm your email', $sent['mime']);
 *
 * Each worker has its own, and it keeps everything until clear().
 */
final class ArrayTransport implements Transport
{
    /**
     * @var list<array{message: Message, mime: string}>
     */
    private array $messages = [];

    public function send(Message $message, string $mime, ?Cancellation $cancellation = null): Sent
    {
        $cancellation?->throwIfCancelled();
        $this->messages[] = ['message' => $message, 'mime' => $mime];
        return new Sent(Mime::messageId($mime), 'array');
    }

    /**
     * Every message sent, oldest first, as it was given and as it was written.
     *
     * @return list<array{message: Message, mime: string}>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The message sent last, or null when none was.
     *
     * @return array{message: Message, mime: string}|null
     */
    public function last(): ?array
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }

    /**
     * Forgets every message kept so far.
     */
    public function clear(): void
    {
        $this->messages = [];
    }
}
