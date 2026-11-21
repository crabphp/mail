<?php

declare(strict_types=1);

namespace Crab\Mail\Smtp;

use Closure;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use RuntimeException;

/**
 * The SMTP conversation over one connection, used by one send at a time:
 * writes that wait for room, and replies read whole and bounded, each
 * line at most 512 bytes and a reply at most 100 lines and 16 KiB. Every
 * wait ends at the deadline or when the cancellation fires.
 *
 * @internal
 */
final class Channel
{
    private const int MAX_REPLY_BYTES = 16_384;

    private string $buffer = '';
    private ?Closure $changed = null;
    private ?RuntimeException $failure = null;

    public function __construct(
        public readonly Connection $connection,
    ) {
        $connection->onData(function (Connection $connection, string $bytes): void {
            if ((strlen($this->buffer) + strlen($bytes)) > self::MAX_REPLY_BYTES) {
                $this->failure = new RuntimeException('The SMTP server sent a reply of more than 16 KiB.');
                $connection->close();
                return;
            }
            $this->buffer .= $bytes;
            $this->changed?->__invoke();
        });
        $connection->onClose(function (): void {
            $this->changed?->__invoke();
        });
        $connection->onDrain(function (): void {
            $this->changed?->__invoke();
        });
    }

    public function write(string $bytes, float $deadline, Cancellation $cancellation): void
    {
        while (true) {
            $cancellation->throwIfCancelled();
            $result = $this->connection->tryWrite($bytes);
            if ($result === WriteResult::Accepted) {
                return;
            }
            if ($result === WriteResult::Closed) {
                throw new RuntimeException('The SMTP server closed the connection while it was being written to.');
            }
            $this->wait($deadline, $cancellation);
        }
    }

    public function reply(float $deadline, Cancellation $cancellation): Reply
    {
        $lines = [];
        $code = null;
        $size = 0;
        while (true) {
            while (($end = strpos($this->buffer, "\r\n")) === false) {
                if (strlen($this->buffer) > 512) {
                    throw new RuntimeException('The SMTP server sent a reply line of more than 512 bytes.');
                }
                if ($this->connection->isClosed()) {
                    throw $this->failure ?? new RuntimeException('The SMTP server closed the connection.');
                }
                $this->wait($deadline, $cancellation);
            }
            $line = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 2);
            $size += strlen($line) + 2;
            if (
                strlen($line) > 510
                || $size > self::MAX_REPLY_BYTES
                || count($lines) >= 100
                || preg_match('/^([2-5][0-9]{2})([- ]?)([^\r\n]*)$/D', $line, $parts) !== 1
                || $parts[2] === '' && $parts[3] !== ''
                || $code !== null && $code !== (int) $parts[1]
            ) {
                throw new RuntimeException('The SMTP server sent a reply that is not SMTP.');
            }
            $code = (int) $parts[1];
            $lines[] = $parts[3];
            if ($parts[2] !== '-') {
                return new Reply($code, $lines);
            }
        }
    }

    /**
     * Writes a command and reads its reply.
     */
    public function command(string $line, float $deadline, Cancellation $cancellation): Reply
    {
        $this->write($line . "\r\n", $deadline, $cancellation);
        return $this->reply($deadline, $cancellation);
    }

    /**
     * Turns the connection to TLS after the server's 220 to STARTTLS.
     *
     * @param array<string, mixed> $options PHP SSL context options, verification included.
     */
    public function startTls(array $options, float $deadline, Cancellation $cancellation): void
    {
        if ($this->buffer !== '') {
            throw new RuntimeException(
                'The SMTP server sent more after agreeing to STARTTLS; the connection was given up.',
            );
        }
        while ($this->connection->bufferedBytes() !== 0) {
            $this->wait($deadline, $cancellation);
        }
        $left = $deadline - (hrtime(true) / 1e9);
        if ($left <= 0) {
            throw new RuntimeException('The deadline passed before TLS could start.');
        }
        Async::await(
            $this->connection->loop(),
            function (Closure $resume, Closure $fail) use ($options, $left): Closure {
                // A failed handshake closes the connection, which is the only word of it.
                $this->changed = function () use ($fail): void {
                    if ($this->connection->isClosed()) {
                        $fail(
                            new RuntimeException(
                                'TLS with the SMTP server failed: its certificate was not trusted or did not name the host, or the handshake did not finish.',
                            ),
                        );
                    }
                };
                $this->connection->startTls($resume, $options, server: false, timeout: $left);
                return function (): void {
                    $this->changed = null;
                };
            },
            $cancellation,
        );
    }

    private function wait(float $deadline, Cancellation $cancellation): void
    {
        $left = $deadline - (hrtime(true) / 1e9);
        if ($left <= 0) {
            throw new RuntimeException('The deadline passed while waiting for the SMTP server.');
        }
        $loop = $this->connection->loop();
        Async::await(
            $loop,
            function (Closure $resume, Closure $fail) use ($left, $loop): Closure {
                $this->changed = $resume;
                $timer = $loop->delay($left, static fn() => $fail(
                    new RuntimeException('The deadline passed while waiting for the SMTP server.'),
                ));
                return function () use ($timer, $loop): void {
                    $this->changed = null;
                    $loop->cancel($timer);
                };
            },
            $cancellation,
        );
    }
}
