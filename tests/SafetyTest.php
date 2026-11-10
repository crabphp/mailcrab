<?php

declare(strict_types=1);

namespace Tests;

use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Mailcrab\Delivery\SesProvider;
use Mailcrab\Worker;
use PHPUnit\Framework\TestCase;

final class SafetyTest extends TestCase
{
    public function testEnvironmentCannotUnlockRealDelivery(): void
    {
        putenv('DELIVERY_PROVIDER=ses');
        try {
            $app = require dirname(__DIR__) . '/app.php';
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Real delivery is locked');
            $app->container->get(Worker::class)->tick();
        } finally {
            putenv('DELIVERY_PROVIDER=fake');
        }
    }

    public function testSesAdapterBuildsUnsubscribeHeadersUsingOnlySdkMocks(): void
    {
        // No HTTP handler is used: the SDK's terminal command handler is a local mock.
        $mock = new MockHandler([
            new Result([
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => ['SigningEnabled' => true, 'Status' => 'SUCCESS'],
                'MailFromAttributes' => [
                    'MailFromDomainStatus' => 'SUCCESS',
                    'BehaviorOnMxFailure' => 'REJECT_MESSAGE',
                ],
            ]),
            new Result(['MessageId' => 'ses-mocked-id']),
        ]);
        $client = new SesV2Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'retries' => 3,
            'credentials' => ['key' => 'not-real', 'secret' => 'not-real'],
            'handler' => $mock,
        ]);
        $provider = new SesProvider($client, 'mock-feedback', explicitlyAuthorized: true);
        $id = $provider->send([
            'id' => 1,
            'from_email' => 'sender@example.test',
            'from_name' => 'Sender',
            'recipient' => 'reader@example.test',
            'subject' => 'Hello',
        ], [
            'text' => 'Hello',
            'html' => '<p>Hello</p>',
            'headers' => [
                'List-Unsubscribe' => '<https://example.test/u/token>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ]);
        self::assertSame('ses-mocked-id', $id);
        self::assertFalse($provider->idempotent());
        $command = $mock->getLastCommand();
        self::assertSame('SendEmail', $command->getName());
        self::assertStringContainsString(
            "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n",
            $command['Content']['Raw']['Data'],
        );
        self::assertStringContainsString('multipart/alternative', $command['Content']['Raw']['Data']);
        self::assertSame(['ToAddresses' => ['reader@example.test']], $command['Destination']);
    }

    public function testEnvironmentInitializerGeneratesSecretsPrivatelyAndNeverOverwrites(): void
    {
        $root = dirname(__DIR__) . '/storage/env-test-' . bin2hex(random_bytes(5));
        mkdir($root . '/bin', 0700, true);
        copy(dirname(__DIR__) . '/bin/init-env.php', $root . '/bin/init-env.php');
        copy(dirname(__DIR__) . '/.env.example', $root . '/.env.example');
        try {
            [$code, $output] = $this->execute([PHP_BINARY, $root . '/bin/init-env.php']);
            self::assertSame(0, $code, $output);
            $first = file_get_contents($root . '/.env');
            self::assertMatchesRegularExpression('/^APP_KEY=[a-f0-9]{64}$/m', $first);
            self::assertMatchesRegularExpression('/^FAKE_WEBHOOK_SECRET=[a-f0-9]{64}$/m', $first);
            self::assertSame(0600, fileperms($root . '/.env') & 0777);
            self::assertStringNotContainsString(explode("\n", explode('APP_KEY=', $first)[1])[0], $output);
            [$code] = $this->execute([PHP_BINARY, $root . '/bin/init-env.php']);
            self::assertSame(1, $code);
            self::assertSame($first, file_get_contents($root . '/.env'));
        } finally {
            foreach (['.env', '.env.example', 'bin/init-env.php'] as $file) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }
            rmdir($root . '/bin');
            rmdir($root);
        }
    }

    private function execute(array $command): array
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
