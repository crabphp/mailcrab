<?php

declare(strict_types=1);

namespace Tests;

use Aws\SesV2\SesV2Client;
use Crab\App;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use Mailcrab\Auth;
use Mailcrab\Campaigns;
use Mailcrab\Delivery\FakeProvider;
use Mailcrab\Delivery\Provider;
use Mailcrab\Delivery\Retryable;
use Mailcrab\Delivery\SesProvider;
use Mailcrab\Renderer;
use Mailcrab\Worker;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class MailcrabTest extends TestCase
{
    private App $app;
    private Database $db;
    private Api $public;
    private Api $api;
    private FrozenClock $clock;
    private int $workspace;
    private string $base;
    private string $directory;
    private string $cookie;
    private string $csrf;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__) . '/storage/test-outbox-' . bin2hex(random_bytes(5));
        putenv('MAILCRAB_OUTBOX=' . $this->directory);
        $this->app = require dirname(__DIR__) . '/app.php';
        $this->clock = new FrozenClock('2026-11-10T12:00:00Z');
        $this->app->container->instance(ClockInterface::class, $this->clock);
        $this->db = $this->app->container->get(Database::class);
        if (
            $this->db->driver() === 'mysql'
            && ($this->db->first('SELECT DATABASE() AS name')['name'] ?? '') !== 'mailcrab_test'
        ) {
            throw new \RuntimeException('Tests may reset only the dedicated mailcrab_test MySQL database.');
        }
        $this->app->container->get(Migrator::class)->fresh();
        $owner = $this->app->container->get(Auth::class)->createWorkspace(
            'Rebitplay local',
            'rebitplay',
            'owner@rebitplay.test',
            'test-password-123',
            '123 Test Street, Test City',
        );
        $this->workspace = $owner['workspace_id'];
        $this->base = '/api/v1/workspaces/' . $this->workspace;
        $this->public = new Api($this->app);
        $login = $this->public
            ->post('/api/v1/auth/login', ['email' => 'owner@rebitplay.test', 'password' => 'test-password-123'])
            ->assertStatus(200);
        $this->cookie = explode(';', $login->header('Set-Cookie'))[0];
        $this->csrf = $login->json('csrf');
        $this->api = $this->public->withHeaders(['Cookie' => $this->cookie, 'X-CSRF-Token' => $this->csrf]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        putenv('MAILCRAB_OUTBOX');
    }

    private function subscriber(string $email = 'reader@example.test', array $extra = []): int
    {
        return (int) $this->api
            ->post(
                $this->base . '/subscribers',
                $extra
                + [
                    'email' => $email,
                    'name' => 'Reader',
                    'tags' => ['rebitplay'],
                    'consent' => true,
                    'consent_at' => '2026-10-01T10:00:00Z',
                    'source' => 'First-party signup',
                    'evidence' => 'Explicit newsletter checkbox; archived consent record.',
                ],
            )
            ->assertStatus(201)
            ->json('id');
    }

    private function sender(bool $verify = true): int
    {
        $id = (int) $this->api
            ->post($this->base . '/senders', ['name' => 'Rebitplay', 'email' => 'newsletter@rebitplay.test'])
            ->assertStatus(201)
            ->json('id');
        if ($verify) {
            $token = $this->latestToken();
            $this->public->post('/confirm/' . $token, form: [])->assertStatus(200);
        }
        return $id;
    }

    private function latestToken(): string
    {
        $delivery = $this->db->table('deliveries')->where('kind', 'confirmation')->orderByDesc('id')->first();
        preg_match('~/confirm/([a-f0-9]{64})~', $delivery['body'], $matches);
        return $matches[1];
    }

    private function campaign(?int $sender = null, array $extra = []): int
    {
        $sender ??= $this->sender();
        return (int) $this->api
            ->post(
                $this->base . '/campaigns',
                $extra
                + [
                    'sender_id' => $sender,
                    'subject' => 'News from Rebitplay',
                    'body' => "Hello!\n\n[Visit Rebitplay](https://rebitplay.example/news?from=email)",
                    'tag_id' => null,
                ],
            )
            ->assertStatus(201)
            ->json('id');
    }

    private function schedule(int $campaign, string $at = '2026-11-10T12:00:00Z'): void
    {
        $this->api->post($this->base . "/campaigns/{$campaign}/schedule", ['scheduled_at' => $at])->assertStatus(200);
    }

    private function tick(int $batch = 50): array
    {
        return $this->app->container->get(Worker::class)->tick($batch);
    }

    private function delivery(int $campaign): array
    {
        return $this->db->table('deliveries')->where('campaign_id', $campaign)->first();
    }

    private function feedback(
        array $delivery,
        string $kind,
        string $id = 'event-1',
        ?string $time = null,
    ): \Crab\Testing\ApiResponse {
        $time ??= (string) $this->clock->now()->getTimestamp();
        $body = json_encode([
            'provider_id' => $delivery['provider_id'],
            'event_id' => $id,
            'kind' => $kind,
        ], JSON_THROW_ON_ERROR);
        return $this->public->post('/webhooks/fake', body: $body, headers: [
            'Content-Type' => 'application/json',
            'X-Mailcrab-Timestamp' => $time,
            'X-Mailcrab-Signature' => hash_hmac('sha256', $time . '.' . $body, 'test-feedback-secret-0000000000000000'),
        ]);
    }

    public function testAuthenticationCsrfSessionExpiryAndLogout(): void
    {
        $this->public->get($this->base . '/subscribers')->assertStatus(401);
        $this->api->get('/api/v1/auth/me')->assertStatus(200)->assertJson('user.email', 'owner@rebitplay.test');
        $this->public
            ->withHeaders(['Cookie' => $this->cookie])
            ->post($this->base . '/subscribers', [])
            ->assertStatus(403);
        $this->api
            ->post($this->base . '/subscribers', [], headers: ['Origin' => 'https://evil.example'])
            ->assertStatus(403);
        $this->api->post('/api/v1/auth/logout', [])->assertStatus(204);
        $this->api->get('/api/v1/auth/me')->assertStatus(401);
        self::assertSame(0, $this->db->table('sessions')->count());
    }

    public function testSessionExpiresAndCredentialsAreNotStoredInPlaintext(): void
    {
        $row = $this->db->table('sessions')->first();
        self::assertNotSame(substr($this->cookie, strlen(Auth::COOKIE) + 1), $row['token_hash']);
        $this->clock->advance('+13 hours');
        $this->api->get('/api/v1/auth/me')->assertStatus(401);
        $this->public
            ->post('/api/v1/auth/login', ['email' => 'owner@rebitplay.test', 'password' => 'wrong-password'])
            ->assertStatus(401);
    }

    public function testWorkspaceIsolationForEveryResourceAndForeignReferences(): void
    {
        $other = $this->app->container->get(Auth::class)->createWorkspace(
            'Other',
            'other',
            'other@example.test',
            'test-password-123',
            'Other address',
        );
        $wid = $other['workspace_id'];
        $sender = $this->sender();
        $subscriber = $this->subscriber();
        $campaign = $this->campaign($sender);
        foreach ([
            'subscribers',
            "subscribers/{$subscriber}/consent",
            'tags',
            'senders',
            'campaigns',
            "campaigns/{$campaign}/preview",
            'welcome',
            'outbox',
            'report',
        ] as $suffix) {
            $this->api->get('/api/v1/workspaces/' . $wid . '/' . $suffix)->assertStatus(404);
        }
        $login = $this->public
            ->post('/api/v1/auth/login', ['email' => 'other@example.test', 'password' => 'test-password-123'])
            ->assertStatus(200);
        $client = $this->public->withHeaders([
            'Cookie' => explode(';', $login->header('Set-Cookie'))[0],
            'X-CSRF-Token' => $login->json('csrf'),
        ]);
        $base = '/api/v1/workspaces/' . $wid;
        $client->get($base . '/subscribers')->assertJson('data', []);
        $client->get($base . '/report')->assertJson('accepted', 0);
        $client
            ->post($base . '/campaigns', ['sender_id' => $sender, 'subject' => 'Stolen sender', 'body' => 'No'])
            ->assertStatus(404);
        $client->put($base . '/welcome', [
            'sender_id' => $sender,
            'subject' => 'No',
            'body' => 'No',
            'enabled' => true,
        ])->assertStatus(404);
        $client->post($base . "/subscribers/{$subscriber}/suppress", [])->assertStatus(404);
        $client->put($base . "/subscribers/{$subscriber}/tags", ['tags' => ['wrong']])->assertStatus(404);
        $client->put($base . "/campaigns/{$campaign}", [
            'sender_id' => $sender,
            'subject' => 'No',
            'body' => 'No',
        ])->assertStatus(404);
        $tag = $this->db->table('tags')->first();
        $this->api->post($this->base . '/campaigns', [
            'sender_id' => $sender,
            'subject' => 'Fine',
            'body' => 'Fine',
            'tag_id' => $tag['id'],
        ])->assertStatus(201);
        self::assertSame('active', $this->db->table('subscribers')->where('id', $subscriber)->first()['status']);
    }

    public function testConsentIsRequiredNormalizedAndAuditable(): void
    {
        $this->api->post($this->base . '/subscribers', ['email' => 'missing@example.test'])->assertStatus(422);
        $id = $this->subscriber('Reader@Example.test');
        self::assertSame('reader@example.test', $this->db->table('subscribers')->where('id', $id)->first()['email']);
        $this->api
            ->get($this->base . "/subscribers/{$id}/consent")
            ->assertStatus(200)
            ->assertJson('data.0.kind', 'import_opt_in');
        $this->api->put($this->base . "/subscribers/{$id}/tags", ['tags' => ['players', 'weekly']])->assertStatus(200);
        self::assertSame(2, $this->db->table('subscriber_tags')->where('subscriber_id', $id)->count());
        $this->api->post($this->base . '/subscribers', [
            'consent' => true,
            'consent_at' => '2027-01-01T00:00:00Z',
        ])->assertStatus(422);
    }

    public function testCsvImportIsAtomicBoundedAndDoesNotResurrectSuppression(): void
    {
        $id = $this->subscriber();
        $this->api->post($this->base . "/subscribers/{$id}/suppress", [])->assertStatus(200);
        $input = [
            'origin' => 'existing_opt_in',
            'consent' => true,
            'source' => 'First-party opt-in export',
            'evidence' => 'Permission checkbox and original timestamps',
            'csv' => "email,consent_at,name,tags\nnew@example.test,2026-10-01T00:00:00Z,New,players|weekly\nreader@example.test,2026-10-01T00:00:00Z,Reader,weekly",
        ];
        $this->api
            ->post($this->base . '/subscribers/import', $input)
            ->assertStatus(200)
            ->assertJson('imported', 1)
            ->assertJson('suppressed', 1);
        $this->api
            ->post($this->base . '/subscribers/import', $input)
            ->assertJson('duplicate', 1)
            ->assertJson('suppressed', 1);
        self::assertSame('unsubscribed', $this->db->table('subscribers')->where('id', $id)->first()['status']);
        $input['csv'] = "email,consent_at\nvalid@example.test,2026-10-01T00:00:00Z\ninvalid,2026-10-01T00:00:00Z";
        $this->api->post($this->base . '/subscribers/import', $input)->assertStatus(422);
        self::assertFalse($this->db->table('subscribers')->where('email', 'valid@example.test')->exists());
        $input['csv'] = "email,consent_at\n" . str_repeat("bulk@example.test,2026-10-01T00:00:00Z\n", 501);
        $this->api->post($this->base . '/subscribers/import', $input)->assertStatus(422);
        $input['origin'] = 'purchased';
        $this->api->post($this->base . '/subscribers/import', $input)->assertStatus(422);
    }

    public function testDoubleOptInRequiresExplicitPostAndIsSingleUse(): void
    {
        $this->public->get('/subscribe/rebitplay')->assertStatus(200);
        $this->public->post('/subscribe/rebitplay', form: ['email' => 'signup@example.test'])->assertStatus(422);
        $this->public
            ->post('/subscribe/rebitplay', form: ['email' => 'signup@example.test', 'consent' => 'yes'])
            ->assertStatus(200);
        $token = $this->latestToken();
        self::assertSame('pending', $this->db->table('subscribers')->first()['status']);
        $this->public->get('/confirm/' . $token)->assertStatus(200);
        self::assertSame('pending', $this->db->table('subscribers')->first()['status']);
        $this->public->post('/confirm/' . $token, form: [])->assertStatus(200);
        $this->public->post('/confirm/' . $token, form: [])->assertStatus(200);
        self::assertSame('active', $this->db->table('subscribers')->first()['status']);
        self::assertSame(1, $this->db->table('consents')->where('kind', 'double_opt_in')->count());
        self::assertSame(64, strlen($this->db->table('challenges')->first()['token_hash']));
        self::assertNotSame($token, $this->db->table('challenges')->first()['token_hash']);
    }

    public function testExpiredTokensAndSuppressedSignupsNeverActivate(): void
    {
        $this->public
            ->post('/subscribe/rebitplay', form: ['email' => 'pending@example.test', 'consent' => 'yes'])
            ->assertStatus(200);
        $token = $this->latestToken();
        $this->clock->advance('+49 hours');
        $this->public->post('/confirm/' . $token, form: [])->assertStatus(410);
        $id = (int) $this->db->table('subscribers')->first()['id'];
        $this->api->post($this->base . "/subscribers/{$id}/suppress", [])->assertStatus(401);
        // Re-login after advancing beyond the session TTL.
        $login = $this->public
            ->post('/api/v1/auth/login', ['email' => 'owner@rebitplay.test', 'password' => 'test-password-123'])
            ->assertStatus(200);
        $client = $this->public->withHeaders([
            'Cookie' => explode(';', $login->header('Set-Cookie'))[0],
            'X-CSRF-Token' => $login->json('csrf'),
        ]);
        $client->post($this->base . "/subscribers/{$id}/suppress", [])->assertStatus(200);
        $this->public
            ->post('/subscribe/rebitplay', form: ['email' => 'pending@example.test', 'consent' => 'yes'])
            ->assertStatus(200);
        self::assertSame(1, $this->db->table('challenges')->count());
        $this->tick();
        self::assertSame('suppressed', $this->db->table('deliveries')->first()['status']);
    }

    public function testImportCannotBypassPendingDoubleOptIn(): void
    {
        $this->public
            ->post('/subscribe/rebitplay', form: ['email' => 'reader@example.test', 'consent' => 'yes'])
            ->assertStatus(200);
        $this->api
            ->post($this->base . '/subscribers', [
                'email' => 'reader@example.test',
                'consent' => true,
                'consent_at' => '2026-10-01T00:00:00Z',
                'source' => 'Import',
                'evidence' => 'Original permission',
            ])
            ->assertStatus(201)
            ->assertJson('result', 'duplicate');
        self::assertSame('pending', $this->db->table('subscribers')->first()['status']);
    }

    public function testSenderVerificationBlocksScheduleAndTests(): void
    {
        $sender = $this->sender(false);
        $campaign = $this->campaign($sender);
        $this->api->post($this->base . "/campaigns/{$campaign}/schedule", [
            'scheduled_at' => '2026-11-10T12:00:00Z',
        ])->assertStatus(422);
        $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                [],
                headers: ['Idempotency-Key' => 'test-request-0000001'],
            )
            ->assertStatus(422);
        $this->public->post('/confirm/' . $this->latestToken(), form: [])->assertStatus(200);
        $this->schedule($campaign);
        $this->api->put($this->base . "/campaigns/{$campaign}", [
            'sender_id' => $sender,
            'subject' => 'Changed',
            'body' => 'Changed',
        ])->assertStatus(409);
    }

    public function testTestSendIsIdempotentAndOnlyTargetsAuthenticatedOwner(): void
    {
        $campaign = $this->campaign();
        $a = $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                ['recipient' => 'victim@example.test'],
                headers: ['Idempotency-Key' => 'test-request-0000001'],
            )
            ->assertStatus(200)
            ->json('delivery_id');
        $b = $this->api
            ->post(
                $this->base . "/campaigns/{$campaign}/test",
                [],
                headers: ['Idempotency-Key' => 'test-request-0000001'],
            )
            ->assertStatus(200)
            ->json('delivery_id');
        self::assertSame($a, $b);
        self::assertSame('owner@rebitplay.test', $this->db->table('deliveries')->where('id', $a)->first()['recipient']);
        $this->tick();
        self::assertSame(1, $this->db->table('deliveries')->where('kind', 'test')->count());
    }

    public function testScheduleDueTimeBoundedBatchesAndIdempotentExpansion(): void
    {
        $sender = $this->sender();
        for ($i = 0; $i < 7; ++$i) {
            $this->subscriber("reader{$i}@example.test");
        }
        $campaign = $this->campaign($sender);
        $this->schedule($campaign, '2026-11-10T12:05:00Z');
        self::assertSame(0, $this->tick(2)['enqueued']);
        $this->clock->advance('+5 minutes');
        self::assertSame(2, $this->tick(2)['enqueued']);
        self::assertSame(2, $this->db->table('deliveries')->where('campaign_id', $campaign)->count());
        for ($i = 0; $i < 6; ++$i) {
            $result = $this->tick(2);
            self::assertLessThanOrEqual(2, $result['enqueued']);
            self::assertLessThanOrEqual(2, $result['processed']);
        }
        self::assertSame(
            7,
            $this->db->table('deliveries')->where('campaign_id', $campaign)->where('status', 'accepted')->count(),
        );
        self::assertSame('finished', $this->db->table('campaigns')->where('id', $campaign)->first()['status']);
        $this->tick(100);
        self::assertSame(7, $this->db->table('deliveries')->where('campaign_id', $campaign)->count());
        self::assertCount(8, glob($this->directory . '/*.json')); // sender confirmation + 7 newsletters
        $this->clock->advance('+1 second');
        $this->schedule($campaign, '2026-11-10T12:05:00Z'); // A retry after the due time returns the existing schedule.
        $this->api
            ->get($this->base . '/report', ['campaign_id' => $campaign])
            ->assertJson('accepted', 7)
            ->assertJson('events', []);
    }

    public function testTagSegmentAndAudienceHighWaterMark(): void
    {
        $this->subscriber('chosen@example.test', ['tags' => ['chosen']]);
        $this->subscriber('other@example.test', ['tags' => ['other']]);
        $tag = $this->db->table('tags')->where('name', 'chosen')->first();
        $campaign = $this->campaign(extra: ['tag_id' => $tag['id']]);
        $this->schedule($campaign);
        $this->subscriber('late@example.test', ['tags' => ['chosen']]);
        $this->tick();
        self::assertSame(1, $this->db->table('deliveries')->where('campaign_id', $campaign)->count());
        self::assertSame('chosen@example.test', $this->delivery($campaign)['recipient']);
    }

    public function testSuppressionAndSenderRevocationAreRecheckedAtSend(): void
    {
        $subscriber = $this->subscriber();
        $sender = $this->sender();
        $campaign = $this->campaign($sender);
        $this->schedule($campaign);
        $this->app->container->get(Campaigns::class)->expand(50);
        $this->api->post($this->base . "/subscribers/{$subscriber}/suppress", [])->assertStatus(200);
        $this->tick();
        self::assertSame('suppressed', $this->delivery($campaign)['status']);
        $this->subscriber('other@example.test');
        $second = $this->campaign($sender);
        $this->schedule($second);
        $this->app->container->get(Campaigns::class)->expand(50);
        $this->api->post($this->base . "/senders/{$sender}/revoke", [])->assertStatus(200);
        $this->tick();
        self::assertSame('suppressed', $this->delivery($second)['status']);
    }

    public function testSuppressionRetriesAreIdempotentAndComplaintReasonWins(): void
    {
        $id = $this->subscriber();
        $this->api->post($this->base . "/subscribers/{$id}/suppress", [])->assertStatus(200);
        $this->api->post($this->base . "/subscribers/{$id}/suppress", [])->assertStatus(200);
        self::assertSame(
            1,
            $this->db->table('consents')->where('subscriber_id', $id)->where('kind', 'unsubscribed')->count(),
        );
        self::assertSame(1, $this->db->table('suppressions')->count());
    }

    public function testCancellationSkipsPendingDeliveries(): void
    {
        $this->subscriber();
        $campaign = $this->campaign();
        $this->schedule($campaign);
        $this->app->container->get(Campaigns::class)->expand(50);
        $this->api->post($this->base . "/campaigns/{$campaign}/cancel", [])->assertStatus(200);
        $this->tick();
        self::assertSame('suppressed', $this->delivery($campaign)['status']);
        self::assertSame('cancelled', $this->db->table('campaigns')->where('id', $campaign)->first()['status']);
    }

    public function testPreviewEscapesHtmlAndUnsafeLinks(): void
    {
        $campaign = $this->campaign(extra: [
            'body' => '<script>alert(1)</script> [bad](javascript:alert(1)) [good](https://example.test/path)',
        ]);
        $response = $this->api->get($this->base . "/campaigns/{$campaign}/preview")->assertStatus(200);
        self::assertStringContainsString('&lt;script&gt;', $response->body());
        self::assertStringNotContainsString('<script>', $response->body());
        self::assertStringNotContainsString('href="javascript:', $response->body());
        self::assertStringContainsString('Unsubscribe', $response->body());
        self::assertSame(0, $this->db->table('links')->count());
        self::assertStringContainsString('sandbox', $response->header('Content-Security-Policy'));
    }

    public function testClickUnsubscribeAndAuthenticatedFeedbackReports(): void
    {
        $subscriber = $this->subscriber();
        $campaign = $this->campaign();
        $this->schedule($campaign);
        $this->tick();
        $delivery = $this->delivery($campaign);
        $link = $this->db->table('links')->where('delivery_id', $delivery['id'])->first();
        $this->public
            ->get('/c/' . $link['token'])
            ->assertStatus(302)
            ->assertHeader('Location', 'https://rebitplay.example/news?from=email');
        $this->public->get('/c/' . $link['token'])->assertStatus(302);
        $this->public->get('/u/' . $delivery['token'])->assertStatus(200);
        self::assertSame('active', $this->db->table('subscribers')->where('id', $subscriber)->first()['status']);
        $this->public->post('/u/' . $delivery['token'], form: ['List-Unsubscribe' => 'One-Click'])->assertStatus(200);
        $this->public->post('/u/' . $delivery['token'], form: [])->assertStatus(200);
        self::assertSame('unsubscribed', $this->db->table('subscribers')->where('id', $subscriber)->first()['status']);
        $this->feedback($delivery, 'delivered')->assertStatus(200);
        $this->feedback($delivery, 'delivered')->assertStatus(200);
        $this->feedback($delivery, 'complaint', 'complaint-1')->assertStatus(200);
        $this->feedback($delivery, 'bounce', 'bounce-1')->assertStatus(200);
        self::assertSame('complained', $this->db->table('subscribers')->where('id', $subscriber)->first()['status']);
        self::assertSame(
            'complained',
            $this->db->table('suppressions')->where('email', 'reader@example.test')->first()['reason'],
        );
        $this->feedback(
            $delivery,
            'delivered',
            'too-old',
            (string) ($this->clock->now()->getTimestamp() - 301),
        )->assertStatus(401);
        $this->public->post('/webhooks/fake', ['kind' => 'bounce'])->assertStatus(401);
        $report = $this->api
            ->get($this->base . '/report', ['campaign_id' => $campaign])
            ->assertStatus(200)
            ->json();
        $events = array_column($report['events'], 'count', 'kind');
        self::assertSame(1, (int) $events['click']);
        self::assertSame(1, (int) $events['unsubscribe']);
        self::assertSame(1, (int) $events['complaint']);
        self::assertSame(1, (int) $events['delivered']);
        self::assertSame(1, $this->db->table('suppressions')->count());
    }

    public function testBounceSuppressesFutureCampaigns(): void
    {
        $subscriber = $this->subscriber();
        $sender = $this->sender();
        $campaign = $this->campaign($sender);
        $this->schedule($campaign);
        $this->tick();
        $this->feedback($this->delivery($campaign), 'bounce')->assertStatus(200);
        self::assertSame('bounced', $this->db->table('subscribers')->where('id', $subscriber)->first()['status']);
        $next = $this->campaign($sender);
        $this->schedule($next);
        $this->tick();
        self::assertSame(0, $this->db->table('deliveries')->where('campaign_id', $next)->count());
    }

    public function testWelcomeEnrollsOnlyFutureConfirmedSignupExactlyOnce(): void
    {
        $sender = $this->sender();
        $this->api->put($this->base . '/welcome', [
            'sender_id' => $sender,
            'subject' => 'Welcome aboard',
            'body' => 'Thanks for joining.',
            'enabled' => true,
        ])->assertStatus(200);
        $this->subscriber('imported@example.test');
        self::assertSame(0, $this->db->table('deliveries')->where('kind', 'welcome')->count());
        $this->public
            ->post('/subscribe/rebitplay', form: ['email' => 'signup@example.test', 'consent' => 'yes'])
            ->assertStatus(200);
        $token = $this->latestToken();
        self::assertSame(0, $this->db->table('deliveries')->where('kind', 'welcome')->count());
        $this->public->post('/confirm/' . $token, form: [])->assertStatus(200);
        $this->public->post('/confirm/' . $token, form: [])->assertStatus(200);
        self::assertSame(1, $this->db->table('deliveries')->where('kind', 'welcome')->count());
        $this->tick();
        self::assertSame('accepted', $this->db->table('deliveries')->where('kind', 'welcome')->first()['status']);
    }

    public function testKnownPreAcceptanceFailuresRetryWithBackoffAndStopAtThree(): void
    {
        $this->app->container->instance(Provider::class, new class implements Provider {
            public function idempotent(): bool
            {
                return false;
            }

            public function send(array $delivery, array $message): string
            {
                throw new Retryable('Explicit rejection.');
            }
        });
        $this->subscriber();
        $campaign = $this->campaign();
        $this->schedule($campaign);
        $this->tick();
        self::assertSame(1, (int) $this->delivery($campaign)['attempts']);
        $this->tick();
        self::assertSame(1, (int) $this->delivery($campaign)['attempts']);
        $this->clock->advance('+31 seconds');
        $this->tick();
        self::assertSame(2, (int) $this->delivery($campaign)['attempts']);
        $this->clock->advance('+61 seconds');
        $this->tick();
        self::assertSame('failed', $this->delivery($campaign)['status']);
        $this->clock->advance('+1 day');
        $this->tick();
        self::assertSame(3, (int) $this->delivery($campaign)['attempts']);
    }

    public function testAmbiguousNonIdempotentOutcomeIsNeverRetried(): void
    {
        $this->app->container->instance(Provider::class, new class implements Provider {
            public function idempotent(): bool
            {
                return false;
            }

            public function send(array $delivery, array $message): string
            {
                throw new \RuntimeException('Timeout after possible acceptance.');
            }
        });
        $this->subscriber();
        $campaign = $this->campaign();
        $this->schedule($campaign);
        $this->tick();
        self::assertSame('unknown', $this->delivery($campaign)['status']);
        $this->clock->advance('+1 hour');
        $this->tick();
        self::assertSame(1, (int) $this->delivery($campaign)['attempts']);
    }

    public function testFakeDeliveryAndStaleClaimsAreIdempotent(): void
    {
        $this->subscriber();
        $campaign = $this->campaign();
        $this->schedule($campaign);
        $this->tick();
        $delivery = $this->delivery($campaign);
        $provider = new FakeProvider();
        $message = $this->app->container->get(Renderer::class)->render($delivery);
        self::assertSame($delivery['provider_id'], $provider->send($delivery, $message));
        $this->db
            ->table('deliveries')
            ->where('id', $delivery['id'])
            ->update(['status' => 'sending', 'claimed_at' => '2026-11-10 11:00:00']);
        self::assertSame(1, $this->tick()['recovered']);
        self::assertSame('accepted', $this->delivery($campaign)['status']);
        self::assertCount(2, glob($this->directory . '/*.json'));
    }

    public function testLiveSesPathIsLockedBeforeAnyNetworkCall(): void
    {
        $client = new SesV2Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'not-real', 'secret' => 'not-real'],
            'http_handler' => static function (): never {
                throw new \RuntimeException('Network must not be called.');
            },
        ]);
        $provider = new SesProvider($client, 'mailcrab-feedback');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit user authorization');
        $provider->send([], []);
    }

    public function testConcurrentMySqlWorkersDoNotDuplicateAcceptance(): void
    {
        if ($this->db->driver() !== 'mysql') {
            self::markTestSkipped('Run against dedicated MySQL database for the concurrency check.');
        }
        $sender = $this->sender();
        for ($i = 0; $i < 5; ++$i) {
            $this->subscriber("parallel{$i}@example.test");
        }
        $campaign = $this->campaign($sender);
        $this->schedule($campaign);
        $processes = [];
        for ($i = 0; $i < 2; ++$i) {
            $processes[] = proc_open(
                [PHP_BINARY, __DIR__ . '/worker-once.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $outputs[] = $pipes;
        }
        foreach ($processes as $i => $process) {
            $stdout = stream_get_contents($outputs[$i][1]);
            $stderr = stream_get_contents($outputs[$i][2]);
            fclose($outputs[$i][1]);
            fclose($outputs[$i][2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
        }
        $this->tick();
        self::assertSame(
            5,
            $this->db->table('deliveries')->where('campaign_id', $campaign)->where('status', 'accepted')->count(),
        );
        self::assertCount(6, glob($this->directory . '/*.json'));
    }

    public function testReadinessAndSecurityHeaders(): void
    {
        $this->public->get('/ready')->assertStatus(200)->assertJson('real_sending', false);
        $this->public
            ->get('/subscribe/rebitplay')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->api->post($this->base . '/subscribers', form: ['email' => 'reader@example.test'])->assertStatus(415);
    }
}
