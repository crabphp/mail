<?php

declare(strict_types=1);

namespace Crab\Contracts\Mail;

/**
 * A file sent with a message, its content held in memory.
 *
 *     new Attachment('invoice.pdf', $pdf, 'application/pdf')
 *     new Attachment('logo.png', $png, 'image/png', inline: true, contentId: 'logo')
 *
 * An inline attachment with a content id is shown inside the HTML body
 * where it says `<img src="cid:logo">`.
 */
final readonly class Attachment
{
    /**
     * @param string $filename The name the recipient sees, UTF-8, without a path.
     * @param string $content The file's bytes.
     * @param string $type Its media type, such as image/png.
     * @param bool $inline Whether it is shown in the body rather than listed as a file.
     * @param string|null $contentId What the HTML body refers to it by, without the angle brackets.
     * @throws \InvalidArgumentException When one of them cannot be written in a message.
     */
    public function __construct(
        public string $filename,
        public string $content,
        public string $type = 'application/octet-stream',
        public bool $inline = false,
        public ?string $contentId = null,
    ) {
        if (
            $filename === ''
            || strlen($filename) > 255
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename) === 1
            || !mb_check_encoding($filename, 'UTF-8')
        ) {
            throw new \InvalidArgumentException(
                'An attachment needs a filename of 1 to 255 bytes of UTF-8, with no slash, backslash or control character, such as invoice.pdf.',
            );
        }
        if (
            preg_match('~^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}$~D', $type)
            !== 1
        ) {
            throw new \InvalidArgumentException(
                'An attachment needs a media type such as application/pdf or image/png, without parameters.',
            );
        }
        if ($contentId !== null && preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.@-]{1,250}$/D', $contentId) !== 1) {
            throw new \InvalidArgumentException(
                'An attachment\'s content id is 1 to 250 letters, digits or the characters !#$%&\'*+/=?^_`{|}~.@- , such as logo or logo@example.com, without angle brackets.',
            );
        }
    }
}
