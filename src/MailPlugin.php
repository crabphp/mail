<?php

declare(strict_types=1);

namespace Crab\Mail;

use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Mailer as MailerContract;
use Crab\Contracts\Mail\Transport;
use Crab\Contracts\Plugin;
use Crab\Mail\Commands\SendTestMail;
use Crab\Mail\Transports\ArrayTransport;
use Crab\Mail\Transports\CloudflareTransport;
use Crab\Mail\Transports\LogTransport;
use Crab\Mail\Transports\SmtpTransport;
use Psr\Log\LoggerInterface;

/**
 * Binds the mailer and its transport from the "mail" configuration
 * section, and adds the `mail:test` command. Queueing mail needs the
 * queue: QueuePlugin is listed before this one.
 *
 *     transport             log, array, smtp or cloudflare; "log"
 *     from.address          who a message without a from is sent from; null refuses such a message
 *     from.name             the name shown beside it; null
 *     smtp.host             the relay, such as smtp.mx.cloudflare.net; null
 *     smtp.port             465
 *     smtp.encryption       tls (implicit, port 465), starttls (port 587) or none (localhost only); "tls"
 *     smtp.username         with password, or neither; null
 *     smtp.password         null
 *     smtp.timeout          seconds the whole exchange may take; 30
 *     smtp.local_domain     the name given in EHLO; app.url's host, else localhost, when null
 *     smtp.verify           whether the server's certificate is checked; true
 *     smtp.return_path      the MAIL FROM address, when not the message's from; null
 *     cloudflare.account_id the Cloudflare account; null
 *     cloudflare.api_token  a token with Email Sending: Edit; null
 */
final class MailPlugin implements Plugin
{
    public function register(Container $container): void
    {
        $text = ['type' => ['string', 'null'], 'minLength' => 1];
        $container->get(Config::class)->defaults(
            'mail',
            [
                'transport' => 'log',
                'from' => ['address' => null, 'name' => null],
                'smtp' => [
                    'host' => null,
                    'port' => 465,
                    'encryption' => 'tls',
                    'username' => null,
                    'password' => null,
                    'timeout' => 30,
                    'local_domain' => null,
                    'verify' => true,
                    'return_path' => null,
                ],
                'cloudflare' => ['account_id' => null, 'api_token' => null],
            ],
            [
                'transport' => ['enum' => ['log', 'array', 'smtp', 'cloudflare']],
                'from' => [
                    'type' => 'object',
                    'properties' => ['address' => $text, 'name' => $text],
                    'additionalProperties' => false,
                ],
                'smtp' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => $text,
                        'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65_535],
                        'encryption' => ['enum' => ['tls', 'starttls', 'none']],
                        'username' => $text,
                        'password' => $text,
                        'timeout' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'local_domain' => $text,
                        'verify' => ['type' => 'boolean'],
                        'return_path' => $text,
                    ],
                    'additionalProperties' => false,
                ],
                'cloudflare' => [
                    'type' => 'object',
                    'properties' => ['account_id' => $text, 'api_token' => $text],
                    'additionalProperties' => false,
                ],
            ],
        );
        $container->singleton(Transport::class, static function (Container $container): Transport {
            $config = $container->get(Config::class);
            $config->check('mail');
            return self::transport($container, $config);
        });
        // The same object by its class, for a test to look at what was sent.
        $container->singleton(ArrayTransport::class, static function (Container $container): ArrayTransport {
            $transport = $container->get(Transport::class);
            if (!$transport instanceof ArrayTransport) {
                throw new \LogicException(
                    'ArrayTransport is what mail.transport "array" sends through, and mail.transport is not array: set it to array in the tests\' configuration, as MAIL_TRANSPORT=array.',
                );
            }
            return $transport;
        });
        $container->singleton(MailerContract::class, static function (Container $container): Mailer {
            $config = $container->get(Config::class);
            $config->check('mail');
            $from = self::from($config);
            return new Mailer(
                $container->get(Transport::class),
                new Mime($container->has(Clock::class) ? $container->get(Clock::class) : null),
                $from,
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
                Mailer::queueFrom($container),
            );
        });
        $container->singleton(Mailer::class, MailerContract::class);
        if ($container->has(Console::class)) {
            $container->get(Console::class)->add('mail:test', SendTestMail::class);
        }
    }

    private static function transport(Container $container, Config $config): Transport
    {
        $chosen = $config->get('mail.transport');
        if ($chosen === 'array') {
            return new ArrayTransport();
        }
        if ($chosen === 'smtp') {
            return self::smtp($container, $config);
        }
        if ($chosen === 'cloudflare') {
            $account = $config->get('mail.cloudflare.account_id');
            $token = $config->get('mail.cloudflare.api_token');
            $problems = [];
            if (!is_string($account) || preg_match('/^[A-Za-z0-9]{1,64}$/D', $account) !== 1) {
                $problems[] =
                    'mail.cloudflare.account_id is '
                    . self::json($account)
                    . '; it takes the id of the Cloudflare account, such as "0123456789abcdef0123456789abcdef", when mail.transport is cloudflare.';
            }
            if (!is_string($token) || preg_match('/^[\x21-\x7e]{1,512}$/D', $token) !== 1) {
                $problems[] =
                    'mail.cloudflare.api_token is '
                    . (is_string($token) ? 'not a token' : 'missing')
                    . '; it takes a Cloudflare API token with the Email Sending: Edit permission, when mail.transport is cloudflare.';
            }
            if (!$container->has(Client::class)) {
                $problems[] = 'mail.transport is "cloudflare"; it needs the HTTP client: keep HttpClientPlugin among the application\'s plugins.';
            }
            self::refuse($problems);
            return new CloudflareTransport($container->get(Client::class), (string) $account, (string) $token);
        }
        return new LogTransport($container->get(LoggerInterface::class));
    }

    private static function smtp(Container $container, Config $config): SmtpTransport
    {
        $host = $config->get('mail.smtp.host');
        $encryption = (string) $config->get('mail.smtp.encryption');
        $username = $config->get('mail.smtp.username');
        $password = $config->get('mail.smtp.password');
        $returnPath = $config->get('mail.smtp.return_path');
        $problems = [];
        if (!is_string($host)) {
            $problems[] = 'mail.smtp.host is missing; it takes the SMTP server\'s host, such as "smtp.mx.cloudflare.net", when mail.transport is smtp.';
        } elseif (
            $encryption === 'none'
            && !in_array(strtolower(trim($host, '[]')), ['localhost', '::1'], true)
            && !str_starts_with($host, '127.')
        ) {
            $problems[] =
                'mail.smtp.encryption is "none" with mail.smtp.host '
                . self::json($host)
                . '; it takes "tls" or "starttls", since plain text is only for a development sink on this machine.';
        }
        if (($username === null) !== ($password === null)) {
            $problems[] = $username === null
                ? 'mail.smtp.username is missing; it takes the login name, set together with mail.smtp.password, or neither.'
                : 'mail.smtp.password is missing; it takes the password, set together with mail.smtp.username, or neither.';
        }
        if (is_string($returnPath) && filter_var($returnPath, FILTER_VALIDATE_EMAIL) === false) {
            $problems[] =
                'mail.smtp.return_path is '
                . self::json($returnPath)
                . '; it takes an email address, such as "bounces@example.com".';
        }
        self::refuse($problems);
        $local = $config->get('mail.smtp.local_domain');
        if (!is_string($local)) {
            $url = $config->get('app.url');
            $local = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
            $local = is_string($local) && $local !== '' ? $local : 'localhost';
        }
        try {
            return new SmtpTransport(
                (string) $host,
                (int) $config->get('mail.smtp.port'),
                $encryption,
                is_string($username) ? $username : null,
                is_string($password) ? $password : null,
                (float) $config->get('mail.smtp.timeout'),
                $local,
                (bool) $config->get('mail.smtp.verify'),
                is_string($returnPath) ? $returnPath : null,
                logger: $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            );
        } catch (\InvalidArgumentException $error) {
            self::refuse([$error->getMessage()]);
            throw $error;
        }
    }

    private static function from(Config $config): ?Address
    {
        $address = $config->get('mail.from.address');
        $name = $config->get('mail.from.name');
        if (!is_string($address)) {
            return null;
        }
        try {
            return new Address($address, is_string($name) ? $name : null);
        } catch (\InvalidArgumentException) {
            self::refuse([
                'mail.from.address is '
                    . self::json($address)
                    . '; it takes an email address, such as "hello@example.com", and mail.from.name one line of text.',
            ]);
            return null;
        }
    }

    /**
     * @param list<string> $problems
     * @throws MailSettingsException When there are any.
     */
    private static function refuse(array $problems): void
    {
        if ($problems !== []) {
            throw new MailSettingsException($problems);
        }
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
