<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use Crab\Contracts\Mail\Sent;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Mail\Mime;
use Crab\Mail\Transports\SmtpTransport;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SmtpTransportTest extends TestCase
{
    private const string PASSWORD = 'cf-token-s3cr3t-value';

    private StreamSelectEventLoop $loop;
    private string $directory;
    private ?FakeSmtpServer $server = null;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectEventLoop();
        EventLoop::install($this->loop);
        $this->directory = sys_get_temp_dir() . '/crab-mail-smtp-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        $this->server?->close();
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testImplicitTlsWithATrustedCertificateLogsInWithPlainAndSendsFromAFiber(): void
    {
        $certificate = FakeSmtpServer::certificate($this->directory);
        $this->server = new FakeSmtpServer($this->loop, $certificate);
        $this->server->dataReply = '250 2.0.0 Ok <jZTWt0pQO4p2LG7ByfkeSYUvT62k85Q12nCA@example.org>';
        $transport = $this->transport('tls', tls: ['cafile' => $this->directory . '/localhost.crt']);
        [$message, $mime] = $this->message();

        $sent = $this->inFiber(static fn(): Sent => $transport->send($message, $mime));

        self::assertSame('jZTWt0pQO4p2LG7ByfkeSYUvT62k85Q12nCA@example.org', $sent->messageId);
        self::assertSame('smtp', $sent->transport);
        self::assertSame('EHLO app.example.org', $this->server->commands[0]);
        self::assertSame('AUTH PLAIN ' . base64_encode("\0api_token\0" . self::PASSWORD), $this->server->commands[1]);
        self::assertSame('MAIL FROM:<app@example.org> BODY=8BITMIME', $this->server->commands[2]);
        self::assertSame([$mime], $this->server->messages);
    }

    public function testImplicitTlsIsRefusedWhenTheCertificateIsNotTrusted(): void
    {
        $this->server = new FakeSmtpServer($this->loop, FakeSmtpServer::certificate($this->directory));
        $transport = $this->transport('tls');
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertFalse($error->permanent);
        self::assertSame([], $this->server->commands);
        self::assertStringNotContainsString(self::PASSWORD, $error->getMessage());
    }

    public function testImplicitTlsIsRefusedWhenTheCertificateNamesAnotherHost(): void
    {
        $this->server = new FakeSmtpServer($this->loop, FakeSmtpServer::certificate($this->directory, 'other.test'));
        $transport = $this->transport('tls', tls: ['cafile' => $this->directory . '/other.test.crt']);
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertFalse($error->permanent);
        self::assertSame([], $this->server->commands);
    }

    public function testVerifyOffAcceptsASelfSignedSinkAndWarnsOnce(): void
    {
        $this->server = new FakeSmtpServer($this->loop, FakeSmtpServer::certificate($this->directory));
        $logger = new MemoryLogger();
        $transport = new SmtpTransport(
            'localhost',
            $this->server->port,
            'tls',
            timeout: 5,
            verify: false,
            logger: $logger,
        );
        [$message, $mime] = $this->message();

        $transport->send($message, $mime);
        $transport->send($message, $mime);

        self::assertCount(2, $this->server->messages);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('mail.smtp.verify is false', $logger->records[0]['message']);
    }

    public function testStartTlsIsUsedWhenOfferedAndVerified(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->startTls = FakeSmtpServer::certificate($this->directory);
        $transport = $this->transport('starttls', tls: ['cafile' => $this->directory . '/localhost.crt']);
        [$message, $mime] = $this->message();

        $sent = $transport->send($message, $mime);

        self::assertSame(
            ['EHLO app.example.org', 'STARTTLS', 'EHLO app.example.org'],
            array_slice($this->server->commands, 0, 3),
        );
        self::assertStringStartsWith('AUTH PLAIN ', $this->server->commands[3]);
        self::assertSame(Mime::messageId($mime), $sent->messageId);
    }

    public function testStartTlsThatIsNotOfferedFailsWithoutSendingAnythingInPlainText(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $transport = $this->transport('starttls');
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertTrue($error->permanent);
        self::assertStringContainsString('does not offer STARTTLS', $error->reason);
        self::assertSame(['EHLO app.example.org'], $this->server->commands);
    }

    public function testLoginIsUsedWhenItIsTheOnlyMechanismOffered(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->extensions = ['AUTH LOGIN'];
        $transport = $this->transport('none');
        [$message, $mime] = $this->message();

        $transport->send($message, $mime);

        self::assertSame(
            ['AUTH LOGIN', base64_encode('api_token'), base64_encode(self::PASSWORD)],
            array_slice($this->server->commands, 1, 3),
        );
        self::assertSame('MAIL FROM:<app@example.org>', $this->server->commands[4]);
    }

    public function testRefusedCredentialsArePermanentAndNotRepeated(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->authReply = '535 5.7.8 Authentication failed for ' . self::PASSWORD;
        $transport = $this->transport('none');
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertTrue($error->permanent);
        self::assertSame(535, $error->errorCode);
        self::assertStringContainsString('refused the credentials', $error->reason);
        self::assertStringNotContainsString(self::PASSWORD, $error->getMessage());
        self::assertStringNotContainsString(base64_encode(self::PASSWORD), $error->getMessage());
        self::assertStringNotContainsString(self::PASSWORD, (string) $error);
    }

    public function testAuthThatIsNotOfferedFailsClearly(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->extensions = ['8BITMIME'];
        $transport = $this->transport('none');
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertTrue($error->permanent);
        self::assertStringContainsString('does not offer AUTH', $error->reason);
    }

    public function testATemporaryRefusalAtRcptIsTemporary(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->replies['RCPT'] = '451 4.3.0 Try again later';
        $transport = $this->transport('none');
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertFalse($error->permanent);
        self::assertSame(451, $error->errorCode);
        self::assertStringContainsString('451 4.3.0 Try again later', $error->reason);
        self::assertSame([], $this->server->messages);
    }

    public function testARefusalOfTheDataIsPermanent(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->dataReply = '554 5.7.1 Content rejected';
        $transport = $this->transport('none');
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertTrue($error->permanent);
        self::assertSame(554, $error->errorCode);
    }

    public function testALineStartingWithADotIsStuffedAndEveryBccIsARecipientButNotInTheData(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $transport = $this->transport('none');
        $message = new Message(
            to: [new Address('ada@example.com')],
            subject: 'Dots',
            text: ".starts with a dot\n..two dots\n.",
            from: new Address('app@example.org'),
            cc: [new Address('cc@example.com')],
            bcc: [new Address('hidden1@example.com'), new Address('hidden2@example.com')],
        );
        $mime = $this->mime()->render($message);

        $transport->send($message, $mime);

        $recipients = array_values(array_filter(
            $this->server->commands,
            static fn(string $line): bool => str_starts_with($line, 'RCPT TO:'),
        ));
        self::assertSame(
            [
                'RCPT TO:<ada@example.com>',
                'RCPT TO:<cc@example.com>',
                'RCPT TO:<hidden1@example.com>',
                'RCPT TO:<hidden2@example.com>',
            ],
            $recipients,
        );
        self::assertStringContainsString("\r\n..starts with a dot\r\n", $this->server->raw[0]);
        self::assertStringContainsString("\r\n...two dots\r\n", $this->server->raw[0]);
        self::assertSame($mime, $this->server->messages[0]);
        self::assertStringNotContainsString('hidden', $this->server->messages[0]);
    }

    public function testMoreThanFiftyRecipientsAreRefusedBeforeConnecting(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $transport = $this->transport('none');
        $to = [];
        for ($i = 0; $i < 51; $i++) {
            $to[] = new Address("user{$i}@example.com");
        }
        $message = new Message(to: $to, subject: 'Many', text: 'Hi', from: new Address('app@example.org'));
        $failure = null;

        try {
            $transport->send($message, $this->mime()->render($message));
        } catch (\InvalidArgumentException $error) {
            $failure = $error;
        }

        self::assertNotNull($failure);
        self::assertStringContainsString('at most 50 recipients', $failure->getMessage());
        self::assertSame([], $this->server->commands);
    }

    public function testTheDeadlineEndsAServerThatStopsAnswering(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->silent = true;
        $transport = $this->transport('none', timeout: 0.3);
        [$message, $mime] = $this->message();
        $started = hrtime(true);

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertFalse($error->permanent);
        self::assertStringContainsString('took longer than 0.3 seconds', $error->reason);
        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
    }

    public function testCancellationIsHonouredAndIsNotANotSent(): void
    {
        $this->server = new FakeSmtpServer($this->loop);
        $this->server->silent = true;
        $transport = $this->transport('none');
        [$message, $mime] = $this->message();
        $cancellation = new Cancellation();
        $this->loop->delay(0.05, static fn() => $cancellation->cancel());
        $failure = null;

        try {
            $transport->send($message, $mime, $cancellation);
        } catch (\Throwable $error) {
            $failure = $error;
        }

        self::assertInstanceOf(CancelledException::class, $failure);
    }

    public function testAConnectionThatIsRefusedIsTemporary(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $transport = new SmtpTransport('127.0.0.1', $port, 'none', timeout: 2);
        [$message, $mime] = $this->message();

        $error = $this->failure(static fn() => $transport->send($message, $mime));

        self::assertFalse($error->permanent);
    }

    public function testPlainTextIsRefusedForAnyHostButThisMachine(): void
    {
        $failure = null;
        try {
            new SmtpTransport('smtp.example.com', 25, 'none');
        } catch (\InvalidArgumentException $error) {
            $failure = $error;
        }

        self::assertNotNull($failure);
        self::assertStringContainsString('only for a development sink', $failure->getMessage());
        self::assertInstanceOf(SmtpTransport::class, new SmtpTransport('127.0.0.1', 1025, 'none'));
        self::assertInstanceOf(SmtpTransport::class, new SmtpTransport('localhost', 1025, 'none'));
    }

    /**
     * @param array<string, mixed> $tls
     */
    private function transport(string $encryption, float $timeout = 5.0, array $tls = []): SmtpTransport
    {
        return new SmtpTransport(
            'localhost',
            $this->server->port ?? 1,
            $encryption,
            'api_token',
            self::PASSWORD,
            $timeout,
            'app.example.org',
            tls: $tls,
        );
    }

    /**
     * @return array{Message, string}
     */
    private function message(): array
    {
        $message = new Message(
            to: [new Address('ada@example.com', 'Ada')],
            subject: 'Confirm your email',
            text: "Open this link to confirm: https://app.example.org/verify?token=abc\n",
            html: '<p><a href="https://app.example.org/verify?token=abc">Confirm your email</a></p>',
            from: new Address('app@example.org', 'App'),
        );
        return [$message, $this->mime()->render($message)];
    }

    private function mime(): Mime
    {
        return new Mime(new FrozenClock('2026-11-21T10:00:00Z'));
    }

    private function failure(\Closure $send): NotSent
    {
        try {
            $send();
        } catch (NotSent $error) {
            return $error;
        }
        self::fail('The message was sent, and should not have been.');
    }

    private function inFiber(\Closure $body): mixed
    {
        $fiber = null;
        $task = Task::spawn($this->loop, function () use ($body, &$fiber): mixed {
            $fiber = \Fiber::getCurrent();
            try {
                return $body();
            } finally {
                $this->loop->stop();
            }
        });
        $deadline = $this->loop->delay(10, fn() => $this->loop->stop());
        try {
            $this->loop->run();
        } finally {
            $this->loop->cancel($deadline);
        }
        self::assertNotNull($fiber, 'The send ran inside a fiber.');
        return $task->result();
    }
}
