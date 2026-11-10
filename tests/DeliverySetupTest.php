<?php

declare(strict_types=1);

namespace Tests;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Aws\Sns\SnsClient;
use Crab\App;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use GuzzleHttp\Promise\Create;
use Mailcrab\Auth;
use Mailcrab\Delivery\AwsConnection;
use Mailcrab\Delivery\DnsResolver;
use Mailcrab\Delivery\SesEvents;
use Mailcrab\Delivery\SnsCertificate;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class DeliverySetupTest extends TestCase
{
    private App $app;
    private Database $db;
    private Api $api;
    private Api $public;
    private FrozenClock $clock;
    private int $workspace;
    private string $base;
    private array $calls = [];
    private array $dns = [];
    private array $identities = [];
    private array $sets = [];
    private bool $failAws = false;
    private string $enforcement = 'HEALTHY';
    private bool $rawDelivery = false;
    private string $sendFailure = '';
    private ?\Closure $onAccount = null;
    private float $dailyLimit = 50000;
    private string $outboxDirectory;
    private static mixed $key = null;
    private static string $cert = '';

    private const TOPIC = 'arn:aws:sns:us-east-1:123456789012:mailcrab-feedback';

    protected function setUp(): void
    {
        putenv('SES_ENABLED=true');
        putenv('SES_SETUP_ENABLED=true');
        putenv('SES_LIVE_ENABLED=true'); // Test-only SDK mocks; authorization is still absent by default.
        $this->outboxDirectory = dirname(__DIR__) . '/storage/setup-test-' . bin2hex(random_bytes(5));
        putenv('MAILCRAB_OUTBOX=' . $this->outboxDirectory);
        putenv('AWS_REGION=us-east-1');
        putenv('SES_SNS_TOPIC_ARN=' . self::TOPIC);
        putenv('APP_URL=https://mailcrab.example.com');
        $this->app = require dirname(__DIR__) . '/app.php';
        $this->clock = new FrozenClock('2026-11-10T12:00:00Z');
        $this->app->container->instance(ClockInterface::class, $this->clock);
        $this->db = $this->app->container->get(Database::class);
        if (
            $this->db->driver() === 'mysql'
            && ($this->db->first('SELECT DATABASE() AS name')['name'] ?? '') !== 'mailcrab_test'
        ) {
            throw new \RuntimeException('Tests may reset only mailcrab_test.');
        }
        $this->app->container->get(Migrator::class)->fresh();
        $owner = $this->app->container->get(Auth::class)->createWorkspace(
            'Delivery test',
            'delivery',
            'owner@example.com',
            'test-password-123',
            '123 Example Street',
        );
        $this->workspace = $owner['workspace_id'];
        $this->base = '/api/v1/workspaces/' . $this->workspace;
        $this->public = new Api($this->app);
        $login = $this->public
            ->post('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'test-password-123'])
            ->assertStatus(200);
        $this->api = $this->public->withHeaders([
            'Cookie' => explode(';', $login->header('Set-Cookie'))[0],
            'X-CSRF-Token' => $login->json('csrf'),
        ]);
        $handler = function (CommandInterface $command) {
            $name = $command->getName();
            $args = $command->toArray();
            $this->calls[] = [$name, $args];
            if ($this->failAws) {
                return Create::rejectionFor(new AwsException('Secret credentials must not appear in output', $command, [
                    'code' => 'AccessDeniedException',
                ]));
            }
            if ($name === 'SendEmail' && $this->sendFailure !== '') {
                return Create::rejectionFor(
                    $this->sendFailure === 'timeout'
                        ? new \RuntimeException('Ambiguous connection loss')
                        : new AwsException('Explicit throttle rejection', $command, [
                            'code' => 'TooManyRequestsException',
                        ]),
                );
            }
            if ($name === 'GetAccount' && $this->onAccount) {
                $hook = $this->onAccount;
                $this->onAccount = null;
                $hook();
            }
            $domain = $args['EmailIdentity'] ?? '';
            $set = $args['ConfigurationSetName'] ?? '';
            $result = match ($name) {
                'GetAccount' => [
                    'ProductionAccessEnabled' => true,
                    'SendingEnabled' => true,
                    'EnforcementStatus' => $this->enforcement,
                    'SendQuota' => [
                        'Max24HourSend' => $this->dailyLimit,
                        'SentLast24Hours' => 100,
                        'MaxSendRate' => 14,
                    ],
                ],
                'GetEmailIdentity' => $this->identities[$domain] ?? null,
                'CreateEmailIdentity' => $this->identities[$domain] = $this->identity($this->workspace, $domain),
                'PutEmailIdentityMailFromAttributes' => [],
                'SendEmail' => ['MessageId' => 'ses-mocked-' . count($this->calls)],
                'GetTopicAttributes' => ['Attributes' => ['TopicArn' => self::TOPIC]],
                'GetConfigurationSet' => $this->sets[$set] ?? null,
                'CreateConfigurationSet' => $this->sets[$set] = [
                    'Tags' => $args['Tags'],
                    'SuppressionOptions' => $args['SuppressionOptions'],
                ],
                'PutConfigurationSetSuppressionOptions' => $this->sets[$set]['SuppressionOptions'] = [
                    'SuppressedReasons' => $args['SuppressedReasons'],
                ],
                'GetConfigurationSetEventDestinations' => [
                    'EventDestinations' => [[
                        'Name' => 'mailcrab-feedback',
                        'Enabled' => true,
                        'SnsDestination' => ['TopicArn' => self::TOPIC],
                        'MatchingEventTypes' => ['DELIVERY', 'BOUNCE', 'COMPLAINT'],
                    ]],
                ],
                'CreateConfigurationSetEventDestination', 'UpdateConfigurationSetEventDestination' => [],
                'Subscribe' => ['SubscriptionArn' => 'pending confirmation'],
                'ConfirmSubscription' => ['SubscriptionArn' => self::TOPIC . ':subscription-id'],
                'GetSubscriptionAttributes' => ['Attributes' => [
                    'TopicArn' => self::TOPIC,
                    'Endpoint' => 'https://mailcrab.example.com/webhooks/ses/' . $this->workspace,
                    'Protocol' => 'https',
                    'PendingConfirmation' => 'false',
                    'RawMessageDelivery' => $this->rawDelivery ? 'true' : 'false',
                ]],
                default => throw new \RuntimeException('Unexpected AWS action ' . $name),
            };
            return $result === null ? Create::rejectionFor(new AwsException('Not found', $command, [
                    'code' => 'NotFoundException',
                ])) : Create::promiseFor(new Result($result));
        };
        $options = [
            'region' => 'us-east-1',
            'version' => 'latest',
            'retries' => 0,
            'credentials' => ['key' => 'not-real', 'secret' => 'not-real'],
            'handler' => $handler,
        ];
        $this->app->container->instance(
            AwsConnection::class,
            new AwsConnection(new SesV2Client($options), new SnsClient($options)),
        );
        $resolver = new class extends DnsResolver {
            public array $values = [];

            public function records(string $host, string $type): array
            {
                return $this->values[$type . ':' . $host] ?? [];
            }
        };
        $this->dns = &$resolver->values;
        $this->app->container->instance(DnsResolver::class, $resolver);
        if (self::$key === null) {
            self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], self::$key, [
                'digest_alg' => 'sha256',
            ]);
            $cert = openssl_csr_sign($csr, null, self::$key, 2, ['digest_alg' => 'sha256']);
            openssl_x509_export($cert, self::$cert);
        }
        $fetcher = new class extends SnsCertificate {
            public string $certificate;

            public function fetch(string $url): string
            {
                return $this->certificate;
            }
        };
        $fetcher->certificate = self::$cert;
        $this->app->container->instance(SnsCertificate::class, $fetcher);
    }

    protected function tearDown(): void
    {
        if (isset($this->outboxDirectory) && is_dir($this->outboxDirectory)) {
            foreach (glob($this->outboxDirectory . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->outboxDirectory);
        }
        foreach ([
            'SES_ENABLED',
            'SES_SETUP_ENABLED',
            'SES_LIVE_ENABLED',
            'MAILCRAB_OUTBOX',
            'AWS_REGION',
            'SES_SNS_TOPIC_ARN',
            'APP_URL',
        ] as $key) {
            putenv($key);
        }
    }

    private function identity(int $workspace, string $domain = 'news.example.com'): array
    {
        return [
            'VerifiedForSendingStatus' => true,
            'VerificationStatus' => 'SUCCESS',
            'IdentityType' => 'DOMAIN',
            'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $workspace]],
            'DkimAttributes' => [
                'Tokens' => ['token1', 'token2', 'token3'],
                'Status' => 'SUCCESS',
                'SigningEnabled' => true,
            ],
            'MailFromAttributes' => [
                'MailFromDomain' => 'bounce.' . $domain,
                'MailFromDomainStatus' => 'SUCCESS',
                'BehaviorOnMxFailure' => 'REJECT_MESSAGE',
            ],
        ];
    }

    private function domain(): int
    {
        return (int) $this->api
            ->post($this->base . '/delivery/domains', ['domain' => 'news.example.com'])
            ->assertStatus(201)
            ->json('id');
    }

    private function verifiedDomain(): int
    {
        $id = $this->domain();
        $domain = 'news.example.com';
        $this->identities[$domain] = $this->identity($this->workspace);
        foreach (['token1', 'token2', 'token3'] as $token) {
            $this->dns['CNAME:' . $token . '._domainkey.' . $domain] = [$token . '.dkim.amazonses.com'];
        }
        $this->dns['MX:bounce.' . $domain] = ['10 feedback-smtp.us-east-1.amazonses.com'];
        $this->dns['TXT:bounce.' . $domain] = ['v=spf1 include:amazonses.com -all'];
        $this->dns['TXT:_dmarc.' . $domain] = ['v=DMARC1; p=reject; rua=mailto:dmarc@example.com'];
        $this->api->post($this->base . "/delivery/domains/{$id}/connect", ['authorize_aws' => true])->assertStatus(200);
        return $id;
    }

    private function readySender(): int
    {
        $this->verifiedDomain();
        $this->api->post($this->base . '/delivery/connection/check', [])->assertStatus(200);
        $this->sets['mailcrab-workspace-' . $this->workspace] = [
            'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $this->workspace]],
            'SuppressionOptions' => ['SuppressedReasons' => ['BOUNCE', 'COMPLAINT']],
        ];
        $this->db
            ->table('delivery_settings')
            ->where('workspace_id', $this->workspace)
            ->update(['subscription_arn' => self::TOPIC . ':subscription-id']);
        $this->api->post($this->base . '/delivery/feedback/check', [])->assertStatus(200);
        $sender = (int) $this->api
            ->post($this->base . '/senders', [
                'name' => 'SES test team',
                'email' => 'hello@news.example.com',
                'reply_to' => 'replies@example.com',
            ])
            ->assertStatus(201)
            ->json('id');
        $this->api
            ->get($this->base . '/delivery')
            ->assertJson('ready', true)
            ->assertJson('real_sending', false);
        return $sender;
    }

    private function authorize(bool $revoke = false): void
    {
        $input = new \Crab\Console\Input([
            '--workspace=' . $this->workspace,
            '--confirm=delivery',
            '--reason=Automated SDK mock test only',
            ...($revoke ? ['--revoke'] : []),
        ]);
        $stream = fopen('php://memory', 'w+');
        try {
            self::assertSame(0, $this->app->container->get(\Mailcrab\Console\AuthorizeSes::class)->handle(
                $input,
                new \Crab\Console\Output($stream, $stream),
            ));
        } finally {
            fclose($stream);
        }
    }

    private function unpause(): void
    {
        $row = $this->db->table('delivery_settings')->where('workspace_id', $this->workspace)->first();
        $this->api->put($this->base . '/delivery/preferences', [
            'default_sender_id' => $row['default_sender_id'],
            'paused' => false,
            'rate_per_second' => 1,
        ])->assertStatus(200);
    }

    private function testCampaign(int $sender): int
    {
        return (int) $this->api
            ->post($this->base . '/campaigns', [
                'sender_id' => $sender,
                'subject' => 'Mock-only campaign',
                'body' => 'No real mail is sent.',
            ])
            ->assertStatus(201)
            ->json('id');
    }

    private function queueSesTest(int $campaign, string $key): int
    {
        return (int) $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                ['confirm_live' => true],
                headers: ['Idempotency-Key' => $key],
            )
            ->assertStatus(200)
            ->json('delivery_id');
    }

    private function signed(array $payload = [], string $type = 'Notification', array $overrides = []): string
    {
        $message = $overrides
        + [
            'Type' => $type,
            'TopicArn' => self::TOPIC,
            'MessageId' => 'sns-' . bin2hex(random_bytes(6)),
            'Message' => json_encode($payload, JSON_THROW_ON_ERROR),
            'Timestamp' => '2026-11-10T12:00:00Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem',
            'Signature' => 'pending',
        ];
        if ($type !== 'Notification') {
            $message += ['Token' => 'signed-subscription-token', 'SubscribeURL' => 'https://evil.example/never-fetch'];
        }
        $signable = new MessageValidator()->getStringToSign(new Message($message));
        openssl_sign($signable, $signature, self::$key, OPENSSL_ALGO_SHA256);
        $message['Signature'] = base64_encode($signature);
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    private function eventPayload(string $kind, array $extra = []): array
    {
        return $extra
        + [
            'eventType' => $kind,
            'mail' => [
                'messageId' => 'ses-message-1',
                'destination' => ['reader@example.com'],
                'tags' => ['ses:configuration-set' => ['mailcrab-workspace-' . $this->workspace]],
            ],
            'delivery' => ['recipients' => ['reader@example.com']],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bouncedRecipients' => [['emailAddress' => 'reader@example.com']],
            ],
            'complaint' => ['complainedRecipients' => [['emailAddress' => 'reader@example.com']]],
        ];
    }

    private function delivery(?string $provider = 'ses-message-1'): int
    {
        $subscriber = $this->db
            ->table('subscribers')
            ->insert([
                'workspace_id' => $this->workspace,
                'email' => 'reader@example.com',
                'status' => 'active',
                'confirmed_at' => '2026-10-01 00:00:00',
            ]);
        return (int) $this->db
            ->table('deliveries')
            ->insert([
                'workspace_id' => $this->workspace,
                'subscriber_id' => $subscriber,
                'kind' => 'campaign',
                'transport' => 'ses',
                'dedupe_key' => 'ses-test-delivery',
                'token' => bin2hex(random_bytes(32)),
                'recipient' => 'reader@example.com',
                'from_email' => 'hello@news.example.com',
                'from_name' => 'Hello',
                'subject' => 'Test',
                'body' => 'Test',
                'postal_address' => 'Test',
                'provider_id' => $provider,
                'status' => $provider ? 'accepted' : 'sending',
                'available_at' => '2026-11-10 12:00:00',
                'created_at' => '2026-11-10 12:00:00',
            ]);
    }

    private function webhook(string $body): \Crab\Testing\ApiResponse
    {
        return $this->public->post('/webhooks/ses/' . $this->workspace, body: $body, headers: [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function testSettingsReadDoesNotContactAwsOrExposeCredentials(): void
    {
        $view = $this->api->get($this->base . '/delivery')->assertStatus(200);
        $view->assertJson('real_sending', false)->assertJson('ready', false)->assertJson('domains', []);
        self::assertSame([], $this->calls);
        self::assertStringNotContainsString('not-real', $view->body());
        self::assertArrayNotHasKey('subscription_token', $view->json());
    }

    public function testConnectionIsReadOnlyAndErrorsAreRedacted(): void
    {
        $this->api
            ->post($this->base . '/delivery/connection/check', [])
            ->assertStatus(200)
            ->assertJson('account.daily_limit', 50000)
            ->assertJson('account.production', true);
        self::assertSame(['GetAccount'], array_column($this->calls, 0));
        $this->failAws = true;
        $response = $this->api->post($this->base . '/delivery/connection/check', [])->assertStatus(422);
        self::assertStringNotContainsString('Secret credentials', $response->body());
        $this->api
            ->get($this->base . '/delivery')
            ->assertJson('account', null)
            ->assertJson('ready', false);
    }

    public function testLocalDomainsNormalizeAndNeverProvisionWithoutConsent(): void
    {
        $id = (int) $this->api
            ->post($this->base . '/delivery/domains', ['domain' => ' News.Example.Com '])
            ->assertStatus(201)
            ->json('id');
        self::assertSame([], $this->calls);
        $this->api->post($this->base . "/delivery/domains/{$id}/connect", [])->assertStatus(422);
        self::assertSame([], $this->calls);
        foreach ([
            'https://example.com',
            'bad@example.com',
            '127.0.0.1',
            'localhost',
            '*.example.com',
            'example.com/path',
        ] as $domain) {
            $this->api->post($this->base . '/delivery/domains', ['domain' => $domain])->assertStatus(422);
        }
        $same = $this->api
            ->post($this->base . '/delivery/domains', ['domain' => 'news.example.com'])
            ->assertStatus(201)
            ->json('id');
        self::assertSame($id, (int) $same);
    }

    public function testDomainRegistrationAndDnsPoliciesPreserveExistingValues(): void
    {
        $id = $this->verifiedDomain();
        $view = $this->api->get($this->base . '/delivery')->assertJson('domains.0.status', 'verified');
        self::assertSame('v=DMARC1; p=reject; rua=mailto:dmarc@example.com', $view->json('domains.0.dns.5.value'));
        self::assertSame('v=spf1 include:amazonses.com -all', $view->json('domains.0.dns.4.value'));
        self::assertSame(10, $view->json('domains.0.dns.3.priority'));
        $this->api->post($this->base . "/delivery/domains/{$id}/disconnect", [])->assertStatus(200);
        $this->api->post($this->base . "/delivery/domains/{$id}/check", [])->assertStatus(409);
    }

    public function testNewIdentityUsesWorkspaceTagAndRejectOnMxFailure(): void
    {
        $id = $this->domain();
        $this->api
            ->post($this->base . "/delivery/domains/{$id}/connect", ['authorize_aws' => true])
            ->assertStatus(200)
            ->assertJson('domains.0.status', 'pending');
        $calls = array_column($this->calls, 1, 0);
        self::assertSame(
            [['Key' => 'mailcrab_workspace', 'Value' => (string) $this->workspace]],
            $calls['CreateEmailIdentity']['Tags'],
        );
        self::assertSame('REJECT_MESSAGE', $calls['PutEmailIdentityMailFromAttributes']['BehaviorOnMxFailure']);
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
    }

    public function testExistingForeignAwsIdentityCannotBeAdopted(): void
    {
        $id = $this->domain();
        $this->identities['news.example.com'] = $this->identity(999);
        $this->api->post($this->base . "/delivery/domains/{$id}/connect", ['authorize_aws' => true])->assertStatus(422);
        self::assertNotContains('PutEmailIdentityMailFromAttributes', array_column($this->calls, 0));
        $this->api->get($this->base . '/delivery')->assertJson('domains.0.status', 'error');
    }

    public function testWorkspaceIsolationForSettingsDomainAndPreferences(): void
    {
        $id = $this->domain();
        $other = $this->app->container->get(Auth::class)->createWorkspace(
            'Other',
            'other',
            'other@example.com',
            'test-password-123',
            'Other',
        );
        $this->api->get('/api/v1/workspaces/' . $other['workspace_id'] . '/delivery')->assertStatus(404);
        $login = $this->public
            ->post('/api/v1/auth/login', ['email' => 'other@example.com', 'password' => 'test-password-123'])
            ->assertStatus(200);
        $client = $this->public->withHeaders([
            'Cookie' => explode(';', $login->header('Set-Cookie'))[0],
            'X-CSRF-Token' => $login->json('csrf'),
        ]);
        $base = '/api/v1/workspaces/' . $other['workspace_id'];
        $client->post($base . "/delivery/domains/{$id}/connect", ['authorize_aws' => true])->assertStatus(404);
        $client->post($base . '/delivery/domains', ['domain' => 'news.example.com'])->assertStatus(409);
        self::assertSame([], $this->calls);
    }

    public function testVerifiedDomainSenderAndReplyToDefaultAreScoped(): void
    {
        $this->verifiedDomain();
        $sender = $this->api
            ->post($this->base . '/senders', [
                'name' => 'Team',
                'email' => 'hello@news.example.com',
                'reply_to' => 'reply@example.com',
            ])
            ->assertStatus(201)
            ->json();
        self::assertNotNull($sender['verified_at']);
        self::assertSame(0, $this->db->table('deliveries')->count());
        $this->api
            ->put($this->base . '/delivery/preferences', [
                'default_sender_id' => $sender['id'],
                'rate_per_second' => 3,
                'paused' => true,
                'postal_address' => 'New postal address',
            ])
            ->assertStatus(200)
            ->assertJson('postal_address', 'New postal address')
            ->assertJson('senders.0.ses_ready', true);
        $this->api->put($this->base . '/senders/' . $sender['id'], [
            'name' => 'Team',
            'reply_to' => "bad\r\nBcc: victim@example.com",
        ])->assertStatus(422);
        $this->api->put($this->base . '/delivery/preferences', [
            'rate_per_second' => 101,
            'paused' => true,
        ])->assertStatus(422);
    }

    public function testFeedbackRequiresConsentAndRepairsOwnedConfigurationSets(): void
    {
        $name = 'mailcrab-workspace-' . $this->workspace;
        $this->sets[$name] = [
            'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $this->workspace]],
            'SuppressionOptions' => [],
        ];
        $this->api->post($this->base . '/delivery/feedback/connect', [])->assertStatus(422);
        self::assertSame([], $this->calls);
        $this->api
            ->post($this->base . '/delivery/feedback/connect', ['authorize_aws' => true])
            ->assertStatus(200)
            ->assertJson('subscription_requested', true);
        self::assertContains('PutConfigurationSetSuppressionOptions', array_column($this->calls, 0));
        self::assertSame(['BOUNCE', 'COMPLAINT'], $this->sets[$name]['SuppressionOptions']['SuppressedReasons']);
    }

    public function testSnsConfirmationIsSignedStoredNotAutoConfirmedAndDoesNotFetchSubscribeUrl(): void
    {
        $body = $this->signed([], 'SubscriptionConfirmation');
        $this->webhook($body)->assertStatus(202);
        self::assertSame([], $this->calls);
        $this->api->get($this->base . '/delivery')->assertJson('subscription_pending', true);
        $this->sets['mailcrab-workspace-' . $this->workspace] = [
            'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $this->workspace]],
            'SuppressionOptions' => ['SuppressedReasons' => ['BOUNCE', 'COMPLAINT']],
        ];
        $this->api
            ->post($this->base . '/delivery/feedback/confirm', ['authorize_aws' => true])
            ->assertStatus(200)
            ->assertJson('feedback.ready', true)
            ->assertJson('subscription_pending', false);
        self::assertSame('ConfirmSubscription', $this->calls[0][0]);
        $this->webhook($body)->assertStatus(202);
        $this->api->get($this->base . '/delivery')->assertJson('subscription_pending', false);
        self::assertSame(1, $this->db->table('ses_event_inbox')->count());
    }

    public function testSnsRejectsForgedSignaturesWrongTopicsAndCertificateSsrf(): void
    {
        $signed = $this->signed($this->eventPayload('Delivery'));
        $bad = json_decode($signed, true);
        $bad['Message'] .= 'tampered';
        $this->webhook(json_encode($bad))->assertStatus(401);
        $this->webhook($this->signed([], overrides: [
            'TopicArn' => 'arn:aws:sns:us-east-1:999999999999:other',
        ]))->assertStatus(401);
        foreach ([
            'http://127.0.0.1/private.pem',
            'https://sns.us-east-1.amazonaws.com.evil.example/SimpleNotificationService-x.pem',
            'https://sns.us-east-1.amazonaws.com:443/SimpleNotificationService-x.pem',
            'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem?redirect=1',
        ] as $url) {
            $this->webhook($this->signed([], overrides: ['SigningCertURL' => $url]))->assertStatus(401);
        }
        self::assertSame(0, $this->db->table('ses_event_inbox')->count());
    }

    public function testSesEventsDeduplicateAndSuppressPermanentBounces(): void
    {
        $id = $this->delivery();
        $body = $this->signed($this->eventPayload('Bounce'));
        $this->webhook($body)->assertStatus(202);
        $this->webhook($body)->assertStatus(202);
        self::assertSame('bounced', $this->db->table('deliveries')->where('id', $id)->first()['status']);
        self::assertSame(1, $this->db->table('suppressions')->count());
        self::assertSame(1, $this->db->table('delivery_events')->count());
        self::assertSame('processed', $this->db->table('ses_event_inbox')->first()['status']);
    }

    public function testTransientBouncesDoNotSuppressAndRecipientMismatchIsIgnored(): void
    {
        $this->delivery();
        $payload = $this->eventPayload('Bounce');
        $payload['bounce']['bounceType'] = 'Transient';
        $this->webhook($this->signed($payload))->assertStatus(202);
        self::assertSame(0, $this->db->table('suppressions')->count());
        $payload = $this->eventPayload('Complaint');
        $payload['complaint']['complainedRecipients'][0]['emailAddress'] = 'someoneelse@example.com';
        $this->webhook($this->signed($payload))->assertStatus(202);
        self::assertSame(0, $this->db->table('suppressions')->count());
        self::assertSame(1, $this->db->table('ses_event_inbox')->where('status', 'ignored')->count());
    }

    public function testFeedbackBeforeAcceptanceReconcilesOnlySignedTagsAndRecipient(): void
    {
        $id = $this->delivery(null);
        $payload = $this->eventPayload('Delivery');
        $payload['mail']['tags']['mailcrab_workspace'] = [(string) $this->workspace];
        $payload['mail']['tags']['mailcrab_delivery'] = [(string) $id];
        $this->webhook($this->signed($payload))->assertStatus(202);
        self::assertSame('delivered', $this->db->table('deliveries')->where('id', $id)->first()['status']);
        self::assertSame('ses-message-1', $this->db->table('deliveries')->where('id', $id)->first()['provider_id']);
        self::assertSame(0, $this->db->table('ses_event_inbox')->where('status', 'pending')->count());
    }

    public function testUnmatchedAndWrongConfigurationEventsAreBounded(): void
    {
        $payload = $this->eventPayload('Delivery');
        $this->webhook($this->signed($payload))->assertStatus(202);
        for ($i = 0; $i < 13; ++$i) {
            $this->clock->advance('+2 hours');
            $this->app->container->get(SesEvents::class)->process(2);
        }
        self::assertSame('unmatched', $this->db->table('ses_event_inbox')->first()['status']);
        $payload['mail']['tags']['ses:configuration-set'] = ['other-app'];
        $this->webhook($this->signed($payload))->assertStatus(202);
        self::assertSame(1, $this->db->table('ses_event_inbox')->count());
        self::assertSame([], $this->calls);
    }

    public function testFeedbackExplainsDisabledConfigurationAndRawDelivery(): void
    {
        $this->sets['mailcrab-workspace-' . $this->workspace] = [
            'Tags' => [['Key' => 'mailcrab_workspace', 'Value' => (string) $this->workspace]],
            'SuppressionOptions' => ['SuppressedReasons' => ['BOUNCE', 'COMPLAINT']],
            'SendingOptions' => ['SendingEnabled' => false],
        ];
        $this->api->get($this->base . '/delivery')->assertStatus(200);
        $this->db
            ->table('delivery_settings')
            ->where('workspace_id', $this->workspace)
            ->update(['subscription_arn' => self::TOPIC . ':subscription-id']);
        $this->rawDelivery = true;
        $this->api
            ->post($this->base . '/delivery/feedback/check', [])
            ->assertStatus(200)
            ->assertJson('feedback.configuration_sending_enabled', false)
            ->assertJson('feedback.subscription_problem', 'raw_delivery_enabled')
            ->assertJson('feedback.ready', false);
    }

    public function testTemporaryCertificateFailureReturnsRetryableServiceStatus(): void
    {
        $this->app->container->instance(SnsCertificate::class, new class extends SnsCertificate {
            public function fetch(string $url): string
            {
                throw new \Mailcrab\Delivery\SnsCertificateUnavailable('Network timeout');
            }
        });
        $this->webhook($this->signed($this->eventPayload('Delivery')))->assertStatus(503);
        self::assertSame(0, $this->db->table('ses_event_inbox')->count());
    }

    public function testCloudMutationsStayLockedWithoutOperatorEnablement(): void
    {
        putenv('SES_SETUP_ENABLED=false');
        $app = require dirname(__DIR__) . '/app.php';
        // Guard fails before any SDK/credential-chain access, even with browser acknowledgement.
        $this->expectException(\Crab\Http\HttpException::class);
        $this->expectExceptionMessage('operator must enable SES_SETUP_ENABLED');
        $app->container->get(AwsConnection::class)->authorize(['authorize_aws' => true]);
    }

    public function testSesRuntimeRequiresCliAuthorizationAndExplicitPerSendAcknowledgement(): void
    {
        $sender = $this->readySender();
        $campaign = $this->testCampaign($sender);
        $fake = $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                [],
                headers: ['Idempotency-Key' => 'fake-before-authorize-0001'],
            )
            ->assertStatus(200)
            ->json('delivery_id');
        self::assertSame('fake', $this->db->table('deliveries')->where('id', $fake)->first()['transport']);
        $this->authorize();
        $this->api
            ->get($this->base . '/delivery')
            ->assertJson('real_sending', true)
            ->assertJson('paused', true);
        $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                [],
                headers: ['Idempotency-Key' => 'live-requires-confirm-0001'],
            )
            ->assertStatus(422);
        $this->api->post($this->base . "/campaigns/{$campaign}/schedule", [
            'scheduled_at' => '2026-11-10T12:00:00Z',
        ])->assertStatus(422);
        $this->api->put($this->base . '/welcome', [
            'sender_id' => $sender,
            'subject' => 'Hello',
            'body' => 'Hello',
            'enabled' => true,
        ])->assertStatus(422);
        $this->api->get('/api/v1/auth/me')->assertJson('workspaces.0.delivery_mode', 'ses');
        self::assertSame('fake', $this->db->table('deliveries')->where('id', $fake)->first()['transport']);
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
    }

    public function testSesRuntimePauseDefersWithoutUsingAttemptThenUsesMockedTransport(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $campaign = $this->testCampaign($sender);
        $delivery = $this->queueSesTest($campaign, 'mock-pause-request-0001');
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        $row = $this->db->table('deliveries')->where('id', $delivery)->first();
        self::assertSame('queued', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
        $this->unpause();
        $this->clock->advance('+61 seconds');
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        $row = $this->db->table('deliveries')->where('id', $delivery)->first();
        self::assertSame('accepted', $row['status']);
        self::assertSame('ses', $row['transport']);
        self::assertSame(1, (int) $row['attempts']);
        $sends = array_values(array_filter($this->calls, static fn($call): bool => $call[0] === 'SendEmail'));
        self::assertCount(1, $sends);
        self::assertSame(['ToAddresses' => ['owner@example.com']], $sends[0][1]['Destination']);
        self::assertStringContainsString('Reply-To: replies@example.com', $sends[0][1]['Content']['Raw']['Data']);
        self::assertSame(1, $this->db->table('ses_send_reservations')->count());
    }

    public function testSesRuntimeSharedRateSlotsDeferRatherThanDropMessages(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $campaign = $this->testCampaign($sender);
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->queueSesTest($campaign, 'mock-rate-request-000' . $i);
        }
        $worker = $this->app->container->get(\Mailcrab\Worker::class);
        $worker->tick();
        self::assertSame(1, $this->db->table('deliveries')->where('status', 'accepted')->count());
        self::assertSame(2, $this->db->table('deliveries')->where('status', 'queued')->where('attempts', 0)->count());
        $this->clock->advance('+1 second');
        $worker->tick();
        $this->clock->advance('+1 second');
        $worker->tick();
        self::assertSame(3, $this->db->table('deliveries')->where('status', 'accepted')->count());
        self::assertSame(3, $this->db->table('ses_send_reservations')->count());
    }

    public function testSesRuntimeQuotaAndRevocationNeverFallbackToFake(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $campaign = $this->testCampaign($sender);
        $first = $this->queueSesTest($campaign, 'mock-quota-request-0001');
        $second = $this->queueSesTest($campaign, 'mock-quota-request-0002');
        $this->dailyLimit = 101;
        $worker = $this->app->container->get(\Mailcrab\Worker::class);
        $worker->tick();
        $this->clock->advance('+1 second');
        $worker->tick();
        self::assertSame('accepted', $this->db->table('deliveries')->where('id', $first)->first()['status']);
        self::assertSame('queued', $this->db->table('deliveries')->where('id', $second)->first()['status']);
        self::assertSame(0, (int) $this->db->table('deliveries')->where('id', $second)->first()['attempts']);
        $this->authorize(revoke: true);
        $this->clock->advance('+61 seconds');
        $worker->tick();
        self::assertSame('ses', $this->db->table('deliveries')->where('id', $second)->first()['transport']);
        self::assertSame('queued', $this->db->table('deliveries')->where('id', $second)->first()['status']);
        self::assertFalse(is_dir($this->outboxDirectory));
    }

    public function testSesRuntimeAmbiguousOutcomesNeverAutomaticallyRetry(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $campaign = $this->testCampaign($sender);
        $id = $this->queueSesTest($campaign, 'mock-unknown-request-0001');
        $this->sendFailure = 'timeout';
        $worker = $this->app->container->get(\Mailcrab\Worker::class);
        $worker->tick();
        $this->clock->advance('+120 seconds');
        $worker->tick();
        $row = $this->db->table('deliveries')->where('id', $id)->first();
        self::assertSame('unknown', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertCount(1, array_filter($this->calls, static fn($call): bool => $call[0] === 'SendEmail'));
    }

    public function testSesRuntimeOldFakeCampaignAndWelcomeStayFakeAfterAuthorization(): void
    {
        $sender = $this->readySender();
        $campaign = $this->testCampaign($sender);
        $this->api
            ->post($this->base . "/campaigns/{$campaign}/schedule", ['scheduled_at' => '2026-11-10T12:00:00Z'])
            ->assertStatus(200)
            ->assertJson('transport', 'fake');
        $this->api
            ->put($this->base . '/welcome', [
                'sender_id' => $sender,
                'subject' => 'Welcome',
                'body' => 'Hello',
                'enabled' => true,
            ])
            ->assertStatus(200)
            ->assertJson('transport', 'fake');
        $this->api->post($this->base . '/subscribers', [
            'email' => 'reader@example.com',
            'consent' => true,
            'consent_at' => '2026-10-01T00:00:00Z',
            'source' => 'First-party signup',
            'evidence' => 'Original checkbox permission',
        ])->assertStatus(201);
        // The subscriber was added after scheduling, so create one eligible pre-schedule row via a new draft.
        $campaign = $this->testCampaign($sender);
        $this->api->post($this->base . "/campaigns/{$campaign}/schedule", [
            'scheduled_at' => '2026-11-10T12:00:00Z',
        ])->assertStatus(200);
        $this->authorize();
        $this->unpause();
        for ($i = 0; $i < 2; ++$i) {
            $this->app->container->get(\Mailcrab\Worker::class)->tick();
        }
        self::assertSame('fake', $this->db->table('deliveries')->where('campaign_id', $campaign)->first()['transport']);
        self::assertSame('fake', $this->db->table('welcome_sequences')->first()['transport']);
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
    }

    public function testSesRuntimeConfirmationUsesDefaultSenderAndRedactsLiveTokens(): void
    {
        $this->readySender();
        $this->authorize();
        $this->public
            ->post('/subscribe/delivery', form: ['email' => 'signup@example.com', 'consent' => 'yes'])
            ->assertStatus(200);
        $row = $this->db->table('deliveries')->where('kind', 'confirmation')->first();
        self::assertSame('ses', $row['transport']);
        self::assertSame('hello@news.example.com', $row['from_email']);
        self::assertStringContainsString('/confirm/', $row['body']);
        $response = $this->api->get($this->base . '/outbox')->assertStatus(200);
        self::assertStringNotContainsString('/confirm/', $response->body());
        self::assertStringContainsString('not exposed in live activity', $response->json('data.0.body'));
    }

    public function testLiveConfirmationChecksAlsoRunInsideLockedCampaignTransactions(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $campaign = $this->testCampaign($sender);
        $service = $this->app->container->get(\Mailcrab\Campaigns::class);
        $user = (int) $this->db->table('users')->first()['id'];
        foreach ([
            fn() => $service->test($this->workspace, $campaign, $user, 'direct-test-request-0001'),
            fn() => $service->schedule($this->workspace, $campaign, ['scheduled_at' => '2026-11-10T12:00:00Z']),
            fn() => $service->welcome($this->workspace, [
                'sender_id' => $sender,
                'subject' => 'Hi',
                'body' => 'Hi',
                'enabled' => true,
            ]),
        ] as $call) {
            try {
                $call();
                self::fail('Unacknowledged SES operation was allowed.');
            } catch (\Crab\Http\HttpException $error) {
                self::assertStringContainsString('Explicitly confirm', $error->getMessage());
            }
        }
        self::assertSame(0, $this->db->table('deliveries')->count());
        self::assertSame('draft', $this->db->table('campaigns')->where('id', $campaign)->first()['status']);
    }

    public function testSesPreflightRechecksLocalRevocationAndDoesNotReserveQuota(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $campaign = $this->testCampaign($sender);
        $delivery = $this->queueSesTest($campaign, 'mock-revoke-race-0001');
        $this->onAccount = fn() => $this->api->post($this->base . "/senders/{$sender}/revoke", [])->assertStatus(200);
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        $row = $this->db->table('deliveries')->where('id', $delivery)->first();
        self::assertSame('queued', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame(0, $this->db->table('ses_send_reservations')->count());
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
    }

    public function testIdentityFailureNeverConsumesSharedQuota(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $campaign = $this->testCampaign($sender);
        $id = $this->queueSesTest($campaign, 'mock-identity-failure-0001');
        $this->identities['news.example.com']['DkimAttributes']['Status'] = 'FAILED';
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        self::assertSame(0, $this->db->table('ses_send_reservations')->count());
        self::assertSame(0, (int) $this->db->table('deliveries')->where('id', $id)->first()['attempts']);
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
    }

    public function testFakeConsentCannotTurnIntoSesMarketingAndLiveReconfirmationIsRequired(): void
    {
        $sender = $this->readySender();
        $this->public
            ->post('/subscribe/delivery', form: ['email' => 'simulation@example.com', 'consent' => 'yes'])
            ->assertStatus(200);
        $fake = $this->db->table('deliveries')->where('kind', 'confirmation')->first();
        preg_match('~/confirm/([a-f0-9]{64})~', $fake['body'], $token);
        $this->public->post('/confirm/' . $token[1], form: [])->assertStatus(200);
        $this->public
            ->post('/subscribe/delivery', form: ['email' => 'pending@example.com', 'consent' => 'yes'])
            ->assertStatus(200);
        $pending = $this->db->table('deliveries')->where('recipient', 'pending@example.com')->first();
        preg_match('~/confirm/([a-f0-9]{64})~', $pending['body'], $oldToken);
        $this->authorize();
        $this->unpause();
        $this->public->post('/confirm/' . $oldToken[1], form: [])->assertStatus(410);
        $this->public->post('/confirm/' . $token[1], form: [])->assertStatus(410);
        $campaign = $this->testCampaign($sender);
        $this->api->post($this->base . "/campaigns/{$campaign}/schedule", [
            'scheduled_at' => '2026-11-10T12:00:00Z',
            'confirm_live' => true,
        ])->assertStatus(200);
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        self::assertSame(0, $this->db->table('deliveries')->where('campaign_id', $campaign)->count());
        $this->public
            ->post('/subscribe/delivery', form: ['email' => 'simulation@example.com', 'consent' => 'yes'])
            ->assertStatus(200);
        $real = $this->db
            ->table('deliveries')
            ->where('recipient', 'simulation@example.com')
            ->where('transport', 'ses')
            ->first();
        preg_match('~/confirm/([a-f0-9]{64})~', $real['body'], $liveToken);
        $this->public->post('/confirm/' . $liveToken[1], form: [])->assertStatus(200);
        self::assertSame(
            'ses',
            $this->db->table('subscribers')->where('email', 'simulation@example.com')->first()['consent_transport'],
        );
        self::assertStringNotContainsString('/confirm/', $this->api->get($this->base . '/outbox')->body());
        $this->authorize(revoke: true);
        $this->public
            ->post('/subscribe/delivery', form: ['email' => 'after-revocation@example.com', 'consent' => 'yes'])
            ->assertStatus(200);
        $after = $this->db->table('deliveries')->where('recipient', 'after-revocation@example.com')->first();
        preg_match('~/confirm/([a-f0-9]{64})~', $after['body'], $revokedToken);
        $this->public->post('/confirm/' . $revokedToken[1], form: [])->assertStatus(410);
        self::assertStringNotContainsString('/confirm/', $this->api->get($this->base . '/outbox')->body());
        self::assertSame(2, $this->db->table('ses_authorization_events')->count());
    }

    public function testMySqlSharedDispatchMutexBlocksAdmissionBeforeReservation(): void
    {
        if ($this->db->driver() !== 'mysql') {
            self::markTestSkipped('Requires MySQL named locks on distinct pooled connections.');
        }
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $campaign = $this->testCampaign($sender);
        $id = $this->queueSesTest($campaign, 'mock-dispatch-lock-0001');
        $key = hash('sha256', 'arn:aws:sns:us-east-1:123456789012');
        $other = $this->db->acquire();
        try {
            self::assertSame(1, (int) $other->first('SELECT GET_LOCK(?, 0) AS acquired', [$key])['acquired']);
            $this->app->container->get(\Mailcrab\Worker::class)->tick();
            self::assertSame(0, $this->db->table('ses_send_reservations')->count());
            self::assertSame(0, (int) $this->db->table('deliveries')->where('id', $id)->first()['attempts']);
            self::assertNotContains('SendEmail', array_column($this->calls, 0));
        } finally {
            $other->first('SELECT RELEASE_LOCK(?) AS released', [$key]);
            $this->db->release($other);
        }
        $this->clock->advance('+2 seconds');
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        self::assertSame('accepted', $this->db->table('deliveries')->where('id', $id)->first()['status']);
    }

    public function testPartialPreferencesCannotUndoPauseAndReportsSeparateTransports(): void
    {
        $sender = $this->readySender();
        $campaign = $this->testCampaign($sender);
        $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                [],
                headers: ['Idempotency-Key' => 'fake-report-request-0001'],
            )
            ->assertStatus(200);
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        $this->authorize();
        $this->api
            ->put($this->base . '/delivery/preferences', ['rate_per_second' => 2])
            ->assertStatus(200)
            ->assertJson('paused', true)
            ->assertJson('default_sender_id', $sender);
        $this->unpause();
        $this->queueSesTest($campaign, 'live-report-request-0001');
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        $this->api
            ->get($this->base . '/report')
            ->assertStatus(200)
            ->assertJson('by_transport.fake.accepted', 1)
            ->assertJson('by_transport.ses.accepted', 1)
            ->assertJson('accepted', 2);
    }

    public function testLostMySqlSessionCannotReconnectPastTheDispatchMutex(): void
    {
        $connection = $this->createMock(\Crab\Database\ManagedConnection::class);
        $connection->method('driver')->willReturn('mysql');
        $connection
            ->expects(self::once())
            ->method('withoutReconnect')
            ->willReturnCallback(fn(\Closure $scope) => $scope($connection));
        $connection
            ->expects(self::once())
            ->method('first')
            ->with('SELECT GET_LOCK(?, 0) AS acquired', ['account-mock-key'])
            ->willReturn(['acquired' => 1]);
        $connection
            ->expects(self::once())
            ->method('transaction')
            ->willThrowException(new \RuntimeException('Physical session lost after lock acquisition'));
        $connection->method('isOpen')->willReturn(false);
        $database = $this->createMock(Database::class);
        $database
            ->expects(self::once())
            ->method('run')
            ->willReturnCallback(fn(\Closure $scope) => $scope($connection));
        $guard = new \Mailcrab\Delivery\SesGuard(
            new \Mailcrab\Store($database, $this->clock),
            $this->app->container->get(AwsConnection::class),
            $this->app->container->get(\Mailcrab\Delivery\DeliverySettings::class),
            $this->app->container->get(\Mailcrab\Delivery\DeliveryMode::class),
        );
        $this->expectException(\Mailcrab\Delivery\Deferred::class);
        $guard->dispatch(
            ['workspace_id' => $this->workspace],
            ['account_key' => 'account-mock-key'],
            static function (): string {
                self::fail('A lost pinned session must never send.');
            },
        );
    }

    public function testWelcomeDisabledDuringSesPreflightNeverReservesOrSends(): void
    {
        $sender = $this->readySender();
        $this->authorize();
        $this->unpause();
        $input = ['sender_id' => $sender, 'subject' => 'Hi', 'body' => 'Hi', 'enabled' => true, 'confirm_live' => true];
        $this->api->put($this->base . '/welcome', $input)->assertStatus(200);
        $this->public
            ->post('/subscribe/delivery', form: ['email' => 'welcome@example.com', 'consent' => 'yes'])
            ->assertStatus(200);
        $row = $this->db->table('deliveries')->where('kind', 'confirmation')->first();
        preg_match('~/confirm/([a-f0-9]{64})~', $row['body'], $token);
        $this->public->post('/confirm/' . $token[1], form: [])->assertStatus(200);
        $this->onAccount = fn() => $this->api->put($this->base . '/welcome', [
            ...$input,
            'enabled' => false,
        ])->assertStatus(200);
        $this->app->container->get(\Mailcrab\Worker::class)->tick();
        $welcome = $this->db->table('deliveries')->where('kind', 'welcome')->first();
        self::assertSame('queued', $welcome['status']);
        self::assertSame(0, (int) $welcome['attempts']);
        self::assertSame(0, $this->db->table('ses_send_reservations')->count());
        self::assertNotContains('SendEmail', array_column($this->calls, 0));
    }

    public function testReadinessExpiresAndNeverUnlocksSending(): void
    {
        $this->verifiedDomain();
        $this->api->post($this->base . '/delivery/connection/check', [])->assertStatus(200);
        $this->clock->advance('+61 minutes');
        $view = $this->api
            ->get($this->base . '/delivery')
            ->assertJson('connection_fresh', false)
            ->assertJson('domains.0.fresh', false)
            ->assertJson('ready', false)
            ->assertJson('real_sending', false);
        self::assertStringContainsString('expired', $view->json('checks.0.reason'));
    }
}
