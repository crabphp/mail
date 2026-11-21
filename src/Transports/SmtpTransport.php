<?php

declare(strict_types=1);

namespace Crab\Mail\Transports;

use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;
use Crab\Contracts\Mail\Sent;
use Crab\Contracts\Mail\Transport;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\Connector;
use Crab\Mail\Mime;
use Crab\Mail\Smtp\Channel;
use Crab\Mail\Smtp\Reply;
use Psr\Log\LoggerInterface;

/**
 * Submits each message to an SMTP relay, such as Cloudflare's
 * smtp.mx.cloudflare.net:465, over one connection of its own, on the
 * event loop: inside a worker only the fiber that sends waits, and from a
 * console command the loop is run until the message is sent.
 *
 * TLS is verified against the host: implicit TLS (tls, port 465) or
 * STARTTLS that the server must offer (starttls, port 587). Plain text
 * (none) is only for a development sink on this machine. The login is
 * AUTH PLAIN, or LOGIN where the server offers only that.
 *
 * A 4xx reply, a failed connection, a TLS failure and the deadline are
 * temporary NotSent; a 5xx reply is permanent. One deadline, timeout
 * seconds, covers the whole exchange.
 */
final class SmtpTransport implements Transport
{
    /**
     * The most recipients one message may have: Cloudflare's limit per session.
     */
    public const int MAX_RECIPIENTS = 50;

    private readonly Connector $connector;
    private bool $warned = false;

    /**
     * @param string $encryption tls, starttls or none.
     * @param string|null $username With $password, or neither.
     * @param float $timeout Seconds the whole exchange may take.
     * @param string $localDomain The name this side gives in EHLO.
     * @param bool $verify Whether the server's certificate is checked; false only for a development sink with a self-signed one.
     * @param string|null $returnPath The MAIL FROM address, when not the message's from.
     * @param array<string, mixed> $tls More PHP SSL context options, such as cafile.
     * @throws \InvalidArgumentException When the settings cannot work together.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 465,
        private readonly string $encryption = 'tls',
        private readonly ?string $username = null,
        #[\SensitiveParameter]
        private readonly ?string $password = null,
        private readonly float $timeout = 30.0,
        private readonly string $localDomain = 'localhost',
        private readonly bool $verify = true,
        private readonly ?string $returnPath = null,
        private readonly array $tls = [],
        ?Connector $connector = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (
            preg_match(
                '/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.?$|^\[?[0-9A-Fa-f:.]+\]?$/D',
                $host,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'mail.smtp.host must be a host name or an IP address, such as smtp.mx.cloudflare.net.',
            );
        }
        if ($port < 1 || $port > 65_535) {
            throw new \InvalidArgumentException('mail.smtp.port must be from 1 to 65535, such as 465.');
        }
        if (!in_array($encryption, ['tls', 'starttls', 'none'], true)) {
            throw new \InvalidArgumentException('mail.smtp.encryption must be tls, starttls or none.');
        }
        if ($encryption === 'none' && !self::loopback($host)) {
            throw new \InvalidArgumentException(
                "mail.smtp.encryption is none for {$host}; plain text is only for a development sink on this machine (localhost, 127.0.0.1 or ::1). Use tls or starttls.",
            );
        }
        if (($username === null) !== ($password === null)) {
            throw new \InvalidArgumentException(
                'mail.smtp.username and mail.smtp.password are set together or not at all.',
            );
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('mail.smtp.timeout must be a number of seconds greater than 0.');
        }
        if (preg_match('/^[A-Za-z0-9.-]{1,253}$|^\[[0-9A-Fa-f:.]+\]$/D', $localDomain) !== 1) {
            throw new \InvalidArgumentException('mail.smtp.local_domain must be a host name, such as app.example.com.');
        }
        if ($returnPath !== null && filter_var($returnPath, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(
                'mail.smtp.return_path must be an email address, such as bounces@example.com.',
            );
        }
        $this->connector = $connector ?? new Connector();
    }

    public function send(Message $message, string $mime, ?Cancellation $cancellation = null): Sent
    {
        if ($message->recipientCount() > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException(
                'A message may have at most '
                . self::MAX_RECIPIENTS
                . ' recipients over SMTP, to, cc and bcc together; it has '
                . $message->recipientCount()
                . '. Send it as several messages.',
            );
        }
        if ($message->from === null) {
            throw new \InvalidArgumentException('A message needs a from address to be sent over SMTP.');
        }
        if (!$this->verify && !$this->warned && $this->encryption !== 'none') {
            $this->warned = true;
            $this->logger?->warning(
                'mail.smtp.verify is false: the certificate of {host} is not checked, so anyone between here and it can read the mail and the password. Use this only with a development sink.',
                ['host' => $this->host],
            );
        }
        $cancellation?->throwIfCancelled();
        $loop = EventLoop::current();
        $deadline = (hrtime(true) / 1e9) + $this->timeout;
        $scope = new Cancellation();
        $expired = new \RuntimeException(
            "The SMTP exchange with {$this->host} took longer than {$this->timeout} seconds.",
        );
        $unsubscribe = $cancellation?->subscribe($scope->cancel(...));
        $timer = $loop->delay($this->timeout, static fn() => $scope->cancel($expired));
        $connection = null;
        try {
            $connection = $this->connector->connect(
                $loop,
                trim($this->host, '[]'),
                $this->port,
                $deadline,
                $scope,
                tlsHost: $this->encryption === 'tls' ? $this->host : null,
                tls: $this->tlsOptions(),
            );
            return $this->exchange(new Channel($connection), $message, $mime, $deadline, $scope);
        } catch (NotSent $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($cancellation?->isCancelled() ?? false) {
                throw $error instanceof CancelledException
                    ? $error
                    : new CancelledException('Sending the mail was cancelled.', previous: $error);
            }
            if ($scope->isCancelled() || $error instanceof CancelledException || (hrtime(true) / 1e9) >= $deadline) {
                throw new NotSent($expired->getMessage(), false, null);
            }
            if ($error instanceof \InvalidArgumentException) {
                throw $error;
            }
            throw new NotSent(
                "Could not send through {$this->host}:{$this->port}: " . $this->scrub($error->getMessage()),
                false,
                null,
            );
        } finally {
            $unsubscribe?->__invoke();
            $loop->cancel($timer);
            $connection?->close();
        }
    }

    private function exchange(
        Channel $channel,
        Message $message,
        string $mime,
        float $deadline,
        Cancellation $scope,
    ): Sent {
        $this->expect($channel->reply($deadline, $scope), [220], 'greeting');
        $hello = $channel->command('EHLO ' . $this->localDomain, $deadline, $scope);
        $this->expect($hello, [250], 'EHLO');
        if ($this->encryption === 'starttls') {
            if ($hello->extension('STARTTLS') === null) {
                throw new NotSent(
                    "{$this->host} does not offer STARTTLS, and mail.smtp.encryption is starttls; nothing was sent in plain text. Use encryption tls if it listens for implicit TLS.",
                    true,
                    null,
                );
            }
            $this->expect($channel->command('STARTTLS', $deadline, $scope), [220], 'STARTTLS');
            $channel->startTls($this->tlsOptions(), $deadline, $scope);
            $hello = $channel->command('EHLO ' . $this->localDomain, $deadline, $scope);
            $this->expect($hello, [250], 'EHLO');
        }
        if ($this->username !== null && $this->password !== null) {
            $this->login($channel, $hello, $this->username, $this->password, $deadline, $scope);
        }
        $sender = $this->returnPath ?? $message->from->email ?? '';
        $body = $hello->extension('8BITMIME') === null ? '' : ' BODY=8BITMIME';
        $this->expect($channel->command('MAIL FROM:<' . $sender . '>' . $body, $deadline, $scope), [250], 'MAIL FROM');
        foreach ([...$message->to, ...$message->cc, ...$message->bcc] as $recipient) {
            $this->expect(
                $channel->command('RCPT TO:<' . $recipient->email . '>', $deadline, $scope),
                [250, 251],
                'RCPT TO',
            );
        }
        $this->expect($channel->command('DATA', $deadline, $scope), [354], 'DATA');
        $data = self::data($mime);
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += 65_536) {
            $channel->write(substr($data, $offset, 65_536), $deadline, $scope);
        }
        $final = $channel->reply($deadline, $scope);
        $this->expect($final, [250], 'the end of DATA');
        // The 250 is authoritative: the message is sent, whatever QUIT does.
        $channel->connection->tryWrite("QUIT\r\n");
        $channel->connection->end();
        return new Sent(self::idFrom($final) ?? Mime::messageId($mime), 'smtp');
    }

    private function login(
        Channel $channel,
        Reply $hello,
        string $username,
        #[\SensitiveParameter]
        string $password,
        float $deadline,
        Cancellation $scope,
    ): void {
        $mechanisms = $hello->extension('AUTH');
        if ($mechanisms === null) {
            throw new NotSent(
                "{$this->host} does not offer AUTH, so mail.smtp.username cannot log in. Check the host and port, and that the connection is encrypted.",
                true,
                null,
            );
        }
        if (in_array('PLAIN', $mechanisms, true)) {
            $reply = $channel->command(
                'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password),
                $deadline,
                $scope,
            );
            $this->loggedIn($reply);
            return;
        }
        if (in_array('LOGIN', $mechanisms, true)) {
            $this->expect($channel->command('AUTH LOGIN', $deadline, $scope), [334], 'AUTH LOGIN');
            $this->expect($channel->command(base64_encode($username), $deadline, $scope), [334], 'AUTH LOGIN');
            $this->loggedIn($channel->command(base64_encode($password), $deadline, $scope));
            return;
        }
        throw new NotSent(
            "{$this->host} offers neither AUTH PLAIN nor AUTH LOGIN (it offers " . implode(' ', $mechanisms) . ').',
            true,
            null,
        );
    }

    private function loggedIn(Reply $reply): void
    {
        if ($reply->code === 535) {
            throw new NotSent(
                "{$this->host} refused the credentials in mail.smtp.username and mail.smtp.password: "
                    . $this->scrub($reply->text()),
                true,
                535,
            );
        }
        $this->expect($reply, [235], 'AUTH');
    }

    /**
     * @param list<int> $codes
     * @throws NotSent When the reply is none of them.
     */
    private function expect(Reply $reply, array $codes, string $after): void
    {
        if (in_array($reply->code, $codes, true)) {
            return;
        }
        throw new NotSent(
            "{$this->host} answered {$after} with " . $this->scrub($reply->text()),
            $reply->code >= 500,
            $reply->code,
        );
    }

    /**
     * Server text with the credentials taken out, should it repeat them.
     */
    private function scrub(string $text): string
    {
        foreach ([$this->password, $this->username] as $secret) {
            if ($secret === null || $secret === '') {
                continue;
            }
            $text = str_replace([$secret, base64_encode($secret)], '[hidden]', $text);
        }
        return NotSent::clean($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function tlsOptions(): array
    {
        return [
            'peer_name' => trim($this->host, '[]'),
            'verify_peer' => $this->verify,
            'verify_peer_name' => $this->verify,
            'allow_self_signed' => false,
            // The connector offers http/1.1 by default, which an SMTP server need not accept.
            'alpn_protocols' => 'smtp',
            ...$this->tls,
        ];
    }

    /**
     * The message as DATA sends it: CRLF lines, a line that starts with a
     * dot given another, and the terminating dot.
     */
    private static function data(string $mime): string
    {
        $mime = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $mime);
        if (!str_ends_with($mime, "\r\n")) {
            $mime .= "\r\n";
        }
        $mime = (string) preg_replace('/^\./m', '..', $mime);
        return $mime . ".\r\n";
    }

    /**
     * The id a server gives in its final 250, as Cloudflare's
     * "250 2.0.0 Ok <id@domain>" or Postfix's "queued as 4Xyz".
     */
    private static function idFrom(Reply $reply): ?string
    {
        $text = implode(' ', $reply->lines);
        if (preg_match('/<([^<>\s]{1,250})>/', $text, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/queued as ([A-Za-z0-9._@-]{1,250})/i', $text, $match) === 1) {
            return $match[1];
        }
        return null;
    }

    private static function loopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }
}
