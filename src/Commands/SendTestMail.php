<?php

declare(strict_types=1);

namespace Crab\Mail\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Mailer;
use Crab\Contracts\Mail\Message;
use Crab\Contracts\Mail\NotSent;

/**
 * `mail:test --to=you@example.com`: sends a short message through the
 * configured transport and says what became of it.
 */
final readonly class SendTestMail implements Command
{
    /**
     * Takes the container rather than the mailer, so that a wrong --to is
     * refused before the mailer and its settings are made.
     */
    public function __construct(
        private Container $container,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Send a test message through the configured mail transport
            Options:
              --to=you@example.com   Who receives it

            The message is sent from mail.from.address. The message id is printed when it is sent, the reason when not.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $to = $input->value('to', 'you@example.com');
        if ($to === null) {
            $output->error('--to is missing; use mail:test --to=you@example.com.');
            return 1;
        }
        try {
            $recipient = new Address($to);
        } catch (\InvalidArgumentException) {
            $output->error('--to takes one email address; use mail:test --to=you@example.com.');
            return 1;
        }
        $mailer = $this->container->get(Mailer::class);
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $transport = (string) ($config?->get('mail.transport') ?? 'mail');
        $message = new Message(
            to: [$recipient],
            subject: 'Test message',
            text: "This is a test message sent with mail:test through the {$transport} transport.\n\nIf you can read it, mail is set up.",
        );
        try {
            $sent = $mailer->send($message);
        } catch (NotSent $error) {
            $output->error('Not sent (' . ($error->permanent ? 'permanent' : 'temporary') . '): ' . $error->reason);
            return 1;
        } catch (\InvalidArgumentException $error) {
            $output->error('Not sent: ' . $error->getMessage());
            return 1;
        }
        $output->line(
            "Sent through {$sent->transport} to {$recipient->email}, message id "
            . ($sent->messageId ?? '(none given)')
            . '.',
        );
        return 0;
    }
}
