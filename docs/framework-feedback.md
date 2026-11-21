# Framework feedback from crabphp/mail 0.1

Gaps in `crabphp/framework` 0.7 met while building this package. Each is worked around here; none of the framework was changed.

## 1. A job cannot fail for good without being tried again

A job that throws is released and tried again until `queue.tries` is spent; nothing lets a job say "this will never work, stop now". `SendMail` meets this for a permanent `NotSent` (a 5xx, refused credentials, a sender domain that is not onboarded): trying it three times sends nothing and only delays the error.

**Workaround:** `SendMail` logs the permanent failure as an error and returns normally. The job is completed, so it does not appear in `queue:failed` and `queue:retry` cannot bring it back.

**Reproduction:**

```php
final class Refused
{
    public function handle(): void
    {
        throw new \RuntimeException('550 5.7.1 sender denied'); // permanent, but retried
    }
}

$queue->push(Refused::class);
$consumer->runNext(); // released for another try in queue.backoff seconds
```

**Suggestion:** a contract exception such as `Contracts\Queue\GiveUp` (or `Unrecoverable`) that `Consumer` treats like the last try: `giveUp()` at once, so the job is kept as failed with its message and can still be retried by hand.

## 2. `Connector` reports the last address's failure, which can hide the real one

`Engine\Network\Connector::connect()` tries every address of a host and throws the failure of the last. For `localhost`, which resolves to `127.0.0.1` and `::1`, a server on `127.0.0.1` with an untrusted certificate fails TLS verification, then `::1` refuses the connection, and the caller sees only "The outbound connection was refused." The certificate problem, the thing to fix, is lost.

**Reproduction** (any TLS server on `127.0.0.1` only, with a self-signed certificate):

```php
$loop = EventLoop::current();
new Connector()->connect($loop, 'localhost', 41465, hrtime(true) / 1e9 + 5, tlsHost: 'localhost');
// RuntimeException: The outbound connection was refused.
new Connector()->connect($loop, '127.0.0.1', 41465, hrtime(true) / 1e9 + 5, tlsHost: 'localhost');
// RuntimeException: SSL operation failed with code 1. ... certificate verify failed
```

**Workaround:** none in the package; the README says to use the address the relay listens on. Cloudflare's relay is unaffected in practice.

**Suggestion:** keep the most telling failure (a TLS failure over a refused connection), or join the reasons per address.

## 3. `Connector` offers ALPN `http/1.1` on every TLS connection

`Connector::open()` sets `alpn_protocols => 'http/1.1'` for any TLS connection, though the connector is for "low-level protocols". A strict server of another protocol may refuse the handshake (`no_application_protocol`).

**Workaround:** `SmtpTransport` passes `alpn_protocols => 'smtp'` in its TLS options, which come after the default and replace it.

**Suggestion:** leave ALPN out unless the caller asks, and let the HTTP client ask for `h2,http/1.1` itself.

## 4. A package cannot raise a settings problem in the framework's form

A check between settings (the smtp transport without a host, `encryption: none` for a remote host, a username without a password) belongs where the settings are read, and should end like any other: a `Contracts\Config\InvalidSettings` printed by the console without a trace. The framework's implementation, `Config\SettingsException`, is a component class, which a package may not name.

**Workaround:** `Crab\Mail\MailSettingsException` implements `InvalidSettings` and repeats `SettingsException::say()`'s heading.

**Suggestion:** a way through the contract, such as `Config::refuse(list<string> $problems): never`, or the exception as a value object in `Contracts\Config`.
