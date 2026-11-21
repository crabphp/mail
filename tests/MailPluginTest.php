<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Config\InvalidSettings;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Mailer as MailerContract;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\Transport;
use Crab\HttpClient\FakeClient;
use Crab\Mail\Mailer;
use Crab\Mail\MailPlugin;
use Crab\Mail\Transports\ArrayTransport;
use Crab\Mail\Transports\CloudflareTransport;
use Crab\Mail\Transports\LogTransport;
use Crab\Mail\Transports\SmtpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MailPluginTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'transport' => [['transport' => 'sendmail'], 'mail.transport'];
        yield 'from' => [['from' => 'app@example.org'], 'mail.from'];
        yield 'from.address' => [['from' => ['address' => '']], 'mail.from.address'];
        yield 'from.name' => [['from' => ['name' => 7]], 'mail.from.name'];
        yield 'from unknown' => [['from' => ['email' => 'app@example.org']], 'mail.from.email'];
        yield 'smtp' => [['smtp' => 'smtp.example.com'], 'mail.smtp'];
        yield 'smtp.host' => [['smtp' => ['host' => '']], 'mail.smtp.host'];
        yield 'smtp.port' => [['smtp' => ['port' => 70_000]], 'mail.smtp.port'];
        yield 'smtp.encryption' => [['smtp' => ['encryption' => 'ssl']], 'mail.smtp.encryption'];
        yield 'smtp.username' => [['smtp' => ['username' => '']], 'mail.smtp.username'];
        yield 'smtp.password' => [['smtp' => ['password' => 12]], 'mail.smtp.password'];
        yield 'smtp.timeout' => [['smtp' => ['timeout' => 0]], 'mail.smtp.timeout'];
        yield 'smtp.local_domain' => [['smtp' => ['local_domain' => '']], 'mail.smtp.local_domain'];
        yield 'smtp.verify' => [['smtp' => ['verify' => 'maybe']], 'mail.smtp.verify'];
        yield 'smtp.return_path' => [['smtp' => ['return_path' => []]], 'mail.smtp.return_path'];
        yield 'smtp unknown' => [['smtp' => ['hostname' => 'x']], 'mail.smtp.hostname'];
        yield 'cloudflare' => [['cloudflare' => true], 'mail.cloudflare'];
        yield 'cloudflare.account_id' => [['cloudflare' => ['account_id' => 5]], 'mail.cloudflare.account_id'];
        yield 'cloudflare.api_token' => [['cloudflare' => ['api_token' => '']], 'mail.cloudflare.api_token'];
        yield 'unknown' => [['driver' => 'smtp'], 'mail.driver'];
        yield 'smtp without host' => [['transport' => 'smtp'], 'mail.smtp.host is missing'];
        yield 'plain text to a remote host' => [
            ['transport' => 'smtp', 'smtp' => ['host' => 'smtp.example.com', 'encryption' => 'none']],
            'mail.smtp.encryption is "none"',
        ];
        yield 'username without password' => [
            ['transport' => 'smtp', 'smtp' => ['host' => 'smtp.example.com', 'username' => 'api_token']],
            'mail.smtp.password is missing',
        ];
        yield 'invalid return path' => [
            ['transport' => 'smtp', 'smtp' => ['host' => 'smtp.example.com', 'return_path' => 'nobody']],
            'mail.smtp.return_path is "nobody"',
        ];
        yield 'cloudflare without account' => [['transport' => 'cloudflare'], 'mail.cloudflare.account_id is null'];
        yield 'invalid from address' => [
            ['from' => ['address' => 'not an address']],
            'mail.from.address is "not an address"',
        ];
    }

    /**
     * @param array<string, mixed> $mail
     */
    #[DataProvider('invalidSettings')]
    public function testTheMailerChecksEachSetting(array $mail, string $expected): void
    {
        $app = self::app($mail);
        $failure = null;

        try {
            $app->container->get(MailerContract::class);
        } catch (InvalidSettings $error) {
            $failure = $error;
        }

        self::assertNotNull($failure);
        self::assertStringContainsString($expected, $failure->getMessage());
    }

    public function testASecretIsNeverRepeated(): void
    {
        $app = self::app([
            'transport' => 'cloudflare',
            'cloudflare' => ['api_token' => "bad token\nwith-secret-part"],
            'smtp' => ['password' => ['secret-in-a-list']],
        ]);
        $failure = null;

        try {
            $app->container->get(MailerContract::class);
        } catch (InvalidSettings $error) {
            $failure = $error;
        }

        self::assertNotNull($failure);
        self::assertStringNotContainsString('secret', $failure->getMessage());
        $app = self::app([
            'transport' => 'cloudflare',
            'cloudflare' => ['account_id' => 'abc', 'api_token' => "bad token\nwith-secret-part"],
        ]);
        $failure = null;
        try {
            $app->container->get(MailerContract::class);
        } catch (InvalidSettings $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('mail.cloudflare.api_token is not a token', $failure->getMessage());
        self::assertStringNotContainsString('secret', $failure->getMessage());
    }

    public function testTheMailerAndItsTransportAreBoundOnce(): void
    {
        $app = self::app([]);

        $mailer = $app->container->get(MailerContract::class);

        self::assertInstanceOf(Mailer::class, $mailer);
        self::assertSame($mailer, $app->container->get(Mailer::class));
        self::assertInstanceOf(LogTransport::class, $app->container->get(Transport::class));
        self::assertTrue($app->console->has('mail:test'));
    }

    public function testEachTransportIsMadeFromItsSettings(): void
    {
        $smtp = self::app([
            'transport' => 'smtp',
            'smtp' => ['host' => 'smtp.mx.cloudflare.net', 'username' => 'api_token', 'password' => 'token'],
        ]);
        self::assertInstanceOf(SmtpTransport::class, $smtp->container->get(Transport::class));
        $cloudflare = self::app([
            'transport' => 'cloudflare',
            'cloudflare' => ['account_id' => '0123456789abcdef0123456789abcdef', 'api_token' => 'token'],
        ]);
        $cloudflare->container->instance(Client::class, new FakeClient());
        self::assertInstanceOf(CloudflareTransport::class, $cloudflare->container->get(Transport::class));
    }

    public function testTheArrayTransportIsReachableAndKeepsWhatWasSent(): void
    {
        $app = self::app(['transport' => 'array', 'from' => ['address' => 'app@example.org', 'name' => 'App']]);
        $array = $app->container->get(ArrayTransport::class);

        $sent = $app->container->get(MailerContract::class)->send(new Message(
            to: [new Address('ada@example.com')],
            subject: 'Confirm your email',
            text: 'Open https://app.example.org/verify?token=abc',
        ));

        self::assertSame($array, $app->container->get(Transport::class));
        self::assertCount(1, $array->messages());
        self::assertSame('app@example.org', $array->last()['message']->from?->email);
        self::assertStringContainsString("From: App <app@example.org>\r\n", $array->last()['mime']);
        self::assertSame($sent->messageId, \Crab\Mail\Mime::messageId($array->last()['mime']));
        $array->clear();
        self::assertNull($array->last());
    }

    public function testTheArrayTransportIsRefusedWhenAnotherIsConfigured(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mail.transport is not array');

        self::app([])->container->get(ArrayTransport::class);
    }

    public function testTheMailerLogsNoAddressSubjectOrBody(): void
    {
        $app = self::app(['transport' => 'array', 'from' => ['address' => 'app@example.org']]);
        $logger = new MemoryLogger();
        $app->container->instance(LoggerInterface::class, $logger);

        $app->container->get(MailerContract::class)->send(new Message(
            to: [new Address('ada@example.com')],
            bcc: [new Address('hidden@example.com')],
            subject: 'Secret subject',
            text: 'Secret body',
        ));

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame(2, $logger->records[0]['context']['recipients']);
        self::assertSame('array', $logger->records[0]['context']['transport']);
        foreach (['ada@', 'hidden@', 'app@', 'Secret'] as $private) {
            self::assertStringNotContainsString($private, $logger->text());
        }
    }

    public function testAMessageWithoutFromIsRefusedWhenNoneIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mail.from.address is not set');

        self::app(['transport' => 'array'])
            ->container
            ->get(MailerContract::class)
            ->send(new Message(to: [new Address('ada@example.com')], subject: 'Hi', text: 'Hi'));
    }

    public function testQueueingWithoutAQueueSaysWhatToDo(): void
    {
        $app = self::app(['transport' => 'array', 'from' => ['address' => 'app@example.org']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('add QueuePlugin before MailPlugin');

        $app->container->get(MailerContract::class)->queue(new Message(
            to: [new Address('ada@example.com')],
            subject: 'Hi',
            text: 'Hi',
        ));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidTestOptions(): iterable
    {
        yield 'missing' => [[], '--to is missing; use mail:test --to=you@example.com'];
        yield 'bare' => [['--to'], '--to needs a value; use --to=you@example.com'];
        yield 'separated' => [['--to', 'ada@example.com'], '--to needs a value; use --to=you@example.com'];
        yield 'empty' => [['--to='], '--to takes one email address; use mail:test --to=you@example.com'];
        yield 'invalid' => [
            ['--to=not-an-address'],
            '--to takes one email address; use mail:test --to=you@example.com',
        ];
        yield 'injected' => [["--to=ada@example.com\r\nBcc: x@example.com"], '--to takes one email address'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidTestOptions')]
    public function testMailTestRefusesABadToBeforeSendingAnything(array $arguments, string $expected): void
    {
        // A transport whose settings are wrong: the refusal must come first, without making it.
        $app = self::app(['transport' => 'smtp']);

        [$status, $output] = self::console($app, ['mail:test', ...$arguments]);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsString('mail.smtp.host', $output);
    }

    public function testMailTestSendsAndPrintsTheMessageId(): void
    {
        $app = self::app(['transport' => 'array', 'from' => ['address' => 'app@example.org']]);

        [$status, $output] = self::console($app, ['mail:test', '--to=ada@example.com']);

        self::assertSame(0, $status, $output);
        $sent = $app->container->get(ArrayTransport::class)->last();
        self::assertNotNull($sent);
        self::assertSame('ada@example.com', $sent['message']->to[0]->email);
        self::assertStringContainsString(
            'Sent through array to ada@example.com, message id ' . \Crab\Mail\Mime::messageId($sent['mime']),
            $output,
        );
    }

    public function testMailTestPrintsAFailure(): void
    {
        $app = self::app([
            'transport' => 'smtp',
            'from' => ['address' => 'app@example.org'],
            'smtp' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'encryption' => 'none',
                'timeout' => 2,
            ],
        ]);

        [$status, $output] = self::console($app, ['mail:test', '--to=ada@example.com']);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('Not sent (temporary)', $output);
    }

    /**
     * @param array<string, mixed> $mail
     */
    private static function app(array $mail): App
    {
        $app = new App(new Config(['mail' => $mail]));
        $app->register(MailPlugin::class);
        $app->container->instance(LoggerInterface::class, new MemoryLogger());
        return $app;
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private static function console(App $app, array $arguments): array
    {
        $stream = fopen('php://memory', 'w+');
        try {
            $status = $app->console->run($arguments, new Output($stream, $stream));
            rewind($stream);
            return [$status, (string) stream_get_contents($stream)];
        } finally {
            fclose($stream);
        }
    }
}
