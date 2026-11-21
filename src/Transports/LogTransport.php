<?php

declare(strict_types=1);

namespace Crab\Mail\Transports;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\Sent;
use Crab\Contracts\Mail\Transport;
use Crab\Engine\Async\Cancellation;
use Crab\Mail\Mime;
use Psr\Log\LoggerInterface;

/**
 * Writes each message to the application's log instead of sending it,
 * for development: the envelope, the subject, the text and HTML bodies
 * as they were written, and each attachment's name and size, so that a
 * link in a message can be copied out of the log.
 *
 * Everything a message says is in the log, so never use it where the
 * log is kept or shared.
 */
final readonly class LogTransport implements Transport
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function send(Message $message, string $mime, ?Cancellation $cancellation = null): Sent
    {
        $cancellation?->throwIfCancelled();
        $id = Mime::messageId($mime);
        $list = static fn(array $addresses): string => implode(', ', array_map(
            static fn(Address $address): string => (string) $address,
            $addresses,
        ));
        $lines = ['Mail ' . ($id ?? '(no id)') . ' was written to the log instead of being sent:'];
        $lines[] = 'From: ' . ($message->from === null ? '(none)' : (string) $message->from);
        foreach ([
            'To' => $message->to,
            'Cc' => $message->cc,
            'Bcc (not shown to recipients)' => $message->bcc,
        ] as $name => $addresses) {
            if ($addresses === []) {
                continue;
            }

            $lines[] = $name . ': ' . $list($addresses);
        }
        if ($message->replyTo !== []) {
            $lines[] = 'Reply-To: ' . $list($message->replyTo);
        }
        $lines[] = 'Subject: ' . $message->subject;
        if ($message->text !== null) {
            $lines[] = '--- text ---';
            $lines[] = $message->text;
        }
        if ($message->html !== null) {
            $lines[] = '--- html ---';
            $lines[] = $message->html;
        }
        foreach ($message->attachments as $attachment) {
            $lines[] =
                'Attachment: '
                . $attachment->filename
                . ' ('
                . $attachment->type
                . ', '
                . strlen($attachment->content)
                . ' bytes'
                . ($attachment->inline ? ', inline' : '')
                . ')';
        }
        $lines[] = '--- end of mail ---';
        $this->logger->info(implode("\n", $lines));
        return new Sent($id, 'log');
    }
}
