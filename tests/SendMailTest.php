<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Attachment;
use Crab\Contracts\Mail\Mailer;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use Crab\Contracts\Mail\Sent;
use Crab\Contracts\Mail\Transport;
use Crab\Contracts\Queue\Store;
use Crab\Engine\Async\Cancellation;
use Crab\Mail\MailPlugin;
use Crab\Mail\SendMail;
use Crab\Mail\Transports\ArrayTransport;
use Crab\Queue\Consumer;
use Crab\Queue\QueuePlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FailingTransport implements Transport
{
    public int $tries = 0;

    public function __construct(
        private readonly bool $permanent,
    ) {}

    public function send(Message $message, string $mime, ?Cancellation $cancellation = null): Sent
    {
        $this->tries++;
        throw new NotSent(
            $this->permanent ? '550 5.7.1 Sender denied' : '451 4.3.0 Try later',
            $this->permanent,
            $this->permanent ? 550 : 451,
        );
    }
}

final class SendMailTest extends TestCase
{
    public function testAQueuedMessageRoundTripsAndIsSentThroughTheArrayTransport(): void
    {
        $app = self::app();
        $message = new Message(
            to: [new Address('ada@example.com', 'Ada')],
            subject: 'Your invoice',
            text: 'Attached',
            bcc: [new Address('books@example.com')],
            attachments: [new Attachment('invoice.pdf', "%PDF\0\xff", 'application/pdf')],
        );

        $id = $app->container->get(Mailer::class)->queue($message);
        $array = $app->container->get(ArrayTransport::class);
        self::assertSame([], $array->messages());
        $ran = $app->container->get(Consumer::class)->runNext();

        self::assertNotSame('', $id);
        self::assertTrue($ran);
        self::assertCount(1, $array->messages());
        self::assertEquals($message->withFrom(new Address('app@example.org', 'App')), $array->last()['message']);
        self::assertNull($app->container->get(Store::class)->reserve(['default'], time() + 100_000, 90));
    }

    public function testAPermanentFailureIsLoggedAndNotTriedAgain(): void
    {
        $app = self::app();
        $transport = new FailingTransport(permanent: true);
        $app->container->instance(Transport::class, $transport);
        $logger = new MemoryLogger();
        $app->container->instance(LoggerInterface::class, $logger);

        $app->container->get(Mailer::class)->queue(self::message());
        $app->container->get(Consumer::class)->runNext();

        self::assertSame(1, $transport->tries);
        self::assertNull($app->container->get(Store::class)->reserve(['default'], time() + 100_000, 90));
        self::assertSame([], $app->container->get(Store::class)->failed());
        self::assertStringContainsString(
            'refused for good and will not be tried again: 550 5.7.1 Sender denied',
            $logger->text(),
        );
        self::assertStringNotContainsString('ada@example.com', $logger->text());
    }

    public function testATemporaryFailureIsThrownSoTheQueueTriesAgain(): void
    {
        $app = self::app();
        $transport = new FailingTransport(permanent: false);
        $app->container->instance(Transport::class, $transport);
        $app->container->instance(LoggerInterface::class, new MemoryLogger());

        $app->container->get(Mailer::class)->queue(self::message());
        $app->container->get(Consumer::class)->runNext();

        self::assertSame(1, $transport->tries);
        $again = $app->container->get(Store::class)->reserve(['default'], time() + 100_000, 90);
        self::assertNotNull($again);
        self::assertSame(SendMail::class, $again->job);
        self::assertSame(2, $again->attempts);
    }

    public function testAMessageWithoutFromIsRefusedWhereItIsQueued(): void
    {
        $app = new App(new Config(['queue' => ['store' => 'memory'], 'mail' => ['transport' => 'array']]));
        $app->register(QueuePlugin::class, MailPlugin::class);

        $this->expectException(\InvalidArgumentException::class);

        $app->container->get(Mailer::class)->queue(self::message());
    }

    private static function message(): Message
    {
        return new Message(to: [new Address('ada@example.com')], subject: 'Hi', text: 'Hi');
    }

    private static function app(): App
    {
        $app = new App(new Config([
            'queue' => ['store' => 'memory'],
            'mail' => ['transport' => 'array', 'from' => ['address' => 'app@example.org', 'name' => 'App']],
        ]));
        $app->register(QueuePlugin::class, MailPlugin::class);
        $app->container->instance(LoggerInterface::class, new MemoryLogger());
        return $app;
    }
}
