<?php

declare(strict_types=1);

namespace Crab\Mail\Transports;

use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use Crab\Contracts\Mail\Sent;
use Crab\Contracts\Mail\Transport;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;

/**
 * Sends through Cloudflare Email Service's REST API, with the message
 * written exactly as the other transports write it: POST
 * /accounts/{account_id}/email/sending/send_raw with the envelope (from
 * and every recipient, bcc included) and the MIME text.
 *
 * Throttling (429), Cloudflare's own failures (500, 503) and a request
 * that got no answer are temporary NotSent; any other refusal, such as
 * a bad token, a sender domain that is not onboarded or a message too
 * large, is permanent. The token is sent as a bearer header and never
 * written into an exception or the log.
 */
final readonly class CloudflareTransport implements Transport
{
    public const string API = 'https://api.cloudflare.com/client/v4';

    /**
     * @param string $accountId The Cloudflare account the sending domain is in.
     * @param string $apiToken A token with the Email Sending: Edit permission.
     * @param string $api The API's base URL; Cloudflare's own unless a test says otherwise.
     * @throws \InvalidArgumentException When the account id or the token is not of their form.
     */
    public function __construct(
        private Client $http,
        private string $accountId,
        #[\SensitiveParameter]
        private string $apiToken,
        private string $api = self::API,
        private float $timeout = 30.0,
    ) {
        if (preg_match('/^[A-Za-z0-9]{1,64}$/D', $accountId) !== 1) {
            throw new \InvalidArgumentException(
                'mail.cloudflare.account_id must be the 32-character id of the Cloudflare account, shown on its dashboard.',
            );
        }
        if (preg_match('/^[\x21-\x7e]{1,512}$/D', $apiToken) !== 1) {
            throw new \InvalidArgumentException(
                'mail.cloudflare.api_token must be a Cloudflare API token with the Email Sending: Edit permission.',
            );
        }
    }

    public function send(Message $message, string $mime, ?Cancellation $cancellation = null): Sent
    {
        if ($message->from === null) {
            throw new \InvalidArgumentException('A message needs a from address to be sent through Cloudflare.');
        }
        $recipients = array_map(static fn($address): string => $address->email, [
            ...$message->to,
            ...$message->cc,
            ...$message->bcc,
        ]);
        try {
            $response = $this->http->timeout($this->timeout)->request(
                'POST',
                $this->api . '/accounts/' . $this->accountId . '/email/sending/send_raw',
                headers: ['Authorization' => 'Bearer ' . $this->apiToken, 'Accept' => 'application/json'],
                json: ['from' => $message->from->email, 'recipients' => $recipients, 'mime_message' => $mime],
                cancellation: $cancellation,
                maxBytes: 1_048_576,
            );
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($cancellation?->isCancelled() ?? false) {
                throw new CancelledException('Sending the mail was cancelled.', previous: $error);
            }
            throw new NotSent(
                'Cloudflare could not be reached: ' . str_replace($this->apiToken, '[hidden]', $error->getMessage()),
                false,
                null,
            );
        }
        $status = $response->getStatusCode();
        try {
            $body = $response->json();
        } catch (\Throwable) {
            $body = null;
        }
        if (is_array($body) && ($body['success'] ?? null) === true && $status >= 200 && $status < 300) {
            $id = $body['result']['message_id'] ?? null;
            return new Sent(is_string($id) && $id !== '' ? $id : null, 'cloudflare');
        }
        $errors = is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : [];
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $code = is_int($first['code'] ?? null) ? $first['code'] : null;
        $said = [];
        foreach ($errors as $error) {
            if (!(is_array($error) && is_scalar($error['message'] ?? null))) {
                continue;
            }

            $said[] = (is_int($error['code'] ?? null) ? $error['code'] . ' ' : '') . $error['message'];
        }
        $reason =
            "Cloudflare refused the message with HTTP {$status}" . ($said === [] ? '.' : ': ' . implode('; ', $said));
        throw new NotSent(
            str_replace($this->apiToken, '[hidden]', $reason),
            !self::temporary($status, $code),
            $code ?? $status,
        );
    }

    /**
     * Throttled, or Cloudflare's own failure: 429 (10004), 500 (10002,
     * 10003), 503 (10100), and any 5xx or 408 besides.
     */
    private static function temporary(int $status, ?int $code): bool
    {
        if (in_array($code, [10_002, 10_003, 10_004, 10_100], true)) {
            return true;
        }
        return $status === 408 || $status === 429 || $status >= 500;
    }
}
