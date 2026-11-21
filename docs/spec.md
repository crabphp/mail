# crabphp/mail 0.1: spec

Written 2026-11-21 by the Claude session working on Crabsmith (`../crabsmith`), at the owner's request. You build it. When it is done, report back as described in [Done](#done).

## Vision

Sending email is something nearly every CrabPHP application needs: sign-up verification, password reset, invitations, alerts. The framework has no mailer, so apps either skip email or each write their own. `crabphp/mail` is the package that ends that. It should feel as small and obvious as Laravel's mail, but in Crab's style: a contract, a value object for the message, a few transports, and a plugin that wires it from config.

The first user is **Crabsmith** (`../crabsmith`, a server-management panel becoming a hosted SaaS). It will use the package for email verification, password reset and workspace invites. Crabsmith sends through **Cloudflare Email Service**, so Cloudflare must work on day one, over SMTP and over Cloudflare's REST API.

Keep it lean. Version 0.1 is one message per send, in memory, through one transport chosen by config. No templates engine, no markdown mail, no per-message transport switching, no connection reuse, no inbound mail. Those can come later.

## Rules

- Read `../framework/AGENTS.md`, `../framework/COMPONENTS.md` and `../framework/ARCHITECTURE.md` first. They bind this package as they bind `../runs`: contracts in `Crab\Contracts\Mail`, a `MailPlugin`, settings declared once with `Config::defaults()` and JSON Schema rules, `handle()` for jobs and commands, async I/O on the engine (`Crab\Engine`), no blocking calls in a worker, and the multiline docblock style.
- Lay the repository out like `../runs`: `composer.json` (`crabphp/mail`, MIT, PHP ^8.4, `crabphp/framework` ^0.7 through the `../framework` path repository with symlink), `contracts/`, `src/`, `tests/`, `phpunit.xml`, `mago.toml` (copy runs'), `.gitignore`, `README.md`, `CHANGELOG.md`, `AGENTS.md` (short: points at the framework's, then what is particular to mail), `LICENSE`.
- No runtime dependency beyond the framework (and the PSR interfaces it already brings). No Symfony Mailer, no PHPMailer.
- **Do not modify any sibling repository** (`../framework`, `../runs`, `../memail`, `../crabsmith` and the rest). A framework gap is worked around in this package and written down in `docs/framework-feedback.md` with a minimal reproduction.
- **Reuse ideas from `../memail`**, copy and adapt, never edit it: `app/Smtp/Client.php` and `app/Smtp/ClientConnection.php` (an SMTP client on the engine: bounded reply reading, deadlines, cancellation, STARTTLS) and `app/Mime/Builder.php` (MIME building: RFC 2047 headers, multipart, quoted-printable, base64 attachments, header-injection refusal). Memail's client delivers directly to MX servers with unverified TLS; ours submits to a relay with authentication and **verified** TLS.
- Never send to a real outside address and never contact a real mail server in tests. Tests start their own fake servers on loopback.
- Commit locally in this repository with files staged by path. Never push.
- Run long processes (a local SMTP sink, long test runs) in a visible `hc` terminal (`hc run --bg --name mail-<what> ...`), and close them when done.
- PHP 8.4 is `~/.local/bin/php`: `export PATH=$HOME/.local/bin:$PATH` first.

## What an application writes

```php
// app.php
->withPlugins([new \Crab\Mail\MailPlugin()])

// an action or a job
public function __construct(private Mailer $mailer) {}   // Crab\Contracts\Mail\Mailer

$this->mailer->send(new Message(
    to: [new Address('ada@example.com', 'Ada')],
    subject: 'Confirm your email',
    text: "Open this link to confirm: {$url}",
    html: "<p><a href=\"{$url}\">Confirm your email</a></p>",
));

// later, through the queue (QueuePlugin must be listed before MailPlugin)
$this->mailer->queue($message);
```

`from` is optional on a message; the configured default is used when it is absent.

## Contracts (`contracts/`, namespace `Crab\Contracts\Mail`)

- `Mailer`
  - `send(Message $message): Sent`: renders and sends now, through the configured transport. Throws `NotSent` on failure.
  - `queue(Message $message): int|string`: pushes the `SendMail` job with the message as JSON data. Throws `LogicException` with a clear sentence when there is no `Queue` in the container.
- `Transport`
  - `send(Message $message, string $mime): Sent`. It receives both the value object, for the envelope and for API transports that want fields, and the rendered RFC 5322 bytes.
- Value objects: final, readonly, no I/O, beside the contracts, as the framework's rules require.
  - `Address(string $email, ?string $name = null)`. Validates the address (no CR or LF, `FILTER_VALIDATE_EMAIL`, at most 254 bytes). Also offers `Address::parse('Ada <ada@example.com>')`.
  - `Attachment(string $filename, string $content, string $type = 'application/octet-stream', bool $inline = false, ?string $contentId = null)`.
  - `Message(array $to, string $subject, ?string $text = null, ?string $html = null, ?Address $from = null, array $cc = [], array $bcc = [], array $replyTo = [], array $attachments = [], array $headers = [])`.
    - At least one of `text` or `html`, and at least one recipient across to, cc and bcc.
    - `headers` holds extra headers. The name matches `[A-Za-z0-9-]+`, the name is not one the renderer owns (From, To, Cc, Bcc, Subject, Date, Message-ID, MIME-Version, Content-*), and the value contains no CR or LF.
    - Has `withFrom(Address)`, plus `toArray()` and `fromArray()` for the queue: JSON-safe, with attachment content as base64.
  - `Sent(?string $messageId, string $transport)`.
- `NotSent`, an exception (named by class, as the framework allows for exceptions): `permanent` (bool), `code` (?int, the SMTP reply code or the provider's error code), and `reason` (the provider's text, cleaned of control characters, at most 1000 bytes). **Never include a password, token or message body in it.**

## Implementation (`src/`, namespace `Crab\Mail`)

### `Mime`: renders a `Message` to RFC 5322 bytes (CRLF)

- **Headers:** From, To, Cc, Reply-To, Subject (RFC 2047 encoded-words when not plain ASCII), Date (from the framework's `Clock`), Message-ID (`<random@domain of from>`), MIME-Version, plus the message's extra headers. **Bcc never appears in the output.**
- **Body:**
  - text only: `text/plain; charset=UTF-8`, quoted-printable;
  - html only: `text/html`;
  - both: `multipart/alternative`;
  - attachments: `multipart/mixed` wrapping the body; inline attachments with a `Content-ID`: `multipart/related`;
  - attachments are base64 in lines of 76.
- Header folding at 78. No line over 998 bytes. Header injection is refused with `InvalidArgumentException`.
- A rendered message over 25 MiB is refused (Cloudflare's limit, and a sane one).

### `Mailer`: implements the contract

Fills in the default `from` when a message has none, renders it, sends it through the transport, and logs one info line: transport, message id, recipient count. **Never log addresses, the subject or the body at info level.**

### `SendMail` job

`handle(array $message)`: rebuilds the message with `Message::fromArray()` and sends it.
- A `NotSent` with `permanent` is logged and not retried. Think about how the framework marks a job failed without retrying; if it has no way, write that in framework-feedback.
- A temporary failure is thrown, so the queue retries it.

### Transports (`src/Transports/`)

- **`LogTransport`** (`log`), for local development. Writes to the app's logger at info:
  - the envelope (from and every recipient, Bcc marked as such);
  - the subject;
  - the text body as written (readable, not quoted-printable);
  - the HTML body as written;
  - attachment names and sizes.

  A developer must be able to copy a verification link out of the log. It returns a generated message id.
- **`ArrayTransport`** (`array`), for tests. Keeps every sent message and its MIME in memory. It offers `messages(): list<array{message: Message, mime: string}>`, `last()` and `clear()`. It is bound in the container as itself, so an app's test can get it and assert on what was sent.
- **`SmtpTransport`** (`smtp`), on the engine (`Engine\Network\Connector`, like memail's client). Settings:
  - `host`, `port`;
  - `encryption`: `tls` (implicit TLS, the default, port 465), `starttls` (it must be offered, otherwise fail; never fall back to plaintext), or `none` (allowed for development sinks on loopback only; refuse it for any other host);
  - `username`, `password`: both or neither;
  - `timeout` (seconds, default 30);
  - `local_domain` for EHLO (default: the host of `app.url`, else `localhost`);
  - `verify` (default true).

  Behaviour:
  - **TLS:** always verify the certificate and the peer name against `host` (`verify_peer`, `verify_peer_name`, `peer_name`). `verify: false` exists only for a self-signed development sink and is logged once at warning level.
  - **Login:** AUTH PLAIN when advertised, else LOGIN. If AUTH is needed but not advertised, fail clearly. A 535 is a permanent `NotSent` saying the credentials were refused, without repeating them.
  - **Envelope:**
    - MAIL FROM is the from address (an optional `return_path` setting overrides it);
    - one RCPT TO for every to, cc and bcc address;
    - refuse more than 50 recipients in one message (Cloudflare's per-session limit) with an `InvalidArgumentException` before connecting;
    - send `BODY=8BITMIME` only if advertised (the MIME is 7-bit anyway).
  - **DATA:** CRLF lines, dot-stuffing, the terminator. The final 250 is authoritative, and a QUIT failure after it cannot undo it. Take the message id from the 250 text when the server gives one (Cloudflare does), otherwise the Message-ID header.
  - **Failures:**
    - 4xx is a temporary `NotSent`, 5xx a permanent one;
    - connection, DNS, TLS and deadline failures are temporary;
    - one deadline covers the whole exchange, and cancellation is honoured;
    - replies are bounded, as in memail.
  - **Context:** it works inside a worker fiber (a request or a queue job) and from a console command, which runs on the main context with no fiber. Test both; `Async::await` on the loop's suspension should cover the main context.
  - One connection per send in 0.1. Reuse is for later.
- **`CloudflareTransport`** (`cloudflare`), through the framework's HTTP client (`Crab\Contracts\HttpClient\Client`). Settings: `account_id`, `api_token`.
  - Prefer `POST https://api.cloudflare.com/client/v4/accounts/{account_id}/email/sending/send_raw` with the rendered MIME, so the message is byte-for-byte what the other transports send. **Its body fields are not on the docs page.** Find them in Cloudflare's API reference (the OpenAPI schema linked from https://developers.cloudflare.com/email-service/api/send-emails/rest-api/). If you cannot pin them down, use `POST .../email/sending/send` with JSON mapped from the `Message`: `to`, `from`, `subject`, `html`, `text`, `cc`, `bcc`, `reply_to`, `headers`, and `attachments` with base64 `content`, `filename`, `type` and `disposition`. Verify each field name against the reference, and write down which endpoint you chose and why.
  - Send `Authorization: Bearer <api_token>`.
  - Success: `{"success": true, "result": {"message_id": ..., "delivered": [...], "permanent_bounces": [...], "queued": [...], "suppressed_recipients": [...]}}`. Return `Sent` with `message_id`.
  - Failure: `{"success": false, "errors": [{"code": 10001, "message": "email.sending.error.invalid_request_schema"}]}`.
    - Temporary: HTTP 429 (10004), 500 (10002, 10003), 503 (10100), and network errors.
    - Permanent: the other 4xx, such as 10001 schema, 10200 too big, 10202 invalid email, 10101 or 10103 unauthorized, 10102 forbidden, 10105 not entitled, 10203 sending disabled, 10207 suspended.
  - **Never log or put the token in an exception.**

### `MailPlugin`

Implements `Crab\Contracts\Plugin`. It declares the `mail` section with defaults and rules, just like `RunsPlugin`:

```
mail.transport           log | array | smtp | cloudflare       default log
mail.from.address        string|null                           default null (then a message without from is refused)
mail.from.name           string|null
mail.smtp.host / port / encryption / username / password / timeout / local_domain / verify / return_path
mail.cloudflare.account_id / api_token
```

- It binds `Contracts\Mail\Mailer`, with `Crab\Mail\Mailer` as an alias, and the chosen `Transport`.
- With `array`, it binds `ArrayTransport` as a singleton so tests can reach it.
- It adds the command `mail:test --to=you@example.com`, which sends a short test message through the configured transport and prints the message id or the failure. Refuse a missing or invalid `--to` before doing anything, per the framework's console rules.
- Config errors follow the framework's `key is value; it takes what.` form. **Never print a password or token value.**

Document a `config/mail.php` an app copies. It reads `MAIL_TRANSPORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_CLOUDFLARE_ACCOUNT_ID` and `MAIL_CLOUDFLARE_API_TOKEN`.

## Cloudflare facts (checked 2026-11-21; re-check the docs)

- **SMTP:**
  - host `smtp.mx.cloudflare.net`, port 465 only, implicit TLS, with no STARTTLS and no 587;
  - AUTH PLAIN (preferred) or LOGIN, username the literal string `api_token`, password a Cloudflare API token with "Email Sending: Edit";
  - 50 RCPT per session, 25 MiB, AUTH timeout 30 s, DATA timeout 300 s;
  - 550 5.7.1 usually means the from domain is not onboarded;
  - 451 4.3.0 means accepted but deferred: treat it as temporary;
  - suppressed recipients still get 250 at RCPT.

  Docs: https://developers.cloudflare.com/email-service/api/send-emails/smtp/
- **REST:** see above. Docs: https://developers.cloudflare.com/email-service/api/send-emails/rest-api/
- The sender domain must be onboarded for Email Sending in the Cloudflare account. Email Sending is in beta, and SMTP submission has been in beta since June 2026.

The README gets a "Send with Cloudflare" section: onboard the domain, create the token, then the two config variants (smtp and cloudflare).

## Tests (PHPUnit, no network)

- **Value objects:** validation, `toArray`/`fromArray` round trip including attachments, and refusal of injection in names, subjects and headers.
- **Mime:**
  - golden outputs for text, html, both, attachments and inline images, with the clock fixed and random parts made deterministic or compared structurally;
  - Bcc absent from the output;
  - RFC 2047 for non-ASCII names and subjects;
  - no line over 998.
- **SmtpTransport** against a fake SMTP server on a loopback port, written in the test with the engine's `Listener`. Each test scripts the server's replies. Cover:
  - implicit TLS with a self-signed certificate generated in the test (openssl), trusted through the `cafile` option, and also refused when not trusted while `verify` is on;
  - STARTTLS required but not offered, which fails;
  - AUTH PLAIN, and LOGIN when only LOGIN is advertised;
  - 535, which is permanent and contains no secret;
  - 4xx at RCPT, temporary; 5xx at DATA, permanent;
  - dot-stuffing of a line starting with `.`;
  - every bcc in RCPT and none in the data;
  - the 51-recipient refusal;
  - the deadline (the server stops answering);
  - the message id taken from the final 250;
  - sending from the main context as well as from inside a fiber.
- **CloudflareTransport** with the framework's `FakeClient`: the request (URL, auth header, body), success parsing, and each error class, temporary versus permanent. The token must not appear in any exception message.
- **MailPlugin:** the settings checks (a wrong value for every rule, as the framework's `SettingsTest` does), the bindings, `ArrayTransport` reachable, `mail:test` refusing a bad `--to`, and `queue()` without a queue giving a clear `LogicException`.
- **SendMail:** a queued message round-trips and is sent through `ArrayTransport`; a permanent failure is not retried.

**Real check, beyond unit tests:** send through the real `SmtpTransport` to a local SMTP sink, never a real server. Mailpit runs as a single binary from GitHub releases, with no Docker. Alternatively use Python's `aiosmtpd` if it is installed. Run it in an `hc` terminal on a high port, send with `mail:test` from a tiny throwaway app or a script, then read the message back from the sink's API or UI and check the headers and body. Use `encryption: none` on loopback, and also TLS if the sink supports it. Say exactly what was run. **Do not use a live Cloudflare token**; the owner will do the first live Cloudflare send with Crabsmith.

## Done

1. `composer test`, `composer format:check` and `composer lint` pass.
2. README, the guide for app developers: install, config, sending, queueing, testing with `array`, the Cloudflare setup, and the security notes (token scope, TLS verification).
3. `CHANGELOG.md` with 0.1.0, and `AGENTS.md`.
4. Committed locally, by path, with no push.
5. Report to the Crabsmith session with `hc send t2t2 "<report>"`, short and plain:
   - what was built;
   - what was run to prove it (commands and results);
   - anything changed from this spec and why;
   - framework gaps written to `docs/framework-feedback.md`;
   - which Cloudflare endpoint the API transport uses;
   - open questions.

   If the owner or that session is busy, the report waits in the terminal; that is fine.

If anything in this spec turns out wrong or impossible, choose the simplest correct thing, write down why in the report, and go on. Ask the owner only when blocked.
