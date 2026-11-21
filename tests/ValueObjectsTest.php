<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Attachment;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'no at' => ['ada.example.com'];
        yield 'line feed' => ["ada@example.com\nBcc: x@example.com"];
        yield 'carriage return' => ["ada@example.com\r"];
        yield 'space' => ['ada lovelace@example.com'];
        yield 'too long' => [
            str_repeat('a', 64)
                . '@'
                . str_repeat('b', 63)
                . '.'
                . str_repeat('c', 63)
                . '.'
                . str_repeat('d', 63)
                . '.com',
        ];
        yield 'two addresses' => ['ada@example.com, grace@example.com'];
    }

    #[DataProvider('invalidAddresses')]
    public function testAnAddressIsChecked(string $email): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Address($email);
    }

    public function testANameWithALineBreakIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Address('ada@example.com', "Ada\r\nBcc: x@example.com");
    }

    public function testAnAddressIsParsedFromItsWrittenForms(): void
    {
        self::assertEquals(new Address('ada@example.com'), Address::parse('ada@example.com'));
        self::assertEquals(new Address('ada@example.com'), Address::parse('<ada@example.com>'));
        self::assertEquals(
            new Address('ada@example.com', 'Ada Lovelace'),
            Address::parse('Ada Lovelace <ada@example.com>'),
        );
        self::assertEquals(
            new Address('ada@example.com', 'Lovelace, Ada'),
            Address::parse('"Lovelace, Ada" <ada@example.com>'),
        );
        self::assertSame('Ada <ada@example.com>', (string) new Address('ada@example.com', 'Ada'));
    }

    public function testAMessageNeedsARecipientAndABody(): void
    {
        $failures = [];
        foreach ([
            static fn() => new Message(to: [], subject: 'Hi', text: 'Hi'),
            static fn() => new Message(to: [new Address('ada@example.com')], subject: 'Hi'),
            static fn() => new Message(to: ['ada@example.com'], subject: 'Hi', text: 'Hi'),
        ] as $make) {
            try {
                $make();
            } catch (\InvalidArgumentException $error) {
                $failures[] = $error->getMessage();
            }
        }

        self::assertCount(3, $failures);
        self::assertStringContainsString('at least one recipient', $failures[0]);
        self::assertStringContainsString('text body, an HTML body or both', $failures[1]);
        self::assertInstanceOf(Message::class, new Message(to: [], subject: 'Hi', html: '<p>Hi</p>', bcc: [new Address(
            'ada@example.com',
        )]));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function injections(): iterable
    {
        yield 'subject line feed' => ["Hello\nBcc: x@example.com", []];
        yield 'subject carriage return' => ["Hello\rBcc: x@example.com", []];
        yield 'header value line feed' => ['Hi', ['X-Tag' => "a\r\nBcc: x@example.com"]];
        yield 'header name with colon' => ['Hi', ['X-Tag: b' => 'a']];
        yield 'header name with space' => ['Hi', ['X Tag' => 'a']];
        yield 'own header' => ['Hi', ['Bcc' => 'x@example.com']];
        yield 'own header, any case' => ['Hi', ['message-id' => '<x@example.com>']];
        yield 'reply-to' => ['Hi', ['Reply-To' => 'x@example.com']];
        yield 'content header' => ['Hi', ['Content-Type' => 'text/html']];
        yield 'repeated name' => ['Hi', ['X-Tag' => 'a', 'x-tag' => 'b']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('injections')]
    public function testInjectionIsRefused(string $subject, array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Message(to: [new Address('ada@example.com')], subject: $subject, text: 'Hi', headers: $headers);
    }

    public function testAnAttachmentIsChecked(): void
    {
        $failures = 0;
        foreach ([
            static fn() => new Attachment('', 'x'),
            static fn() => new Attachment("a\r\nb.txt", 'x'),
            static fn() => new Attachment('../etc/passwd', 'x'),
            static fn() => new Attachment('a.txt', 'x', "text/plain\r\nX: y"),
            static fn() => new Attachment('a.txt', 'x', 'text/plain; charset=UTF-8'),
            static fn() => new Attachment('a.png', 'x', 'image/png', true, '<logo>'),
        ] as $make) {
            try {
                $make();
            } catch (\InvalidArgumentException) {
                $failures++;
            }
        }

        self::assertSame(6, $failures);
    }

    public function testAMessageRoundTripsThroughJson(): void
    {
        $message = new Message(
            to: [new Address('ada@example.com', 'Ada Lovelace'), new Address('zoe@example.com', 'Zoë')],
            subject: 'Bienvenue — welcome',
            text: 'Hi',
            html: '<p>Hi <img src="cid:logo"></p>',
            from: new Address('app@example.org', 'App'),
            cc: [new Address('cc@example.com')],
            bcc: [new Address('bcc@example.com')],
            replyTo: [new Address('support@example.org')],
            attachments: [
                new Attachment('logo.png', random_bytes(64), 'image/png', true, 'logo'),
                new Attachment('report.pdf', "%PDF\0binary\xff", 'application/pdf'),
            ],
            headers: ['X-Campaign' => 'welcome'],
        );

        $json = json_encode($message->toArray(), JSON_THROW_ON_ERROR);
        $again = Message::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        self::assertEquals($message, $again);
        self::assertSame(base64_encode("%PDF\0binary\xff"), $message->toArray()['attachments'][1]['content']);
    }

    public function testFromArrayRefusesWhatIsNotAMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Message::fromArray([
            'to' => [['email' => 'ada@example.com']],
            'subject' => 'Hi',
            'text' => 'Hi',
            'attachments' => [['filename' => 'a', 'content' => '***', 'type' => 'text/plain', 'inline' => false]],
        ]);
    }

    public function testWithFromLeavesTheRestAsItWas(): void
    {
        $message = new Message(to: [new Address('ada@example.com')], subject: 'Hi', text: 'Hi');

        $from = $message->withFrom(new Address('app@example.org'));

        self::assertNull($message->from);
        self::assertSame('app@example.org', $from->from?->email);
        self::assertSame($message->to, $from->to);
    }

    public function testNotSentCleansTheReason(): void
    {
        $error = new NotSent("550 5.7.1\r\nbad\x00 sender" . str_repeat('é', 600), true, 550);

        self::assertStringStartsWith('550 5.7.1 bad  sender', $error->reason);
        self::assertLessThanOrEqual(1000, strlen($error->reason));
        self::assertTrue(mb_check_encoding($error->reason, 'UTF-8'));
        self::assertSame(550, $error->getCode());
        self::assertSame($error->reason, $error->getMessage());
    }
}
