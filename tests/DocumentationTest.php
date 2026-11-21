<?php

declare(strict_types=1);

namespace Crab\Mail\Tests;

use Crab\App;
use Crab\Contracts\Mail\Mailer;
use Crab\Mail\Transports\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    public function testTheReadmeBuildsTheApplicationAndReadsItsMailSettings(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        preg_match_all('/\x60{3}php\n(.*?)\n\x60{3}/s', $readme, $matches);
        $samples = [];
        foreach ($matches[1] as $sample) {
            $samples[strtok($sample, "\n")] = $sample;
        }
        $directory = sys_get_temp_dir() . '/crab-mail-docs-' . bin2hex(random_bytes(6));
        mkdir($directory);
        mkdir($directory . '/config');
        try {
            file_put_contents($directory . '/config/mail.php', "<?php\n" . $samples['// config/mail.php']);
            file_put_contents($directory . '/app.php', "<?php\n" . $samples['// app.php']);
            file_put_contents($directory . '/.env', implode("\n", [
                'MAIL_TRANSPORT=smtp',
                'MAIL_FROM_ADDRESS=hello@example.com',
                'MAIL_FROM_NAME="Example"',
                'MAIL_HOST=smtp.mx.cloudflare.net',
                'MAIL_PORT=465',
                'MAIL_ENCRYPTION=tls',
                'MAIL_USERNAME=api_token',
                'MAIL_PASSWORD=not-a-real-token',
            ])
                . "\n");

            $app = require $directory . '/app.php';

            self::assertInstanceOf(App::class, $app);
            $app->config->check();
            self::assertSame('smtp', $app->config->get('mail.transport'));
            self::assertSame(465, $app->config->get('mail.smtp.port'));
            self::assertSame('Example', $app->config->get('mail.from.name'));
            self::assertTrue($app->config->get('mail.smtp.verify'));
            self::assertInstanceOf(Mailer::class, $app->container->get(Mailer::class));
            self::assertInstanceOf(SmtpTransport::class, $app->container->get(\Crab\Contracts\Mail\Transport::class));
            self::assertTrue($app->console->has('mail:test'));
        } finally {
            foreach (['/config/mail.php', '/app.php', '/.env'] as $file) {
                @unlink($directory . $file);
            }
            @rmdir($directory . '/config');
            @rmdir($directory);
        }
    }
}
