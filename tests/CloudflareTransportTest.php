<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use Crab\HttpClient\FakeClient;
use Crab\Mail\Mime;
use Crab\Mail\Transports\CloudflareTransport;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudflareTransportTest extends TestCase
{
    private const string ACCOUNT = '0123456789abcdef0123456789abcdef';
    private const string TOKEN = 'cf-api-token-that-must-never-leak';
    private const string URL = 'https://api.cloudflare.com/client/v4/accounts/0123456789abcdef0123456789abcdef/email/sending/send_raw';

    public function testItPostsTheEnvelopeAndTheRawMessageWithTheBearerToken(): void
    {
        $http = new FakeClient([
            'POST ' . self::URL => [
                'success' => true,
                'errors' => [],
                'messages' => [],
                'result' => [
                    'message_id' => 'abc123@example.org',
                    'delivered' => ['ada@example.com'],
                    'permanent_bounces' => [],
                    'queued' => [],
                    'suppressed_recipients' => [],
                ],
            ],
        ]);
        [$message, $mime] = self::message();

        $sent = new CloudflareTransport($http, self::ACCOUNT, self::TOKEN)->send($message, $mime);

        self::assertSame('abc123@example.org', $sent->messageId);
        self::assertSame('cloudflare', $sent->transport);
        $requests = $http->sent('POST ' . self::URL);
        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
        self::assertStringStartsWith('application/json', $requests[0]->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'from' => 'app@example.org',
                'recipients' => ['ada@example.com', 'cc@example.com', 'hidden@example.com'],
                'mime_message' => $mime,
            ],
            json_decode((string) $requests[0]->getBody(), true),
        );
    }

    /**
     * @return iterable<string, array{int, int, string, bool}>
     */
    public static function failures(): iterable
    {
        yield 'invalid schema' => [400, 10_001, 'email.sending.error.invalid_request_schema', true];
        yield 'too big' => [400, 10_200, 'email.sending.error.email.too_big', true];
        yield 'invalid email' => [400, 10_202, 'email.sending.error.email.invalid', true];
        yield 'unauthorized' => [401, 10_101, 'email.sending.error.authentication.unauthorized', true];
        yield 'bad token type' => [401, 10_103, 'email.sending.error.authentication.bad_token_type', true];
        yield 'forbidden' => [403, 10_102, 'email.sending.error.authentication.forbidden', true];
        yield 'not entitled' => [403, 10_105, 'email.sending.error.authentication.not_entitled', true];
        yield 'sending disabled' => [403, 10_203, 'email.sending.error.email.sending_disabled', true];
        yield 'suspended' => [403, 10_207, 'email.sending.error.email.account_suspended', true];
        yield 'throttled' => [429, 10_004, 'email.sending.error.throttled', false];
        yield 'internal' => [500, 10_002, 'email.sending.error.internal_server', false];
        yield 'not implemented' => [500, 10_003, 'email.sending.error.not_implemented', false];
        yield 'upstream' => [503, 10_100, 'email.sending.error.authentication.upstream', false];
    }

    #[DataProvider('failures')]
    public function testEachErrorIsTemporaryOrPermanentAndNeverHoldsTheToken(
        int $status,
        int $code,
        string $text,
        bool $permanent,
    ): void {
        $http = new FakeClient([
            'POST ' . self::URL => FakeClient::response($status, [
                'success' => false,
                'errors' => [['code' => $code, 'message' => $text]],
                'messages' => [],
                'result' => null,
            ]),
        ]);
        [$message, $mime] = self::message();

        $error = self::failure(new CloudflareTransport($http, self::ACCOUNT, self::TOKEN), $message, $mime);

        self::assertSame($permanent, $error->permanent);
        self::assertSame($code, $error->errorCode);
        self::assertStringContainsString($text, $error->reason);
        self::assertStringContainsString((string) $status, $error->reason);
        self::assertStringNotContainsString(self::TOKEN, (string) $error);
    }

    public function testANetworkFailureIsTemporaryAndNeverHoldsTheToken(): void
    {
        $http = new FakeClient([
            'POST ' . self::URL => new \RuntimeException('Could not connect to api.cloudflare.com.'),
        ]);
        [$message, $mime] = self::message();

        $error = self::failure(new CloudflareTransport($http, self::ACCOUNT, self::TOKEN), $message, $mime);

        self::assertFalse($error->permanent);
        self::assertStringContainsString('Could not connect', $error->reason);
        self::assertStringNotContainsString(self::TOKEN, (string) $error);
    }

    public function testAnAnswerThatIsNotJsonIsStillClassifiedByItsStatus(): void
    {
        $http = new FakeClient(['POST ' . self::URL => FakeClient::response(502, 'Bad gateway')]);
        [$message, $mime] = self::message();

        $error = self::failure(new CloudflareTransport($http, self::ACCOUNT, self::TOKEN), $message, $mime);

        self::assertFalse($error->permanent);
        self::assertSame(502, $error->errorCode);
    }

    public function testAMalformedAccountIdOrTokenIsRefusedWithoutRepeatingIt(): void
    {
        $failures = [];
        foreach ([['../x', self::TOKEN], [self::ACCOUNT, "token with\nnewline"]] as [$account, $token]) {
            try {
                new CloudflareTransport(new FakeClient(), $account, $token);
            } catch (\InvalidArgumentException $error) {
                $failures[] = $error->getMessage();
            }
        }

        self::assertCount(2, $failures);
        self::assertStringNotContainsString('newline', $failures[1]);
    }

    private static function failure(CloudflareTransport $transport, Message $message, string $mime): NotSent
    {
        try {
            $transport->send($message, $mime);
        } catch (NotSent $error) {
            return $error;
        }
        self::fail('The message was sent, and should not have been.');
    }

    /**
     * @return array{Message, string}
     */
    private static function message(): array
    {
        $message = new Message(
            to: [new Address('ada@example.com')],
            subject: 'Confirm your email',
            text: 'Confirm',
            from: new Address('app@example.org', 'App'),
            cc: [new Address('cc@example.com')],
            bcc: [new Address('hidden@example.com')],
        );
        return [$message, new Mime(new FrozenClock('2026-11-21T10:00:00Z'))->render($message)];
    }
}
