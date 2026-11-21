<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Attachment;
use Crab\Contracts\Mail\Message;
use Crab\Mail\Mime;
use Crab\Mail\Transports\LogTransport;
use PHPUnit\Framework\TestCase;

final class LogTransportTest extends TestCase
{
    public function testTheLogShowsTheEnvelopeAndTheBodiesAsWritten(): void
    {
        $logger = new MemoryLogger();
        $url = 'https://app.example.org/verify?token=abc123&expires=1763700000&signature=' . str_repeat('f', 64);
        $message = new Message(
            to: [new Address('ada@example.com', 'Ada')],
            subject: 'Confirm your email',
            text: "Open this link to confirm: {$url}",
            html: "<p><a href=\"{$url}\">Confirm</a></p>",
            from: new Address('app@example.org'),
            bcc: [new Address('audit@example.org')],
            attachments: [new Attachment('terms.pdf', str_repeat('x', 1234), 'application/pdf')],
        );
        $mime = new Mime()->render($message);

        $sent = new LogTransport($logger)->send($message, $mime);

        self::assertSame('log', $sent->transport);
        self::assertSame(Mime::messageId($mime), $sent->messageId);
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        $text = $logger->records[0]['message'];
        self::assertStringContainsString('From: app@example.org', $text);
        self::assertStringContainsString('To: Ada <ada@example.com>', $text);
        self::assertStringContainsString('Bcc (not shown to recipients): audit@example.org', $text);
        self::assertStringContainsString('Subject: Confirm your email', $text);
        self::assertStringContainsString("Open this link to confirm: {$url}\n", $text);
        self::assertStringContainsString("<a href=\"{$url}\">", $text);
        self::assertStringContainsString('Attachment: terms.pdf (application/pdf, 1234 bytes)', $text);
    }
}
