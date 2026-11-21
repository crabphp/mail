# Changelog

Crab Mail follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## 0.1.0 — 2026-11-21

- First release: `Contracts\Mail\Mailer` with `send()` and `queue()`, the `Message`, `Address`, `Attachment` and `Sent` value objects and the `NotSent` exception.
- `Mime` writes RFC 5322 messages: RFC 2047 headers, quoted-printable bodies, `multipart/alternative`, `related` and `mixed`, base64 attachments, header folding, no Bcc, and a 25 MiB limit.
- Transports: `log` for development, `array` for tests, `smtp` on the event loop with verified TLS (implicit or STARTTLS), AUTH PLAIN or LOGIN and one deadline, and `cloudflare` through Cloudflare Email Service's `send_raw` REST endpoint.
- `MailPlugin` declares the `mail` settings, binds the mailer and its transport, and adds `mail:test --to=you@example.com`.
- The `SendMail` job sends queued mail; a temporary failure is tried again, a permanent one is logged and not.
