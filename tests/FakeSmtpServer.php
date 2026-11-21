<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\Engine\EventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Listener;

/**
 * An SMTP server on a loopback port whose replies each test scripts. It
 * keeps every command it was sent and every message's data, with the
 * dot-stuffing undone, and never relays anything anywhere.
 */
final class FakeSmtpServer
{
    public readonly int $port;

    /**
     * Every command line received, in order; AUTH LOGIN's answers included.
     *
     * @var list<string>
     */
    public array $commands = [];

    /**
     * Each message's data as received, before dot-stuffing was undone.
     *
     * @var list<string>
     */
    public array $raw = [];

    /**
     * Each message's data with dot-stuffing undone.
     *
     * @var list<string>
     */
    public array $messages = [];

    /**
     * What EHLO lists after the greeting line.
     *
     * @var list<string>
     */
    public array $extensions = ['8BITMIME', 'AUTH PLAIN LOGIN'];

    /**
     * Replies by command verb (EHLO, MAIL, RCPT, DATA, AUTH, ...) in place of the usual ones.
     *
     * @var array<string, string>
     */
    public array $replies = [];

    public string $dataReply = '250 2.0.0 Ok';
    public string $authReply = '235 2.7.0 Authentication successful';
    public bool $silent = false;

    /**
     * The certificate STARTTLS is done with; STARTTLS is offered only when set.
     */
    public ?string $startTls = null;

    private Listener $listener;

    /**
     * @var list<Connection>
     */
    private array $connections = [];

    /**
     * @param string|null $certificate A PEM file of certificate and key, for implicit TLS from the first byte.
     */
    public function __construct(
        private readonly EventLoop $loop,
        ?string $certificate = null,
    ) {
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        $this->listener = new Listener(
            $loop,
            $socket,
            $this->accept(...),
            tls: $certificate === null ? null : ['local_cert' => $certificate],
            handshakeTimeout: 2.0,
        );
        $this->listener->attach();
    }

    public function close(): void
    {
        $this->listener->close();
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    /**
     * A self-signed certificate for localhost with its key, written to a
     * file in the directory.
     */
    public static function certificate(string $directory, string $name = 'localhost'): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $request = openssl_csr_new(['commonName' => $name], $key, [
            'digest_alg' => 'sha256',
            'req_extensions' => 'v3_req',
        ]);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $public);
        openssl_pkey_export($key, $private);
        $path = $directory . '/' . $name . '.pem';
        file_put_contents($path, $public . $private);
        chmod($path, 0o600);
        file_put_contents($directory . '/' . $name . '.crt', $public);
        return $path;
    }

    private function accept(Connection $connection): void
    {
        $this->connections[] = $connection;
        if ($this->silent) {
            return;
        }
        $connection->tryWrite("220 fake.test ESMTP\r\n");
        $buffer = '';
        $data = null;
        $login = 0;
        $connection->onData(function (Connection $connection, string $bytes) use (&$buffer, &$data, &$login): void {
            $buffer .= $bytes;
            while (($end = strpos($buffer, "\r\n")) !== false) {
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 2);
                if ($data !== null) {
                    if ($line === '.') {
                        $this->raw[] = $data[0];
                        $this->messages[] = $data[1];
                        $data = null;
                        $connection->tryWrite($this->dataReply . "\r\n");
                        continue;
                    }
                    $data[0] .= $line . "\r\n";
                    $data[1] .= (str_starts_with($line, '.') ? substr($line, 1) : $line) . "\r\n";
                    continue;
                }
                $this->commands[] = $line;
                if ($login > 0) {
                    $login--;
                    $connection->tryWrite($login === 1 ? "334 UGFzc3dvcmQ6\r\n" : $this->authReply . "\r\n");
                    continue;
                }
                $verb = strtoupper((string) strtok($line, ' :'));
                if (isset($this->replies[$verb])) {
                    $connection->tryWrite($this->replies[$verb] . "\r\n");
                    continue;
                }
                if ($verb === 'EHLO') {
                    $extensions = $this->extensions;
                    if ($this->startTls !== null && !$connection->isEncrypted()) {
                        $extensions[] = 'STARTTLS';
                    }
                    $lines = ['fake.test greets you', ...$extensions];
                    $reply = '';
                    foreach ($lines as $index => $text) {
                        $reply .= '250' . ($index === (count($lines) - 1) ? ' ' : '-') . $text . "\r\n";
                    }
                    $connection->tryWrite($reply);
                } elseif ($verb === 'STARTTLS' && $this->startTls !== null) {
                    $connection->tryWrite("220 2.0.0 Ready to start TLS\r\n");
                    $connection->startTls(
                        static function (): void {},
                        ['local_cert' => $this->startTls],
                        unread: $buffer,
                    );
                    $buffer = '';
                    return;
                } elseif ($verb === 'AUTH') {
                    if (str_starts_with(strtoupper($line), 'AUTH LOGIN')) {
                        $login = 2;
                        $connection->tryWrite("334 VXNlcm5hbWU6\r\n");
                    } else {
                        $connection->tryWrite($this->authReply . "\r\n");
                    }
                } elseif ($verb === 'DATA') {
                    $data = ['', ''];
                    $connection->tryWrite("354 Start mail input; end with <CR><LF>.<CR><LF>\r\n");
                } elseif ($verb === 'QUIT') {
                    $connection->tryWrite("221 2.0.0 Bye\r\n");
                    $connection->end();
                    return;
                } else {
                    $connection->tryWrite("250 2.1.0 Ok\r\n");
                }
            }
        });
    }
}
