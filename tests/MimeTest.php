<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Attachment;
use Crab\Contracts\Mail\Message;
use Crab\Mail\Mime;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MimeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Message}>
     */
    public static function messages(): iterable
    {
        $from = new Address('app@example.org', 'Example App');
        $to = [new Address('ada@example.com', 'Ada Lovelace')];
        yield 'text' => [
            'text',
            new Message(
                to: $to,
                subject: 'Confirm your email',
                text: "Hello Ada,\n\nOpen this link to confirm: https://app.example.org/verify?token=abc123&user=7\n\nThanks",
                from: $from,
            ),
        ];
        yield 'html' => [
            'html',
            new Message(
                to: $to,
                subject: 'Confirm your email',
                html: '<p>Hello Ada, <a href="https://app.example.org/verify?token=abc123">confirm</a>.</p>',
                from: $from,
            ),
        ];
        yield 'both' => [
            'both',
            new Message(
                to: $to,
                subject: 'Confirm your email',
                text: 'Hello Ada',
                html: '<p>Hello Ada</p>',
                from: $from,
                cc: [new Address('grace@example.com')],
                replyTo: [new Address('support@example.org', 'Support')],
                headers: ['X-Campaign' => 'welcome'],
            ),
        ];
        yield 'attachments' => [
            'attachments',
            new Message(
                to: $to,
                subject: 'Your invoice',
                text: 'Your invoice is attached.',
                from: $from,
                attachments: [new Attachment('invoice.pdf', str_repeat('%PDF-1.7 ', 30), 'application/pdf')],
            ),
        ];
        yield 'inline' => [
            'inline',
            new Message(
                to: $to,
                subject: 'Welcome',
                text: 'Welcome',
                html: '<p><img src="cid:logo" alt="Logo"> Welcome</p>',
                from: $from,
                attachments: [
                    new Attachment(
                        'logo.png',
                        "\x89PNG\r\n\x1a\nfake image bytes",
                        'image/png',
                        inline: true,
                        contentId: 'logo',
                    ),
                    new Attachment('terms.txt', "Terms\n", 'text/plain'),
                ],
            ),
        ];
    }

    #[DataProvider('messages')]
    public function testItWritesTheGoldenOutput(string $name, Message $message): void
    {
        $mime = self::mime()->render($message);
        $golden = __DIR__ . '/golden/' . $name . '.eml';
        if (getenv('MAIL_GOLDEN_WRITE') === '1') {
            file_put_contents($golden, $mime);
        }

        self::assertSame(file_get_contents($golden), $mime);
        self::assertSame(0, preg_match('/(?<!\r)\n|\r(?!\n)/', $mime), 'Every line ends in CRLF.');
        self::assertSame(0, preg_match('/[^\x09\x0a\x0d\x20-\x7e]/', $mime), 'Every byte is 7-bit text.');
        foreach (explode("\r\n", $mime) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), $line);
        }
    }

    public function testBccNeverAppears(): void
    {
        $message = new Message(
            to: [new Address('ada@example.com')],
            subject: 'Hi',
            text: 'Hi',
            from: new Address('app@example.org'),
            bcc: [new Address('hidden@example.com', 'Hidden Person')],
        );

        $mime = self::mime()->render($message);

        self::assertStringNotContainsStringIgnoringCase('bcc', $mime);
        self::assertStringNotContainsString('hidden', $mime);
        self::assertStringNotContainsString('Hidden', $mime);
    }

    public function testAMessageOnlyToBccHasNoToHeader(): void
    {
        $message = new Message(
            to: [],
            subject: 'Hi',
            text: 'Hi',
            from: new Address('app@example.org'),
            bcc: [new Address('hidden@example.com')],
        );

        $mime = self::mime()->render($message);

        self::assertStringNotContainsString("\r\nTo:", $mime);
        self::assertStringNotContainsString('hidden', $mime);
    }

    public function testNamesAndSubjectsThatAreNotAsciiAreEncodedWords(): void
    {
        $message = new Message(
            to: [new Address('zoe@example.com', 'Zoë Ünïcode')],
            subject: 'Xác nhận email của bạn — một tiêu đề khá dài để được gấp lại thành nhiều dòng',
            text: 'Chào bạn',
            from: new Address('app@example.org', 'Ứng dụng'),
        );

        $mime = self::mime()->render($message);
        $head = substr($mime, 0, (int) strpos($mime, "\r\n\r\n"));

        self::assertStringContainsString(
            'To: =?UTF-8?B?' . base64_encode('Zoë Ünïcode') . '?= <zoe@example.com>',
            $head,
        );
        self::assertStringContainsString(
            'From: =?UTF-8?B?' . base64_encode('Ứng dụng') . '?= <app@example.org>',
            $head,
        );
        self::assertSame(
            'Xác nhận email của bạn — một tiêu đề khá dài để được gấp lại thành nhiều dòng',
            mb_decode_mimeheader(self::header($head, 'Subject')),
        );
        foreach (explode("\r\n", $head) as $line) {
            self::assertLessThanOrEqual(78, strlen($line));
            self::assertSame(0, preg_match('/[^\x20-\x7e]/', $line));
        }
        preg_match_all('/=\?UTF-8\?B\?[^?]*\?=/', $head, $words);
        foreach ($words[0] as $word) {
            self::assertLessThanOrEqual(75, strlen($word));
            self::assertTrue(mb_check_encoding((string) base64_decode(substr($word, 10, -2), true), 'UTF-8'));
        }
    }

    public function testNoLineIsLongerThan998Bytes(): void
    {
        $message = new Message(
            to: [new Address('ada@example.com')],
            subject: str_repeat('word ', 150),
            text: str_repeat('x', 5000) . "\n" . str_repeat('é', 3000),
            html: '<p>' . str_repeat('y', 5000) . '</p>',
            from: new Address('app@example.org'),
            attachments: [new Attachment('big.bin', random_bytes(10_000))],
            headers: ['X-Long' => str_repeat('token ', 200)],
        );

        $mime = self::mime()->render($message);

        foreach (explode("\r\n", $mime) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
        self::assertSame(
            str_repeat('x', 5000) . "\r\n" . str_repeat('é', 3000) . "\r\n",
            quoted_printable_decode(self::part($mime, 'text/plain')),
        );
    }

    public function testAttachmentsRoundTripThroughBase64InLinesOf76(): void
    {
        $bytes = random_bytes(1000);
        $message = new Message(
            to: [new Address('ada@example.com')],
            subject: 'File',
            text: 'Attached',
            from: new Address('app@example.org'),
            attachments: [new Attachment('ünï cødé.bin', $bytes)],
        );

        $mime = self::mime()->render($message);
        $body = self::part($mime, 'application/octet-stream');

        foreach (explode("\r\n", trim($body)) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }
        self::assertSame($bytes, base64_decode($body, true));
        self::assertStringContainsString("filename*0*=UTF-8''%C3%BCn%C3%AF%20c%C3%B8d", $mime);
    }

    public function testAMessageLargerThan25MiBIsRefused(): void
    {
        $message = new Message(
            to: [new Address('ada@example.com')],
            subject: 'Huge',
            text: 'Huge',
            from: new Address('app@example.org'),
            attachments: [new Attachment('huge.bin', str_repeat("\0", (19 * 1024 * 1024) + 1))],
        );
        $failure = null;

        try {
            self::mime()->render($message);
        } catch (\InvalidArgumentException $error) {
            $failure = $error;
        }

        self::assertNotNull($failure);
        self::assertStringContainsString('more than the 25 MiB', $failure->getMessage());
    }

    public function testAMessageWithoutFromCannotBeWritten(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mail.from.address');

        self::mime()->render(new Message(to: [new Address('ada@example.com')], subject: 'Hi', text: 'Hi'));
    }

    public function testTheMessageIdIsAtTheSendersDomainAndReadBack(): void
    {
        $mime = new Mime()->render(new Message(
            to: [new Address('ada@example.com')],
            subject: 'Hi',
            text: 'Hi',
            from: new Address('app@mail.example.org'),
        ));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}@mail\.example\.org$/D', (string) Mime::messageId($mime));
    }

    private static function mime(): Mime
    {
        $counter = 0;
        $random = static function (int $length) use (&$counter): string {
            $counter++;
            return str_repeat(chr($counter), $length);
        };
        return new Mime(new FrozenClock('2026-11-21T10:30:00+00:00'), $random);
    }

    private static function header(string $head, string $name): string
    {
        preg_match('/^' . $name . ': ([^\r\n]*(?:\r\n [^\r\n]*)*)/m', $head, $match);
        return str_replace("\r\n ", ' ', $match[1]);
    }

    /**
     * The body of the first part of the media type.
     */
    private static function part(string $mime, string $type): string
    {
        $start = strpos($mime, 'Content-Type: ' . $type);
        self::assertNotFalse($start);
        $body = strpos($mime, "\r\n\r\n", $start) + 4;
        $end = strpos($mime, "\r\n--=_crab_", $body);
        return substr($mime, $body, ($end === false ? strlen($mime) : $end) - $body);
    }
}
