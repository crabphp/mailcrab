# Amazon SES and sending-domain setup

Mailcrab's **Settings → Email delivery** is a working onboarding flow. AWS credentials remain on the server. Opening the page reads only local state. No AWS resources, DNS records, paid SNS topics, production-access requests, or real emails are created automatically.

**Live SES is implemented but disabled by default and has not been exercised against a real account.** Setup and a green checklist cannot unlock it. A separate server live gate, audited per-workspace CLI authorization, fresh readiness, and unpausing are required. Existing fake messages stay fake. The worker deliberately rejects `DELIVERY_PROVIDER=ses`; keep `DELIVERY_PROVIDER=fake`, because the guarded router—not an environment-only provider replacement—selects each message's immutable transport.

## 1. Operator connection

Use PHP 8.4 with curl, OpenSSL, PDO MySQL and the installed AWS SDK. Run `php84 cua migrate` to add delivery setup tables. Tests use dedicated databases and SDK mocks, never ambient AWS credentials.

In this project's private `.env`:

```dotenv
SES_ENABLED=true
SES_SETUP_ENABLED=true
SES_LIVE_ENABLED=false
AWS_REGION=us-east-1
AWS_PROFILE=mailcrab
SES_CONFIGURATION_PREFIX=mailcrab
SES_SNS_TOPIC_ARN=arn:aws:sns:us-east-1:YOUR_ACCOUNT:YOUR_EXISTING_TOPIC
APP_URL=https://your-mailcrab-host.example.com
DELIVERY_PROVIDER=fake
```

These are **instructions for an authorized operator**, not a request to provision AWS now. Configure an existing AWS profile locally or use an IAM workload role on a server. Prefer temporary credentials. Do not put access keys in the dashboard, repository, screenshots, support tickets, or logs. If environment credentials are necessary, standard `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and optional `AWS_SESSION_TOKEN` are consumed by the SDK's server-side credential chain; the UI never returns them.

Restart Crab after environment changes. `SES_ENABLED` permits explicit read checks. `SES_SETUP_ENABLED` additionally allows cloud-changing actions, but each still requires an authenticated, CSRF-protected request with `authorize_aws: true`, presented by a specific confirmation in the UI. No AWS clients resolve credentials on page load. SDK retries are disabled and network requests have bounded timeouts.

**Check connection** calls `GetAccount` and shows region, sandbox/production mode, enforcement, daily quota, recent use, and sending rate. AWS errors are translated to safe guidance rather than exposing credential/provider internals. If AWS_REGION changes later, setup is blocked rather than silently moving or adopting another region's identities.

### IAM permissions

The onboarding principal does **not** need SendEmail, IAM administration, Route53 writes, SNS CreateTopic, or any account/billing provisioning permission. Scope identity operations to the exact domains the operator approves and configuration operations to `mailcrab-workspace-*`. Use the account's configured region and account ID in the resource ARNs below.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["ses:GetAccount"],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": ["ses:GetEmailIdentity", "ses:CreateEmailIdentity", "ses:PutEmailIdentityMailFromAttributes"],
      "Resource": "arn:aws:ses:REGION:ACCOUNT:identity/YOUR_APPROVED_DOMAIN"
    },
    {
      "Effect": "Allow",
      "Action": ["ses:GetConfigurationSet", "ses:CreateConfigurationSet", "ses:GetConfigurationSetEventDestinations", "ses:CreateConfigurationSetEventDestination", "ses:UpdateConfigurationSetEventDestination", "ses:PutConfigurationSetSuppressionOptions"],
      "Resource": "arn:aws:ses:REGION:ACCOUNT:configuration-set/mailcrab-workspace-*"
    },
    {
      "Effect": "Allow",
      "Action": ["sns:GetTopicAttributes", "sns:Subscribe", "sns:ConfirmSubscription"],
      "Resource": "arn:aws:sns:REGION:ACCOUNT:YOUR_EXISTING_TOPIC"
    },
    {
      "Effect": "Allow",
      "Action": ["sns:GetSubscriptionAttributes"],
      "Resource": "arn:aws:sns:REGION:ACCOUNT:YOUR_EXISTING_TOPIC:*"
    }
  ]
}
```

This template must be reviewed and adapted by the AWS account administrator, including tag-on-create permissions if required by their organization policies. The app creates `mailcrab_workspace=<workspace ID>` tags and refuses to modify pre-existing identities/configuration sets that lack the matching tag. For an existing identity, review ownership first, then have an authorized operator add the tag in AWS. Merely knowing a domain name does not authorize adoption. Never retag another application's identity casually.

The AWS commercial partition is supported; GovCloud/China are intentionally not accepted by this slice. SES must actually be available in the chosen commercial region. Do not add an arbitrary custom AWS endpoint.

## 2. Sending domain and DNS

**Add domain** saves a workspace-local reservation; it makes no AWS call. Use an exact public ASCII hostname, such as `news.example.com`, without scheme, wildcard, email address, port or path. At most 20 domains can be added. A domain cannot be claimed by two workspaces. Disconnect retains the reservation/audit trail and never deletes its AWS identity or DNS.

**Connect to SES** requires explicit acknowledgement. It:

1. Reads the identity, or creates an Easy DKIM identity tagged to the workspace if absent.
2. Refuses identities owned by another workspace or unreviewed pre-existing identities.
3. Configures the separate `bounce.<sending-domain>` custom MAIL FROM with **REJECT_MESSAGE** on MX failure.
4. Reads the three Easy DKIM tokens and checks their DNS records plus MAIL FROM, SPF and DMARC.

It does **not** touch your DNS provider. BYODKIM identities without three Easy DKIM tokens require operator conversion/review; the UI does not fabricate token values.

The DNS table provides exact record names and copyable targets:

- Three DKIM CNAMEs, generated from SES-returned tokens. Use DNS-only/unproxied records.
- MAIL FROM MX, with priority **10 shown separately** and target `feedback-smtp.<region>.amazonses.com`.
- MAIL FROM TXT SPF including `amazonses.com`. This belongs on the dedicated MAIL FROM subdomain, not your ordinary mailbox domain.
- A DMARC TXT policy on the exact sending domain. `v=DMARC1; p=none;` is only a starting example if none exists. Keep accepted existing `quarantine` or `reject` policies.

Do not duplicate SPF or DMARC records or overwrite existing policies. Accepted existing policy values remain visible. **Copy pending records** exports only genuinely missing records. It excludes satisfied, conflicting, and lookup-failed records, so it does not export a downgrade for an existing stricter DMARC policy. Resolve conflicts in your DNS provider first; do not paste an example policy over one. Providers that append the zone name may need a relative host label; the table always shows the full name. MX target-only copy excludes priority.

Verification is conservative: the exact six records must be visible, SES identity sending status and DKIM must succeed, signing must be enabled, and custom MAIL FROM must match and be successful with reject-on-failure. The UI separately shows SES identity, DKIM and MAIL FROM progress—DNS propagation alone does not immediately make SES ready. Checks can take up to 72 hours to settle. DNS lookup failures remain distinguishable from missing/conflicting records.

Only exact sending-domain DMARC is checked; organizational-domain inheritance/public-suffix discovery is not implemented. If your existing policy is inherited, the conservative readiness gate asks the operator to publish an explicit compatible policy on the sending subdomain. DNS lookups use the host resolver; configure short resolver timeouts on the host. No remote DNS-editing API is used.

## 3. Sender identity

Sender management is consolidated in Email delivery. Specify From name, From address, optional Reply-To, and a default sender for future confirmation emails. Names cannot contain header newlines. Reply-To is a single validated address. Queued messages snapshot Reply-To and are not changed when a sender is edited.

A recently verified workspace domain can confirm a new exact-domain sender without another fake confirmation message. A sender added earlier can use **Verify sender** once the domain is ready. Otherwise confirmation remains a local development message in Activity; the UI provides resend and navigation to it. Local email confirmation is clearly distinct from SES readiness. Revocation blocks pending jobs at claim time. New domains do not re-enable revoked senders automatically.

The first sender becomes the default atomically when settings exist. Default-sender selections are workspace scoped. Edit the required postal mailing address under Readiness; snapshots in already queued messages remain unchanged.

## 4. SNS feedback

Use an **existing** SNS topic in the same region, supplied by the operator. The app does not create a paid SNS topic or alter its policy. Before authorizing setup, make the exact APP_URL webhook publicly reachable over HTTPS. Localhost cannot receive SNS messages. The dashboard shows the server's APP_URL endpoint, not the browser's potentially different Vite/proxy origin.

The topic's administrator must allow SES publishing. A typical topic policy statement is:

```json
{
  "Effect": "Allow",
  "Principal": {"Service": "ses.amazonaws.com"},
  "Action": "sns:Publish",
  "Resource": "arn:aws:sns:REGION:ACCOUNT:YOUR_EXISTING_TOPIC",
  "Condition": {
    "StringEquals": {"AWS:SourceAccount": "ACCOUNT"},
    "ArnLike": {"AWS:SourceArn": "arn:aws:ses:REGION:ACCOUNT:configuration-set/mailcrab-workspace-*"}
  }
}
```

Review the actual AWS source ARN and topic-policy requirements for your account; do not replace unrelated policy statements. If using a KMS-encrypted topic, the operator must separately configure appropriate SES/KMS access. This app does not grant it.

**Connect feedback** discloses and requires authorization for creating/updating a workspace-tagged SES configuration set, enabling its BOUNCE/COMPLAINT suppression, setting a standard SNS event destination, and subscribing the exact workspace webhook. Existing owned configuration sets are repaired too. Usage can incur SNS charges, which the confirmation explicitly states. Cloud operations are idempotent by identity/configuration-set name and topic/endpoint; automatic SDK retries remain off. No real email is sent.

SNS then POSTs a signed SubscriptionConfirmation. The ingress validates it and stores its token server-side; it **never fetches SubscribeURL or auto-confirms**. The page polls local cached state every 15 seconds and offers **Refresh status**. Once received, the operator clicks **Confirm subscription**, explicitly authorizing the SNS API confirmation. The app sends the stored token to the exact configured topic through the AWS SDK and checks the resulting subscription. Already-confirmed subscriptions are detected rather than sending the user into another wait loop.

Feedback readiness requires:

- A correctly tagged SES configuration set with enabled DELIVERY/BOUNCE/COMPLAINT event publishing to the exact topic.
- BOUNCE and COMPLAINT suppression enabled on that set.
- An HTTPS subscription confirmed for the exact endpoint/topic, with **RawMessageDelivery=false**.
- Recent successful status checks.

This is configuration verification, **not proof of an end-to-end real send**. That exercise requires separate user authorization and controlled addresses.

### Signature validation and durable event handling

`POST /webhooks/ses/{workspace}` accepts standard signed SNS JSON (including its normal text/plain content type):

- The exact topic/account/region must match the configured ARN before a certificate is fetched.
- The certificate URL must be HTTPS on the exact regional SNS host and match `/SimpleNotificationService-*.pem`, with no port, credentials, query, fragment, or redirects. Fetches enforce TLS verification, timeouts and a 32 KiB bound.
- The maintained AWS SNS message-validator package performs signature verification. Messages more than five minutes in the future or thirty days old are rejected, allowing SNS's bounded retry period. Replay safety comes from durable topic/message IDs.
- Events must include the exact workspace configuration-set tag. Other apps/workspaces sharing the topic are ignored without persisting their payloads.
- Only normalized fields required for matching/feedback are stored, not arbitrary raw messages/headers. Matching requires workspace + provider message ID + recipient. A callback racing provider acceptance can reconcile a signed delivery/workspace tag only if recipient and in-flight/unknown row also match.
- Inbox application, receipt dedupe and suppression use the same held database connection/transaction. A permanent bounce or complaint suppresses; transient bounces/delivery delays are reported without suppressing on their own. Rejection/rendering failures fail the job without resending.
- Unmatched events retry through `mailcrab:tick`, with bounded batches/backoff and twelve attempts, then become `unmatched` for operator investigation. Unknown send outcomes are never blindly retried. A signed UnsubscribeConfirmation invalidates feedback readiness.

## Readiness and launch boundary

Each readiness failure names the missing/expired requirement and links back to its setup section. Account, domain and feedback checks expire after an hour. The page periodically refreshes local timestamps/status, **not AWS**, so an expired check does not stay falsely green. AWS checks are explicit user actions. Recently accepted existing configuration is never treated as fresh forever.

A fully green checklist is **Ready for launch review**, not sending enabled. The rate and pause controls are enforced for authorized SES messages, but cannot activate SES. Review consent provenance, the postal/legal footer, privacy/retention, production access, IAM, monitoring and rollback with the user before any launch. This runtime deliberately refuses SES sandbox sending; production access must be requested manually, never by the application.

### Explicit launch and emergency stop (operator only)

**Do not execute these steps without user direction.** No live authorization or real send was performed during development. First audit the audience: never include synthetic test contacts, scraped/purchased addresses, or records without genuine first-party permission. Perform the first authorized end-to-end exercise only with user-controlled addresses and verify the signed feedback loop before any wider campaign.

1. Keep `DELIVERY_PROVIDER=fake`. After setup and review, the operator may explicitly set `SES_LIVE_ENABLED=true` in the private server environment and restart the server and one-shot worker environment. Environment settings alone cannot authorize any workspace.
2. The runtime IAM role needs scoped `ses:SendEmail`/`ses:SendRawEmail` permission for the owned sender identities and workspace configuration set, in addition to read-only account/identity checks. Do not add sending permission to a setup-only role automatically. Restrict `ses:Recipients` to controlled test addresses during the pilot where practical.
3. With every readiness check fresh, authorize the exact workspace with an audit reason:

   ```sh
   php84 cua mailcrab:ses-authorize --workspace=ID --confirm=EXACT_WORKSPACE_SLUG \
     --reason='Explicit user authorization for controlled pilot; operator reference'
   ```

   This writes an authorization event and **starts paused**; it sends nothing. It invalidates outstanding fake confirmation challenges. Fake-only DOI remains simulation-only, even if completed before authorization; those subscribers must request and complete a new real DOI using the signup page. Recorded imports require original legitimate evidence. Subsequent disabling of the server gate does not turn simulation confirmations into valid live consent.
4. Settings shows the authorized/paused state and an immediate **Pause sending** action when active. Explicitly remove the pause only when ready. Save rate and pause independently: unrelated preference changes cannot resume sending. Each real test, campaign schedule and welcome enablement requires a separate browser confirmation, checked again inside the workspace transaction.
5. Scheduled campaigns and queued messages retain their original transport. An existing fake welcome stays fake until explicitly saved for future SES enrollments. Activity labels each row; live confirmation capabilities—and all confirmation capabilities in an ever-authorized workspace—are never returned to the browser. Reports separate real outcomes from simulated ones.

For emergency stop, click **Pause sending**, or revoke authorization from the CLI (works even when the server live gate is off):

```sh
php84 cua mailcrab:ses-authorize --workspace=ID --confirm=EXACT_WORKSPACE_SLUG \
  --reason='Operator emergency stop; incident reference' --revoke
```

Revocation records an audit event and pauses SES. Pending SES jobs defer without consuming retry attempts; they never silently fall back to fake. Already in-flight or accepted sends cannot be recalled. The global `SES_LIVE_ENABLED=false` gate is an additional server-wide stop after restarting all relevant processes.

### Enforced dispatch limits and failure behavior

Fresh readiness is rechecked after external account/identity preflight, under the workspace lock, so local revocation, disconnect, suppression or pause during preflight blocks admission. Live identity checks require verified SES identity, successful DKIM signing, and successful fail-closed custom MAIL FROM. Readiness expires after one hour: long campaigns defer until an operator refreshes account, domain and feedback checks.

An account/region-scoped MySQL named mutex spans **committed** admission and `SendEmail`; identity network checks and MIME preparation happen before quota reservation. This serializes dispatch across workspaces/processes, with a conservative post-dispatch cooldown capped by both the workspace rate and fresh AWS rate. SQLite's file mutex is development-only, not a multi-host production alternative. Use synchronized server clocks. No send occurs inside a database transaction whose rollback could erase its reservation.

Quota admission includes every local attempt reserved in the last 24 hours **plus** AWS's reported use, even when the two overlap. This deliberately under-uses quota rather than trusting eventually consistent AWS counters. Other applications sharing the SES account can still consume quota; AWS remains authoritative. Preflight/pause/rate/quota failures do not consume attempts or create reservations. Once dispatch begins, reservations survive rejection or ambiguity. Explicit throttling retries with bounded backoff; connection loss/unknown acceptance is never automatically retried. Signed feedback can reconcile an unknown outcome. Set operational retention/backup policies for authorization events, reservations, consent and delivery data before production.

## Verification

```sh
php84 vendor/bin/phpunit
npm --prefix dashboard test
npm --prefix dashboard run build
```

The SDK-mocked runtime suite additionally covers explicit authorization and per-send confirmation, immutable fake snapshots, default-sender confirmation routing, invalidated fake capabilities and consent provenance, pause/revocation, local revocation during preflight, shared rate/quota admission, MySQL dispatch locking, zero reservation on identity failure, mixed-transport reports and ambiguous outcomes. Frontend regressions cover explicit real tests, immediate pause followed by a rate edit without resuming, and separate simulated metrics.

The setup suite covers read-only initial load, credential redaction, authorized cloud mutations, identity ownership, DNS policy preservation, workspace isolation, Reply-To, SNS signatures/SSRF restrictions, explicit subscription confirmation, event dedupe, suppression, transient feedback, acceptance races, bounded reconciliation and stale readiness. All AWS actions use terminal SDK mocks; certificate tests use an in-memory self-signed test certificate and real signature verification. No paid resources or real messages are created by tests.
