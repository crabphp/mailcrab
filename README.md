# Mailcrab

Lean, consent-first newsletters on **PHP 8.4 + CrabPHP + MySQL**, with a **React / TypeScript / Vite** dashboard. No Docker, mail server, paid services, or deployment scripts.

**This is a working local-development slice, not a live-verified release.** Fake delivery is the default. The SES runtime requires a separate server gate, explicit per-workspace CLI authorization, fresh readiness, and unpausing; nothing has been activated. Changing `DELIVERY_PROVIDER` to `ses` is rejected. Nothing imports Rebitplay data or touches the live Rebitplay application.

## What works

- CLI-created owner accounts; HttpOnly, SameSite=Strict sessions; CSRF-protected workspace APIs.
- Workspace-scoped subscribers, tags, immutable consent history, and persistent suppression.
- Atomic, bounded CSV import of explicitly opted-in addresses, including original consent timestamps and evidence. Imports never reactivate suppression or bypass pending double opt-in.
- Hosted signup page with explicit consent, confirmation email, expiring hashed tokens, and a separate confirmation POST (email scanners cannot subscribe people with a GET).
- Sender verification, revocation, and a plain-text email editor with safe links and saved HTML preview.
- Idempotent test sends **only to the authenticated owner's email**; explicit confirmation for real SES tests.
- Disabled-by-default SES routing with immutable transport snapshots, shared account dispatch locking, conservative rate/quota admission, immediate pause, audited CLI authorization/revocation, and live-confirmation consent provenance.
- Scheduled campaigns, optional tag segmentation, bounded audience expansion, transactional per-recipient outbox, cancellation, retry/backoff, and stale-claim recovery.
- Signed fake-provider delivery/bounce/complaint events; unique link-click and unsubscribe reports. No open pixels, cookies for readers, or IP/user-agent tracking.
- A basic **one-email welcome sequence**, queued exactly once after a future double-opt-in confirmation. Imports do not enroll; disabling the sequence suppresses its pending jobs.

- **Settings → Email delivery**: server-side SES account checks, workspace-isolated domains, explicit cloud-setup authorization, exact DKIM/MAIL FROM/SPF/DMARC records, DNS status and safe copy controls, sender/Reply-To/default configuration, SNS setup and signed ingress, durable feedback reconciliation, and actionable readiness checks. [Operator setup guide](docs/ses-setup.md).

The dashboard has Campaigns, Audience, Welcome, Reports, Activity, and Settings views. Sender management lives in Settings, not a competing screen. Its empty states are real: there is no invented audience or performance data.

## Local setup

Requirements: PHP **8.4** (`php84` on this machine), Composer, PDO MySQL, mbstring, OpenSSL, curl, Node **22.12+**, npm, and MySQL **8.0+** (verified with 8.4). Use separate development and test databases. Do not point this app at a sibling project's database.

This development checkout currently requires [crabphp/framework](https://github.com/crabphp/framework) alongside it at `../framework`. Clone both repositories into the same parent directory before installing. Composer copies the framework into `vendor/` (`symlink: false`); it does not modify the sibling. The local path repository supplies the version in the lockfile, so this is not yet a standalone Packagist installation.

```sh
composer install --no-interaction
php84 bin/init-env.php
npm --prefix dashboard ci
npm --prefix dashboard run build
```

`init-env.php` creates `.env` from the example, generates both secrets, sets mode 0600, and refuses to overwrite an existing file. Edit **only this project's `.env`** with your dedicated MySQL credentials. `APP_URL` must be the exact public origin, without a trailing path; the default is `http://127.0.0.1:8090`. Keep debug off.

For a new local MySQL database, an administrator can run the following **manually**, replacing the password with a unique local value:

```sql
CREATE DATABASE mailcrab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mailcrab'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-unique-local-password';
GRANT ALL PRIVILEGES ON mailcrab.* TO 'mailcrab'@'127.0.0.1';
```

No global grants or MySQL administrative account are needed by the app. Migrations require DDL permissions on this database. Use a more restricted runtime account before any future production rollout.

```sh
php84 cua migrate
# Enter a unique 12–72 byte password without putting it in shell history:
read -rs 'MAILCRAB_ADMIN_PASSWORD?Owner password: '; echo
export MAILCRAB_ADMIN_PASSWORD
php84 cua mailcrab:workspace --name='Rebitplay local' --slug=rebitplay \
  --email='you@example.com' --address='Your real postal mailing address'
unset MAILCRAB_ADMIN_PASSWORD
php84 cua start
```

The `read` syntax above is for zsh. In bash, use `read -rs -p 'Owner password: ' MAILCRAB_ADMIN_PASSWORD` instead. There is **no default password**, public registration, or seeded owner. Start the server in a visible terminal; do not daemonize it. Open **http://127.0.0.1:8090**. All members currently have owner-level permissions within their workspace; invitations, password recovery, and granular roles are not implemented.

### First local walkthrough

1. Sign in. Add your sender in **Settings → Email delivery → Sender identity**. You can review local sending without configuring AWS.
2. Run `php84 cua mailcrab:tick --batch=50` in another visible terminal. In **Activity**, expand the verification message and open its local confirmation link. Press **Confirm email**, then refresh the dashboard.
3. Visit **Your signup page**. Enter an address you control and explicitly consent. Run another tick, open its confirmation from Activity, and confirm. Or add/import an existing opted-in record with its evidence.
4. Create a campaign. Use `[label](https://example.com)` for links. Raw HTML is always escaped. Save the draft to preview it.
5. **Send test to me** queues a fake test to the logged-in owner. Run a tick, then inspect Activity or `storage/outbox/*.json`. There is no network delivery.
6. Schedule a **fake** campaign. The UI accepts your local timezone; the API requires an offset or `Z`, and storage uses UTC. Once due, run ticks until expansion and delivery finish. Content is immutable after scheduling; create a new draft to change it. Cancellation cannot recall an in-flight or accepted message.
7. A message's stored HTML includes tracked click and unsubscribe URLs. GET unsubscribe only shows a confirmation; POST supports unauthenticated one-click opt-out. Suppression prevents subsequent newsletters and cannot be cleared through import.
8. Configure **Welcome** for future confirmed signups. No historical/backfill sending occurs.

A tick is finite, never a hidden daemon: at most **100** audience rows expanded, **100** eligible delivery rows examined, **100** stale claims recovered, and **10** campaign completion checks. Default batch is 50. A due campaign may take multiple ticks. If desired later, run the one-shot command from cron once a minute. No scheduler is installed by setup.

For frontend hot reload, run `npm --prefix dashboard run dev` in a visible terminal. Set `APP_URL=http://localhost:5173` (or the exact Vite origin you actually use) and restart Crab on port 8090; Vite proxies the API and public form routes to it. The simplest end-to-end setup is the built dashboard served by Crab, with a single origin. SES feedback additionally requires a publicly reachable HTTPS APP_URL; the settings page displays the server's exact webhook URL.

## CSV contract

UTF-8 CSV, optional BOM; **500 data rows / 256 KiB maximum per request**. Headers must be unique and drawn from `email,consent_at,name,tags`. `email` and `consent_at` are mandatory. Quote CSV fields normally; tags use `|` separators, with at most 10 tags per subscriber.

```csv
email,consent_at,name,tags
reader@example.com,2026-10-01T12:00:00Z,Reader,rebitplay|weekly
```

The request also requires `consent: true`, `origin: "existing_opt_in"`, a `source`, and `evidence` describing the original permission. Future or timezone-less consent timestamps are rejected. The entire batch is validated before any write. The response distinguishes `imported`, `duplicate`, and `suppressed`; retrying the batch does not duplicate consent or recipients. Existing records and tags are not silently overwritten. Edit tags separately. You must actually possess the permission you attest to; software cannot establish that an uploaded list was legitimately collected.

**No scraped or purchased audiences.** Subscriber addresses are normalized to lowercase and deduplicated inside their workspace. An unsubscribe, bounce, or complaint is persistent. Re-consent/reinstatement requires a future explicit audited workflow; there is deliberately no unsuppress shortcut.

## Feedback and reports

Fake delivery means **accepted into a local file**, not delivered to a recipient. To exercise delivery reports, POST an authenticated fake-provider event; this is a development simulator, not an SES endpoint:

```json
{"event_id":"a-stable-unique-event-id","provider_id":"fake-<id-from-Activity>","kind":"delivered"}
```

Supported kinds: `delivered`, `bounce`, `complaint`. Send the exact JSON bytes to `/webhooks/fake` with:

- `X-Mailcrab-Timestamp`: Unix seconds, within five minutes of server time.
- `X-Mailcrab-Signature`: lowercase hex HMAC-SHA256 of **`timestamp + "." + raw_body`**, keyed by `FAKE_WEBHOOK_SECRET`.

Replayed event IDs do not duplicate events or suppression. Unknown provider IDs return 404 so a provider simulator can retry after acceptance commits. Bounce/complaint always suppress future marketing; late delivery receipts cannot undo them. Never expose the fake webhook secret to a browser. Use the tests as an executable signed-request example.

Reports separate **real SES** from **fake simulation**, independently of the workspace's current mode. Real metrics never include fake acceptance or simulated feedback. Reports distinguish acceptance, current queue state, delivery receipts, clicks, unsubscribe, bounce, and complaint. Clicks are deduplicated per tracked HTML link per email; `unique_recipients` means distinct delivery records, **not distinct humans across campaigns**. Plain-text URLs are untracked. Security scanners can click links, so these are not guaranteed human engagement. Delivered counts represent distinct emails with receipts, even if a later complaint changes their state. Workspace totals include control messages and tests; select a campaign for campaign-only results. There is no open tracking.

## Safety and queue semantics

- All workspace mutations lock the workspace row first. Public confirmation/opt-out, feedback, audience generation, and worker claims use the same ordering. APIs validate membership and scope every referenced sender, tag, subscriber, and campaign. Cross-workspace resource probes return 404.
- One immutable recipient snapshot and unique database key per campaign/subscriber; the snapshot contains subject, body, sender, and postal address. The schedule freezes a subscriber **ID high-water mark**, not a full historical tag/status snapshot: eligibility/tag membership is evaluated during bounded expansion, and active consent/suppression/sender verification is rechecked at claim time. New IDs after scheduling are excluded.
- Each queued delivery is claimed transactionally before provider I/O. There is necessarily an in-flight boundary: opt-out/revocation cannot recall a send that has already been claimed. Do not promise zero in-flight email after an opt-out.
- Fake files are written under a lock and atomically renamed; the provider's deterministic key survives process restarts. Production database backups and the outbox need their own access/retention policies. Outbox files contain addresses and confirmation capabilities, are mode 0600, and are outside the public directory.
- Known pre-acceptance rejections retry with 30/60-second backoff and stop at three total attempts. Ambiguous outcomes from a non-idempotent transport become **unknown**, never an automatic resend. Stale in-flight claims are recovered after five minutes only when provider idempotency makes that safe; otherwise they become unknown for reconciliation.
- Sender revocation, sequence disablement, suppression, and cancellation are checked again at claim time. Suppressed jobs do not call the provider. There is no UI retry/unsuppress button for unknown outcomes.
- Sessions last 12 hours, store only token hashes, and use HTTPS Secure cookies when `APP_URL` is HTTPS. Rate limits cover authentication, signup, import, verification, tests, click tracking, and feedback. Reader events do not store IP addresses or user agents.
- This first slice uses a **Crab database-backed transactional outbox**, not a second opaque queue. That makes recipient idempotency, consent checks, and reports share the same source of truth. The one-shot Crab console tick supplies scheduling without a separate broker.

## Amazon SES runtime (disabled by default)

[The operator guide](docs/ses-setup.md) covers onboarding, permissions, DNS, signed feedback, and the explicit launch/revocation procedure. Do not enable gates, provision resources, or make even a real test send without user direction.

`DeliveryRouter` keeps fake jobs fake. New campaigns and welcomes snapshot their transport; previously fake sequences require an explicit save before future enrollments can use SES. Tests use the current authorized mode and only target the owner. SES confirmations use the verified default sender rather than `.invalid`. Browser scheduling/test/welcome confirmation is enforced again inside the locked transaction.

SES sending requires **both** `SES_LIVE_ENABLED=true` and `mailcrab:ses-authorize` with the exact workspace slug and an audit reason. Authorization starts paused; Settings cannot unlock the server gate. Revocation defers outstanding SES jobs, never converts them to fake. Existing fake confirmation capabilities are invalidated on authorization; simulation-only DOI cannot become SES marketing consent. Those subscribers must request and complete a new real confirmation. Recorded import consent remains eligible only when its original first-party evidence is genuine.

Before dispatch, the worker checks fresh account/domain/feedback readiness, current sender/consent/suppression, production access and quota, and live SES identity/DKIM/MAIL FROM health. An account-scoped mutex spans durable quota admission and the external send, with a conservative cooldown and shared last-24-hour reservations. Failed preflight/pause/rate/quota gates defer without consuming attempts. A reservation survives an ambiguous send. MySQL is required for production; SQLite uses a local file mutex for development only.

The SDK has bounded timeouts and **zero automatic send retries**. Explicit throttling can retry with bounded backoff; ambiguous acceptance becomes **unknown**, never an automatic resend. Signed SES/SNS events reconcile acceptance races and permanently suppress bounces/complaints. Monitoring, backups/retention, real IAM/DNS/account validation, and an explicitly authorized end-to-end exercise using only user-controlled addresses remain launch prerequisites. No AWS resources, DNS records or real messages were created during development.

## Tests and checks

```sh
# Fast isolated tests; SQLite is a test-only convenience, never the runtime default.
php84 vendor/bin/phpunit
npm --prefix dashboard test
npm --prefix dashboard run build

# Full MySQL suite, including two concurrent bounded worker processes:
# First create a dedicated disposable database named exactly mailcrab_test.
DB_DSN='mysql:host=127.0.0.1;dbname=mailcrab_test;charset=utf8mb4' \
DB_USERNAME=your_test_user DB_PASSWORD='your_test_password' php84 vendor/bin/phpunit
```

**Tests reset their database.** They refuse to reset a MySQL database not named exactly `mailcrab_test`. Do not use that name for valuable data. Tests use fake delivery or terminal AWS SDK mocks, separate ephemeral outbox directories, a fixed clock, and an isolated cache; the MySQL concurrency helper also refuses any other database/provider. All HTTP tests run through Crab's real request handler without starting a server. MySQL-specific concurrency and dispatch-lock checks are skipped on SQLite. Frontend tests exercise the real request client, empty states, saved preview sandboxing, explicit scheduling, and workspace switching.

Migrations use Crab's actual `Migration`, `Schema`, and `Table` APIs; application code uses its `Route`, `App`, PSR middleware, `Database`/transaction/query contracts, console, cache rate limiting, and test client. Composer mirrors the local sibling framework into `mailcrab/vendor` rather than editing it.

## API map

All private endpoints are under `/api/v1`, use JSON, and require the session cookie. Mutations additionally require `X-CSRF-Token` from login or `/auth/me`. Cross-origin API writes are refused. `/auth/login` is the sole unauthenticated private-API entry point. There is no public API registration.

| Method | Path after `/api/v1` | Purpose |
| --- | --- | --- |
| POST | `/auth/login`, `/auth/logout` | Session lifecycle |
| GET | `/auth/me` | Owner, memberships, CSRF token |
| GET / POST | `/workspaces/{w}/subscribers` | Keyset-paginated audience / opt-in record |
| POST | `/workspaces/{w}/subscribers/import` | Bounded CSV import |
| GET | `/workspaces/{w}/subscribers/{id}/consent` | Latest 100 consent events |
| PUT | `/workspaces/{w}/subscribers/{id}/tags` | Replace up to 10 tag names |
| POST | `/workspaces/{w}/subscribers/{id}/suppress` | Persistent opt-out |
| GET | `/workspaces/{w}/tags` | Up to 200 tags |
| GET / POST | `/workspaces/{w}/senders` | List / request verification |
| POST | `/workspaces/{w}/senders/{id}/verify`, `/revoke` | Resend verification / revoke |
| GET / POST | `/workspaces/{w}/campaigns` | Keyset-paginated campaigns / create draft |
| PUT | `/workspaces/{w}/campaigns/{id}` | Update draft only |
| POST | `/workspaces/{w}/campaigns/{id}/schedule`, `/cancel` | Schedule / cancel pending sends |
| POST | `/workspaces/{w}/campaigns/{id}/test` | Owner-only fake test; requires stable `Idempotency-Key` |
| GET | `/workspaces/{w}/campaigns/{id}/preview` | Sandboxed saved HTML preview |
| GET / PUT | `/workspaces/{w}/welcome` | One-email sequence configuration |
| GET | `/workspaces/{w}/outbox` | Latest 100 workspace deliveries |
| GET | `/workspaces/{w}/report?campaign_id={id}` | Workspace or campaign metrics |

Public: `/up`, `/ready`, `/subscribe/{slug}` (GET/POST), `/confirm/{token}` (GET/POST), `/u/{token}` (GET/POST), `/c/{token}` (GET), and signed `/webhooks/fake` (POST). No endpoint runs the worker from the browser.

## Deliberately not built

Complex automation builders, AI copywriting, CRM, billing, referrals, audience scraping, paid-service provisioning, deployment, real campaigns, and a homemade mail server. Account recovery/invitations, granular roles, production retention/erasure tooling, and live transport/rate enforcement are future work before a live pilot.
