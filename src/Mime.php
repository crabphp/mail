<?php

declare(strict_types=1);

namespace Crab\Mail;

use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Attachment;
use Crab\Contracts\Mail\Message;
use Psr\Clock\ClockInterface;

/**
 * Writes a message as RFC 5322 text with CRLF line ends, ready for SMTP
 * DATA or a provider's raw endpoint. Every line is 7-bit: a header that
 * is not plain ASCII is written as RFC 2047 encoded words, a body as
 * quoted-printable and an attachment as base64. Bcc is never written.
 *
 *     text only            text/plain
 *     html only            text/html
 *     text and html        multipart/alternative
 *     inline with an id    multipart/related around the body
 *     any other file       multipart/mixed around all of it
 *
 * Headers are folded at 78 characters and no line is longer than 998.
 */
final class Mime
{
    /**
     * The largest message written, in bytes: Cloudflare's limit, and a
     * sane one for any relay.
     */
    public const int MAX_BYTES = 26_214_400;

    private readonly \Closure $random;

    /**
     * @param ClockInterface|null $clock What the Date header is read from; the system's when null.
     * @param (\Closure(int): string)|null $random Random bytes for the Message-ID and the boundaries; random_bytes() when null.
     */
    public function __construct(
        private readonly ?ClockInterface $clock = null,
        ?\Closure $random = null,
    ) {
        $this->random = $random ?? random_bytes(...);
    }

    /**
     * @throws \InvalidArgumentException When the message has no from, a header cannot be written, or it would be larger than MAX_BYTES.
     */
    public function render(Message $message): string
    {
        if ($message->from === null) {
            throw new \InvalidArgumentException(
                'A message needs a from address to be written: give it one, or set mail.from.address.',
            );
        }
        $from = $message->from;
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $domain = substr($from->email, strrpos($from->email, '@') + 1);
        $head = self::header('From', self::addresses([$from]));
        if ($message->to !== []) {
            $head .= self::header('To', self::addresses($message->to));
        }
        if ($message->cc !== []) {
            $head .= self::header('Cc', self::addresses($message->cc));
        }
        if ($message->replyTo !== []) {
            $head .= self::header('Reply-To', self::addresses($message->replyTo));
        }
        $head .= self::header('Subject', self::unstructured($message->subject));
        $head .= self::header('Date', $now->format('D, d M Y H:i:s O'));
        $head .= self::header('Message-ID', '<' . bin2hex(($this->random)(16)) . '@' . $domain . '>');
        $head .= self::header('MIME-Version', '1.0');
        foreach ($message->headers as $name => $value) {
            // Written as given when it is ASCII, since it may be structured, such as List-Unsubscribe.
            $written = preg_match('/[^\x20-\x7e]/', $value) === 1 ? self::encode($value) : $value;
            $head .= self::header($name, $written);
        }
        $mime = $head . $this->body($message);
        if (strlen($mime) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                'The message is '
                . strlen($mime)
                . ' bytes once written, more than the 25 MiB a message may be. Send large files as links.',
            );
        }
        return $mime;
    }

    /**
     * The Message-ID a written message carries, without its angle
     * brackets, or null when it has none.
     */
    public static function messageId(string $mime): ?string
    {
        $end = strpos($mime, "\r\n\r\n");
        $head = $end === false ? $mime : substr($mime, 0, $end + 2);
        if (preg_match('/^Message-ID:\s*<([^>\r\n]+)>/mi', $head, $match) !== 1) {
            return null;
        }
        return $match[1];
    }

    /**
     * The body and its Content-* headers: the text, wrapped in the parts
     * the message's alternatives and attachments need.
     */
    private function body(Message $message): string
    {
        $parts = [];
        if ($message->text !== null) {
            $parts[] = self::text('text/plain', $message->text);
        }
        if ($message->html !== null) {
            $parts[] = self::text('text/html', $message->html);
        }
        $body = count($parts) === 1 ? $parts[0] : $this->multipart('alternative', $parts);
        $related = [];
        $mixed = [];
        foreach ($message->attachments as $attachment) {
            if ($attachment->inline && $attachment->contentId !== null) {
                $related[] = self::attachment($attachment);
            } else {
                $mixed[] = self::attachment($attachment);
            }
        }
        if ($related !== []) {
            $body = $this->multipart('related', [$body, ...$related]);
        }
        if ($mixed !== []) {
            $body = $this->multipart('mixed', [$body, ...$mixed]);
        }
        return $body;
    }

    /**
     * @param list<string> $parts Each with its own headers, a blank line and its body.
     */
    private function multipart(string $subtype, array $parts): string
    {
        $boundary = '=_crab_' . bin2hex(($this->random)(12));
        $out = self::header('Content-Type', "multipart/{$subtype}; boundary=\"{$boundary}\"") . "\r\n";
        foreach ($parts as $part) {
            $out .= "--{$boundary}\r\n" . $part . "\r\n";
        }
        return $out . "--{$boundary}--\r\n";
    }

    private static function text(string $type, string $text): string
    {
        $text = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text);
        $encoded = quoted_printable_encode($text);
        if ($encoded !== '' && !str_ends_with($encoded, "\r\n")) {
            $encoded .= "\r\n";
        }
        return (
            self::header('Content-Type', "{$type}; charset=UTF-8")
            . self::header('Content-Transfer-Encoding', 'quoted-printable')
            . "\r\n"
            . $encoded
        );
    }

    private static function attachment(Attachment $attachment): string
    {
        $disposition = $attachment->inline ? 'inline' : 'attachment';
        $parameter = self::filename($attachment->filename);
        $out = self::header('Content-Type', $attachment->type . '; ' . str_replace('filename', 'name', $parameter));
        $out .= self::header('Content-Disposition', $disposition . '; ' . $parameter);
        if ($attachment->contentId !== null) {
            $out .= self::header('Content-ID', '<' . $attachment->contentId . '>');
        }
        $out .= self::header('Content-Transfer-Encoding', 'base64') . "\r\n";
        $encoded = chunk_split(base64_encode($attachment->content), 76, "\r\n");
        return $out . $encoded;
    }

    /**
     * The filename parameter: quoted when it is plain ASCII, otherwise in
     * RFC 2231 continuations, each short enough to fold between.
     */
    private static function filename(string $filename): string
    {
        if (preg_match('/^[\x20-\x21\x23-\x5b\x5d-\x7e]{1,60}$/D', $filename) === 1) {
            return 'filename="' . $filename . '"';
        }
        $pieces = [];
        $rest = $filename;
        while ($rest !== '') {
            $piece = mb_strcut($rest, 0, 15, 'UTF-8');
            $pieces[] = $piece;
            $rest = substr($rest, strlen($piece));
        }
        $parameters = [];
        foreach ($pieces as $index => $piece) {
            $parameters[] = "filename*{$index}*=" . ($index === 0 ? "UTF-8''" : '') . rawurlencode($piece);
        }
        return implode('; ', $parameters);
    }

    /**
     * Addresses as a header writes them, separated by commas.
     *
     * @param list<Address> $addresses
     */
    private static function addresses(array $addresses): string
    {
        $written = [];
        foreach ($addresses as $address) {
            $name = $address->name ?? '';
            if ($name === '') {
                $written[] = $address->email;
                continue;
            }
            $written[] = self::phrase($name) . ' <' . $address->email . '>';
        }
        return implode(', ', $written);
    }

    /**
     * A display name: as it is when it is plain words, quoted when it has
     * other ASCII, and encoded words when it is not ASCII.
     */
    private static function phrase(string $name): string
    {
        if (preg_match('/[^\x20-\x7e]/', $name) === 1 || preg_match('/\S{60}|  |^ | $/D', $name) === 1) {
            return self::encode($name);
        }
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+$/D', $name) === 1) {
            return $name;
        }
        return '"' . addcslashes($name, '\\"') . '"';
    }

    /**
     * Unstructured text such as a subject: as it is when it is plain
     * ASCII that folds well, otherwise encoded words.
     */
    private static function unstructured(string $text): string
    {
        if (preg_match('/[^\x20-\x7e]/', $text) === 1 || preg_match('/\S{70}|  |^ | $|=\?/D', $text) === 1) {
            return self::encode($text);
        }
        return $text;
    }

    /**
     * RFC 2047 encoded words of at most 64 characters, so that one fits beside a header's name, separated by
     * spaces, which a reader drops between two encoded words. A UTF-8
     * character is never split between two words.
     */
    private static function encode(string $text): string
    {
        $words = [];
        while ($text !== '') {
            $chunk = mb_strcut($text, 0, 39, 'UTF-8');
            if ($chunk === '') {
                $chunk = substr($text, 0, 1);
            }
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $text = substr($text, strlen($chunk));
        }
        return implode(' ', $words);
    }

    /**
     * One header, folded at the spaces in its value so that a line stays
     * within 78 characters where it can and within 998 always.
     */
    private static function header(string $name, string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
            throw new \InvalidArgumentException("The {$name} header would hold a line break; it is refused.");
        }
        $line = $name . ':';
        $out = '';
        foreach (explode(' ', $value) as $word) {
            if (strlen($word) > 990) {
                throw new \InvalidArgumentException(
                    "The {$name} header has a word of more than 990 characters, longer than a line may be.",
                );
            }
            if (strlen($line) > (strlen($name) + 1) && (strlen($line) + 1 + strlen($word)) > 78) {
                $out .= $line . "\r\n";
                $line = ' ' . $word;
                continue;
            }
            $line .= ' ' . $word;
        }
        return $out . $line . "\r\n";
    }
}
