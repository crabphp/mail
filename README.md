# Crab Mail

Email for [Crab](https://github.com/crabphp/framework) applications: sign-up verification, password resets, invitations and alerts. A message is a value object, the mailer sends it now or through the queue, and a transport chosen by configuration hands it on: SMTP, Cloudflare Email Service's API, the log, or memory in tests.

It is small on purpose. One message per send, one transport per application, no templates engine, no markdown mail and no inbound mail. It needs nothing but the framework: no Symfony Mailer, no PHPMailer.

## Installation

```sh
composer require crabphp/mail
```

List the plugin in `app.php`:

```php
// app.php
use Crab\App;
use Crab\Mail\MailPlugin;

return App::configure(__DIR__)
    ->withPlugins([new MailPlugin()])
    ->create();
```

To queue mail as well, list the framework's `QueuePlugin` before it: `->withPlugins([new QueuePlugin(), new MailPlugin()])`.

## Configuration

Copy this file to `config/mail.php`:

```php
// config/mail.php
return [
    'transport' => env('MAIL_TRANSPORT', 'log'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS'),
        'name' => env('MAIL_FROM_NAME'),
    ],
    'smtp' => [
        'host' => env('MAIL_HOST'),
        'port' => (int) env('MAIL_PORT', 465),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
    ],
    'cloudflare' => [
        'account_id' => env('MAIL_CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('MAIL_CLOUDFLARE_API_TOKEN'),
    ],
];
```

Every setting, with its default:

| Setting | Default | |
| --- | --- | --- |
| `mail.transport` | `log` | `log`, `array`, `smtp` or `cloudflare` |
| `mail.from.address` | `null` | who a message without a from is sent from; such a message is refused while it is null |
| `mail.from.name` | `null` | the name shown beside it |
| `mail.smtp.host` | `null` | the relay, such as `smtp.mx.cloudflare.net` |
| `mail.smtp.port` | `465` | |
| `mail.smtp.encryption` | `tls` | `tls` (implicit, usually port 465), `starttls` (usually 587; the server must offer it) or `none` (localhost only) |
| `mail.smtp.username`, `mail.smtp.password` | `null` | both or neither |
| `mail.smtp.timeout` | `30` | seconds the whole exchange may take |
| `mail.smtp.local_domain` | `null` | the name given in EHLO; the host of `app.url`, else `localhost` |
| `mail.smtp.verify` | `true` | whether the server's certificate is checked |
| `mail.smtp.return_path` | `null` | the MAIL FROM address, when not the message's from |
| `mail.cloudflare.account_id` | `null` | the Cloudflare account the sending domain is in |
| `mail.cloudflare.api_token` | `null` | a token with Email Sending: Edit |

Settings are checked before the application serves or works, and when the mailer is first made, in the framework's form: `mail.smtp.host is missing; it takes the SMTP server's host, ...`. A password or token is never repeated.

## Sending

Ask for `Crab\Contracts\Mail\Mailer` in the constructor of an action or a job, and send a `Message`:

```php
use Crab\Contracts\Mail\Address;
use Crab\Contracts\Mail\Mailer;
use Crab\Contracts\Mail\Message;

final readonly class SendVerification
{
    public function __construct(private Mailer $mailer) {}

    public function handle(string $email, string $url): void
    {
        $message = new Message(
            to: [new Address($email)],
            subject: 'Confirm your email',
            text: "Open this link to confirm: {$url}",
            html: "<p><a href=\"{$url}\">Confirm your email</a></p>",
        );
        $this->mailer->send($message);
    }
}
```

A message has a text body, an HTML body or both, and at least one recipient in `to`, `cc` or `bcc`. It may also have `from`, `replyTo`, `attachments` and extra `headers`. Without `from` it is sent from `mail.from`. Everything is checked when the message is made: a line break in a subject, a name or a header is refused with `InvalidArgumentException`, never sent as two headers.

```php
use Crab\Contracts\Mail\Attachment;

new Message(
    to: [new Address('ada@example.com', 'Ada Lovelace')],
    subject: 'Your invoice',
    text: 'Your invoice is attached.',
    html: '<p><img src="cid:logo"> Your invoice is attached.</p>',
    from: new Address('billing@example.com', 'Example Billing'),
    bcc: [new Address('books@example.com')],
    replyTo: [new Address('support@example.com')],
    attachments: [
        new Attachment('invoice.pdf', $pdf, 'application/pdf'),
        new Attachment('logo.png', $png, 'image/png', inline: true, contentId: 'logo'),
    ],
    headers: ['X-Invoice' => '2026-0042'],
);
```

`Address::parse('Ada Lovelace <ada@example.com>')` reads an address as people write it.

`send()` waits for the transport's answer and returns a `Sent` with the message id. Inside a worker only the fiber that sends waits; the worker goes on serving. When the message cannot be sent it throws `Crab\Contracts\Mail\NotSent`:

| | |
| --- | --- |
| `$error->permanent` | `true` when trying again cannot help: a 5xx reply, refused credentials, a sender domain the provider does not allow. `false` for a 4xx reply, a network or TLS failure, the deadline, a rate limit. |
| `$error->errorCode` | the SMTP reply code or the provider's error code, when there is one |
| `$error->reason` | the provider's words, cleaned of control characters and at most 1,000 bytes; never a password, token or body |

The mailer logs one info line per message sent, with the transport, the id and how many recipients, never an address, the subject or the body.

## Queueing

```php
$this->mailer->queue($message);
```

pushes the `Crab\Mail\SendMail` job with the message as JSON, attachments included as base64, so keep queued attachments small. A temporary failure is thrown, so the queue tries again under `queue.tries` and `queue.backoff`. A permanent one is logged as an error and the job ends: trying again would only fail again. Without `QueuePlugin` in the application, `queue()` throws a `LogicException` saying to add it before `MailPlugin`.

Like every job, it runs at least once: a worker that dies after the server accepted the message, before the job was reported done, sends it again.

## Transports

- **`log`**, the default, for development. Each message is written to the application's log at info: from and every recipient (bcc marked), the subject, the text and HTML bodies as written, and each attachment's name and size, so that a verification link can be copied out of the log. Everything a message says is in the log: do not use it where the log is kept or shared.
- **`array`**, for tests. Messages are kept in memory; see [Testing](#testing).
- **`smtp`**, to any SMTP relay, on the event loop, one connection per message. TLS is verified, the login is AUTH PLAIN or, where the server offers only that, LOGIN, a message has at most 50 recipients, and one deadline (`mail.smtp.timeout`) covers the whole exchange. It works in a request, in a job and from a console command.
- **`cloudflare`**, through Cloudflare Email Service's REST API: `POST /accounts/{account_id}/email/sending/send_raw` with the message as the other transports write it, so it is the same byte for byte. It uses the framework's HTTP client (`HttpClientPlugin`, one of the framework's plugins).

Every message is written by `Crab\Mail\Mime` as 7-bit RFC 5322 text: headers that are not ASCII as RFC 2047 encoded words, bodies as quoted-printable, attachments as base64, lines folded at 78 and never over 998. A written message over 25 MiB is refused.

Check the configuration by sending a message:

```sh
php cua mail:test --to=you@example.com
```

It prints the message id, or why it was not sent.

## Send with Cloudflare

[Cloudflare Email Service](https://developers.cloudflare.com/email-service/) sends from a domain in your Cloudflare account. Email Sending is in beta, and SMTP submission has been in beta since June 2026.

1. **Onboard the domain.** In the dashboard, add the domain you send from to Email Sending and let Cloudflare add the DNS records it asks for. A message from a domain that is not onboarded is refused (SMTP `550 5.7.1`).
2. **Create a token.** Under API Tokens, create a custom token with the account permission **Email Sending: Edit**, and nothing else. Note the account id shown on the account's overview page.
3. **Configure one of the two transports.**

Over SMTP, with implicit TLS on port 465 (Cloudflare offers no STARTTLS and no port 587). The username is the literal `api_token`:

```sh
MAIL_TRANSPORT=smtp
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="Example"
MAIL_HOST=smtp.mx.cloudflare.net
MAIL_PORT=465
MAIL_ENCRYPTION=tls
MAIL_USERNAME=api_token
MAIL_PASSWORD=your-cloudflare-api-token
```

Or over the REST API:

```sh
MAIL_TRANSPORT=cloudflare
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="Example"
MAIL_CLOUDFLARE_ACCOUNT_ID=your-account-id
MAIL_CLOUDFLARE_API_TOKEN=your-cloudflare-api-token
```

Both send the same message. The API answers with Cloudflare's own error codes, which decide temporary or permanent: 429 throttled, 500 and 503 are temporary; a schema error, a message too big, an invalid address, an unauthorized or forbidden token, a disabled or suspended account are permanent. Cloudflare limits a message to 50 recipients and 25 MiB, which the package enforces too. A recipient on the suppression list is still accepted.

## Testing

Set `MAIL_TRANSPORT=array` for the tests. Messages are kept in memory instead of sent, and the test asks the container for `ArrayTransport` to see them:

```php
use Crab\Mail\Transports\ArrayTransport;

$sent = $app->container->get(ArrayTransport::class)->last();

self::assertSame('ada@example.com', $sent['message']->to[0]->email);
self::assertStringContainsString('Subject: Confirm your email', $sent['mime']);
```

`messages()` gives every message sent, oldest first, each as the `Message` and its written MIME; `clear()` forgets them. Each worker has its own.

## Security

- **Token scope.** Give the Cloudflare token **Email Sending: Edit** and nothing else, for the one account. It can send as any onboarded domain of that account, so keep it as you keep a password: in the environment, never in the repository.
- **TLS is verified.** The SMTP transport checks the server's certificate and that it names `mail.smtp.host`. `starttls` fails when the server does not offer STARTTLS; it never falls back to plain text. `encryption: none` is refused for any host but `localhost`, `127.0.0.1` and `::1`. `verify: false` exists only for a development sink with a self-signed certificate, and logs a warning.
- **Secrets stay out of logs.** No password or token is written to a log, an exception or a settings problem; the mailer's own log line has no address, subject or body. The `log` transport is the exception: it writes whole messages, for development.
- **Header injection is refused** when a message is made, and Bcc recipients are never written into the message.

## License

MIT
