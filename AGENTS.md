# Agents

The framework's [AGENTS.md](https://github.com/crabphp/framework/blob/main/AGENTS.md) holds for this package: its rules for code, comments, tests and documentation all apply. What follows is particular to mail.

## Mail

- One message per send, through the one transport `mail.transport` names. No templates, no markdown mail, no per-message transport, no connection reuse and no inbound mail until the owner asks.
- `Mime` is the one place a message is written. Every transport receives the written MIME, even one that could use fields, so that what is sent is the same everywhere. Bcc is never written; it reaches the transport only through the `Message`, for the envelope.
- A message is checked when it is made (`Message`, `Address`, `Attachment`): a line break in a subject, a name or a header is refused there, never written.
- Nothing logs an address, a subject or a body at info, except `LogTransport`, whose purpose is to. A password or token never appears in a log, an exception or a settings problem; tests assert it.
- `NotSent::$permanent` decides whether trying again can help: 5xx and a provider's refusal are permanent; 4xx, a network failure, TLS and the deadline are temporary. `SendMail` throws a temporary one for the queue to retry, and logs and ends on a permanent one (see `docs/framework-feedback.md`).
- SMTP TLS is always verified against the host. `verify: false` is for a development sink and logs a warning; `encryption: none` is refused for any host but this machine. Never fall back to plain text.
- Tests never reach a real mail server or address: SMTP tests script a `FakeSmtpServer` on loopback, Cloudflare tests use `FakeClient`. A real check uses a local sink such as Mailpit, never a live token.
- The golden MIME files in `tests/golden/` are rewritten with `MAIL_GOLDEN_WRITE=1 composer test` only after reading the change they show.
