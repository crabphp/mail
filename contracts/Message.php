<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

/**
 * One email: who it is for, what it says and what comes with it.
 *
 *     new Message(
 *         to: [new Address('ada@example.com', 'Ada')],
 *         subject: 'Confirm your email',
 *         text: "Open this link to confirm: {$url}",
 *         html: "<p><a href=\"{$url}\">Confirm your email</a></p>",
 *     )
 *
 * It has a text body, an HTML body or both, and at least one recipient
 * among to, cc and bcc. Without a from, the mailer gives it the one
 * configured as mail.from. Bcc recipients receive it without appearing
 * in what anyone receives.
 *
 * Everything is checked when it is made, so a message that exists can be
 * written: a subject or a header with a line break in it is refused here,
 * never sent as two headers.
 */
final readonly class Message
{
    /**
     * Headers the message is written with by the mailer itself, which
     * $headers may not set. Content-* is refused as well.
     */
    public const array OWN_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'reply-to',
        'subject',
        'date',
        'message-id',
        'mime-version',
    ];

    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Address> $replyTo
     * @param list<Attachment> $attachments
     * @param array<string, string> $headers More headers by name, such as ['X-Campaign' => 'welcome'].
     * @throws \InvalidArgumentException When the message could not be written as it is.
     */
    public function __construct(
        public array $to,
        public string $subject,
        public ?string $text = null,
        public ?string $html = null,
        public ?Address $from = null,
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public array $attachments = [],
        public array $headers = [],
    ) {
        foreach (['to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'replyTo' => $replyTo] as $name => $addresses) {
            if (!array_is_list($addresses)) {
                throw new \InvalidArgumentException("A message's {$name} must be a list of Address objects.");
            }
            foreach ($addresses as $address) {
                if (!$address instanceof Address) {
                    throw new \InvalidArgumentException(
                        "A message's {$name} must be a list of Address objects, such as [new Address('ada@example.com')].",
                    );
                }
            }
        }
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new \InvalidArgumentException('A message needs at least one recipient in to, cc or bcc.');
        }
        if ($text === null && $html === null) {
            throw new \InvalidArgumentException('A message needs a text body, an HTML body or both.');
        }
        foreach (['subject' => $subject, 'text' => $text, 'html' => $html] as $name => $value) {
            if ($value !== null && !mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException("A message's {$name} must be UTF-8 text.");
            }
        }
        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $subject) === 1) {
            throw new \InvalidArgumentException(
                'A message\'s subject must be one line, with no line break or control character.',
            );
        }
        if (strlen($subject) > 998) {
            throw new \InvalidArgumentException("A message's subject must be at most 998 bytes.");
        }
        if (!array_is_list($attachments)) {
            throw new \InvalidArgumentException("A message's attachments must be a list of Attachment objects.");
        }
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment) {
                throw new \InvalidArgumentException(
                    "A message's attachments must be a list of Attachment objects, such as [new Attachment('invoice.pdf', \$pdf)].",
                );
            }
        }
        $seen = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9-]{1,76}$/D', $name) !== 1) {
                throw new \InvalidArgumentException(
                    'A message\'s header name must be letters, digits and hyphens, such as X-Campaign.',
                );
            }
            $lower = strtolower($name);
            if (in_array($lower, self::OWN_HEADERS, true) || str_starts_with($lower, 'content-')) {
                throw new \InvalidArgumentException(
                    "A message cannot set the {$name} header in headers: it is written from the message itself. Use the message's own field instead.",
                );
            }
            if (isset($seen[$lower])) {
                throw new \InvalidArgumentException("A message's headers name {$name} twice.");
            }
            $seen[$lower] = true;
            if (
                !is_string($value)
                || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1
                || !mb_check_encoding($value, 'UTF-8')
            ) {
                throw new \InvalidArgumentException(
                    "A message's {$name} header must be one line of UTF-8 text, with no line break or control character.",
                );
            }
        }
    }

    /**
     * The same message from another sender.
     */
    public function withFrom(Address $from): self
    {
        return new self(
            $this->to,
            $this->subject,
            $this->text,
            $this->html,
            $from,
            $this->cc,
            $this->bcc,
            $this->replyTo,
            $this->attachments,
            $this->headers,
        );
    }

    /**
     * How many recipients it has, to, cc and bcc together.
     */
    public function recipientCount(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }

    /**
     * The message as JSON-safe data, for the queue: what fromArray()
     * reads. Attachments' content is base64.
     *
     * @return array{
     *     to: list<array{email: string, name: string|null}>,
     *     subject: string,
     *     text: string|null,
     *     html: string|null,
     *     from: array{email: string, name: string|null}|null,
     *     cc: list<array{email: string, name: string|null}>,
     *     bcc: list<array{email: string, name: string|null}>,
     *     replyTo: list<array{email: string, name: string|null}>,
     *     attachments: list<array{filename: string, content: string, type: string, inline: bool, contentId: string|null}>,
     *     headers: array<string, string>,
     * }
     */
    public function toArray(): array
    {
        $addresses = static fn(array $list): array => array_map(static fn(Address $address): array => [
            'email' => $address->email,
            'name' => $address->name,
        ], $list);
        return [
            'to' => $addresses($this->to),
            'subject' => $this->subject,
            'text' => $this->text,
            'html' => $this->html,
            'from' => $this->from === null ? null : ['email' => $this->from->email, 'name' => $this->from->name],
            'cc' => $addresses($this->cc),
            'bcc' => $addresses($this->bcc),
            'replyTo' => $addresses($this->replyTo),
            'attachments' => array_map(static fn(Attachment $attachment): array => [
                'filename' => $attachment->filename,
                'content' => base64_encode($attachment->content),
                'type' => $attachment->type,
                'inline' => $attachment->inline,
                'contentId' => $attachment->contentId,
            ], $this->attachments),
            'headers' => $this->headers,
        ];
    }

    /**
     * Rebuilds a message from what toArray() gave, checking it again.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException When the data is not a message.
     */
    public static function fromArray(array $data): self
    {
        $to = self::addresses($data, 'to');
        $subject = $data['subject'] ?? null;
        $text = $data['text'] ?? null;
        $html = $data['html'] ?? null;
        if (!is_string($subject) || $text !== null && !is_string($text) || $html !== null && !is_string($html)) {
            throw new \InvalidArgumentException(
                'A message\'s data needs subject as text, and text and html as text or null.',
            );
        }
        $from = $data['from'] ?? null;
        if ($from !== null) {
            $from = self::address($from, 'from');
        }
        $attachments = [];
        foreach (self::list($data, 'attachments') as $attachment) {
            if (
                !is_array($attachment)
                || !is_string($attachment['filename'] ?? null)
                || !is_string($attachment['content'] ?? null)
                || !is_string($attachment['type'] ?? null)
                || !is_bool($attachment['inline'] ?? null)
                || ($attachment['contentId'] ?? null) !== null && !is_string($attachment['contentId'])
            ) {
                throw new \InvalidArgumentException(
                    'A message\'s attachments data needs filename, content, type, inline and contentId for each.',
                );
            }
            $content = base64_decode($attachment['content'], true);
            if ($content === false) {
                throw new \InvalidArgumentException('A message\'s attachment content must be base64.');
            }
            $attachments[] = new Attachment(
                $attachment['filename'],
                $content,
                $attachment['type'],
                $attachment['inline'],
                $attachment['contentId'] ?? null,
            );
        }
        $headers = $data['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('A message\'s headers data must be an object of names and values.');
        }
        return new self(
            $to,
            $subject,
            $text,
            $html,
            $from,
            self::addresses($data, 'cc'),
            self::addresses($data, 'bcc'),
            self::addresses($data, 'replyTo'),
            $attachments,
            $headers,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $list = $data[$key] ?? [];
        if (!is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException("A message's {$key} data must be a list.");
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Address>
     */
    private static function addresses(array $data, string $key): array
    {
        return array_map(static fn(mixed $item): Address => self::address($item, $key), self::list($data, $key));
    }

    private static function address(mixed $item, string $key): Address
    {
        if (
            !is_array($item)
            || !is_string($item['email'] ?? null)
            || ($item['name'] ?? null) !== null && !is_string($item['name'])
        ) {
            throw new \InvalidArgumentException(
                "A message's {$key} data needs each address as {\"email\": ..., \"name\": ...}.",
            );
        }
        return new Address($item['email'], $item['name'] ?? null);
    }
}
