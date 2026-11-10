import { useEffect, useState, type FormEvent } from "react";
import { api, localDate, type Sender } from "./api";
import "./delivery.css";

type DnsRecord = {
  purpose: string;
  type: string;
  name: string;
  value: string;
  status: string;
  found: string[];
  priority: number | null;
  target: string;
};
type Domain = {
  id: number;
  domain: string;
  mail_from: string;
  status: string;
  dns: DnsRecord[];
  last_error: string | null;
  checked_at: string | null;
  fresh: boolean;
  identity: { verification: string; dkim: string; mail_from: string } | null;
};
type DeliverySender = Sender & {
  reply_to: string | null;
  domain_status: string;
  domain_fresh: boolean;
  ses_ready: boolean;
};
export type DeliveryOverview = {
  enabled: boolean;
  setup_enabled: boolean;
  region: string;
  region_changed: boolean;
  account: {
    production: boolean;
    sending_enabled: boolean;
    enforcement: string;
    daily_limit: number;
    sent_last_24h: number;
    max_send_rate: number;
  } | null;
  account_checked_at: string | null;
  account_error: string | null;
  feedback: {
    ready: boolean;
    events_connected: boolean;
    subscription_confirmed: boolean;
    suppression_enabled: boolean;
    configuration_sending_enabled: boolean;
    subscription_problem: string | null;
  } | null;
  feedback_error: string | null;
  feedback_checked_at: string | null;
  subscription_pending: boolean;
  subscription_requested: boolean;
  connection_fresh: boolean;
  feedback_fresh: boolean;
  postal_address: string;
  webhook_url: string;
  topic_configured: boolean;
  configuration_set: string;
  webhook_path: string;
  domains: Domain[];
  senders: DeliverySender[];
  default_sender_id: number | null;
  paused: boolean;
  rate_per_second: number;
  checks: {
    id: string;
    label: string;
    ok: boolean;
    reason: string;
    section: string;
  }[];
  ready: boolean;
  real_sending: boolean;
  live_authorized_at?: string | null;
  live_gate_enabled?: boolean;
};
const statusNames: Record<string, string> = {
  not_connected: "Not connected",
  pending: "Pending",
  verified: "Verified",
  error: "Needs attention",
  disconnected: "Disconnected",
  conflict: "Check existing record",
  lookup_failed: "Lookup failed",
};
function Status({ ok, children }: { ok?: boolean; children: React.ReactNode }) {
  return (
    <span className={"setup-status" + (ok ? " is-ready" : "")}>
      <i aria-hidden="true" />
      {children}
    </span>
  );
}
function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
    </label>
  );
}
function SetupCard({
  id,
  title,
  number,
  status,
  initiallyOpen,
  children,
}: {
  id: string;
  title: string;
  number?: string;
  status: React.ReactNode;
  initiallyOpen: boolean;
  children: React.ReactNode;
}) {
  const [open, setOpen] = useState(initiallyOpen);
  return (
    <details
      className="panel setup-card"
      open={open}
      onToggle={(e) => setOpen(e.currentTarget.open)}
    >
      <summary className="setup-card-header">
        <div className="setup-title">
          {number && <span className="step-number">{number}</span>}
          <h2 id={id}>{title}</h2>
        </div>
        {status}
        <span className="section-chevron" aria-hidden="true">
          ⌄
        </span>
      </summary>
      {children}
    </details>
  );
}
function jumpTo(id: string) {
  const heading = document.getElementById(id);
  const details = heading?.closest("details");
  if (details) details.open = true;
  heading?.scrollIntoView?.({ behavior: "smooth", block: "start" });
}
function formData(e: FormEvent<HTMLFormElement>) {
  e.preventDefault();
  return new FormData(e.currentTarget);
}

export default function DeliverySettings({
  path,
  onChanged,
  onActivity,
  initialSection,
}: {
  path: string;
  onChanged: () => Promise<void>;
  onActivity?: () => void;
  initialSection?: string;
}) {
  const [value, setValue] = useState<DeliveryOverview | null>(null),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false);
  const [domainName, setDomainName] = useState(""),
    [expandedDomain, setExpandedDomain] = useState<number | null>(null);
  const [cloudConsent, setCloudConsent] = useState<number | "feedback" | null>(
      null,
    ),
    [acknowledged, setAcknowledged] = useState(false);
  const [senderEdit, setSenderEdit] = useState<DeliverySender | null>(null);
  const [pollingError, setPollingError] = useState("");
  const load = async () => {
    const result = await api<DeliveryOverview>(path + "/delivery");
    setValue(result);
    setPollingError("");
    return result;
  };
  useEffect(() => {
    let live = true;
    const refresh = () =>
      api<DeliveryOverview>(path + "/delivery").then((v) => {
        if (live) {
          setValue(v);
          setPollingError("");
        }
      });
    const failed = () => {
      if (live) {
        setPollingError(
          "Status could not refresh. Reconnect and refresh before relying on these checks.",
        );
        setValue((previous) =>
          previous
            ? {
                ...previous,
                ready: false,
                connection_fresh: false,
                feedback_fresh: false,
                domains: previous.domains.map((d) => ({ ...d, fresh: false })),
                senders: previous.senders.map((s) => ({
                  ...s,
                  domain_fresh: false,
                  ses_ready: false,
                })),
                checks: previous.checks.map((c) =>
                  c.id === "address"
                    ? c
                    : {
                        ...c,
                        ok: false,
                        reason:
                          "Status is unavailable. Refresh before continuing.",
                      },
                ),
              }
            : null,
        );
      }
    };
    void refresh().catch(failed);
    // Refresh cached local state (not AWS) for SNS confirmations and one-hour readiness expiry.
    const timer = window.setInterval(() => {
      void refresh().catch(failed);
    }, 15000);
    return () => {
      live = false;
      window.clearInterval(timer);
    };
  }, [path]);
  const run = async (
    action: () => Promise<unknown>,
    success: string | ((result: unknown) => string),
  ) => {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const result = await action();
      const text = typeof success === "function" ? success(result) : success;
      setMessage(text);
      setCloudConsent(null);
      setAcknowledged(false);
      if (result && typeof result === "object" && "checks" in result)
        setValue(result as DeliveryOverview);
      else
        await load().catch(() => {
          setMessage(
            text +
              " Saved successfully, but status refresh failed. Use Refresh status.",
          );
        });
      // Unrelated audience/report refresh failure must never turn a successful AWS change into a failed action.
      await onChanged().catch(() => {
        setMessage(
          text + " Saved successfully; other workspace data could not refresh.",
        );
      });
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Could not complete this step.",
      );
      await load().catch(() => {});
    } finally {
      setBusy(false);
    }
  };
  const mutate = (suffix: string, body: unknown = {}) =>
    api(path + "/delivery" + suffix, "POST", body);
  const preferences = (
    changes: Partial<
      Pick<
        DeliveryOverview,
        "default_sender_id" | "paused" | "rate_per_second" | "postal_address"
      >
    >,
  ) => api(path + "/delivery/preferences", "PUT", changes);
  const consent = (target: number | "feedback") => {
    setCloudConsent(target);
    setAcknowledged(false);
  };
  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setError("");
      setMessage("Copied to clipboard.");
    } catch {
      setError("Clipboard is unavailable. Select and copy the record below.");
    }
  };
  if (!value)
    return (
      <section className="panel detail">
        <h2>Email delivery</h2>
        {error || pollingError ? (
          <>
            <p className="error" role="alert">
              {error || pollingError}
            </p>
            <button onClick={() => void run(async () => {}, "")}>Retry</button>
          </>
        ) : (
          <p className="muted">Loading delivery settings…</p>
        )}
      </section>
    );
  const completed = value.checks.filter((c) => c.ok).length;
  const nextCheck = value.checks.find((c) => !c.ok);
  const nextSection = initialSection || nextCheck?.section || "ready-title";
  return (
    <div className="delivery-settings" aria-busy={busy}>
      <div className="setup-overview">
        <div>
          <h2>Set up Amazon SES</h2>
          <p>
            Connect your account, authenticate a domain, and configure feedback.
          </p>
        </div>
        <div className="overview-actions">
          <Status>
            {completed} of {value.checks.length} checks passed
          </Status>
          <button
            disabled={busy}
            onClick={() =>
              void run(load, "Status refreshed. No AWS requests were made.")
            }
          >
            Refresh status
          </button>
        </div>
      </div>
      <div className="setup-safety">
        <span className="setup-lock" aria-hidden="true">
          ◇
        </span>
        <div>
          <strong>
            {value.real_sending
              ? value.paused
                ? "SES authorized · sending paused"
                : "Real SES sending is authorized"
              : "Real sending is locked"}
          </strong>
          <p>
            {value.real_sending
              ? "New campaigns, tests, and confirmation messages use SES. Existing fake jobs stay fake. Readiness and shared sending limits are enforced."
              : "Setup does not send email. Campaigns and tests still use the local fake provider."}
          </p>
        </div>
        {value.real_sending && !value.paused && (
          <button
            className="danger"
            disabled={busy}
            onClick={() =>
              void run(
                () => preferences({ paused: true }),
                "SES sending paused. In-flight sends cannot be recalled.",
              )
            }
          >
            Pause sending
          </button>
        )}
      </div>
      {nextCheck && (
        <div className="setup-next">
          <div>
            <strong>Next: {nextCheck.label}</strong>
            <p>{nextCheck.reason}</p>
          </div>
          <button onClick={() => jumpTo(nextCheck.section)}>Continue →</button>
        </div>
      )}
      {busy && (
        <div className="delivery-toast" role="status">
          Working…
        </div>
      )}
      {error && (
        <div className="error delivery-toast" role="alert">
          {error}
        </div>
      )}
      {message && (
        <div className="notice delivery-toast" role="status">
          {message}
        </div>
      )}
      {pollingError && (
        <div className="error" role="alert">
          {pollingError}
        </div>
      )}
      {value.region_changed && (
        <div className="error" role="alert">
          The server’s AWS region changed. Restore {value.region} before
          continuing. Identities cannot be moved between regions.
        </div>
      )}

      <SetupCard
        id="ses-connection-title"
        title="SES connection"
        number="1"
        initiallyOpen={nextSection === "ses-connection-title"}
        status={
          <Status ok={!!value.account && value.connection_fresh}>
            {value.account
              ? value.connection_fresh
                ? "Connected"
                : "Recheck required"
              : "Not connected"}
          </Status>
        }
      >
        <div className="setup-card-body">
          <p className="muted">
            One operator-managed AWS connection. Credentials stay on the server,
            never in your browser.
          </p>
          <div className="connection-meta">
            <div>
              <span>Region</span>
              <strong className="mono">{value.region}</strong>
            </div>
            <div>
              <span>Account mode</span>
              <strong>
                {value.account
                  ? value.account.production
                    ? "Production"
                    : "Sandbox"
                  : "Not checked"}
              </strong>
            </div>
            <div>
              <span>Last checked</span>
              <strong>{localDate(value.account_checked_at)}</strong>
            </div>
          </div>
          {value.account_error && (
            <p className="error">{value.account_error}</p>
          )}
          {value.account && (
            <>
              <div className="quota-row">
                <span>
                  {value.account.sent_last_24h.toLocaleString()} /{" "}
                  {value.account.daily_limit.toLocaleString()} emails in the
                  last 24 hours
                </span>
                <span>{value.account.max_send_rate} emails / second</span>
              </div>
              <meter
                min={0}
                max={value.account.daily_limit || 1}
                value={value.account.sent_last_24h}
                aria-label="SES daily quota usage"
              />
              {!value.account.production && (
                <p className="setup-hint">
                  Sandbox accounts can only send to verified recipients. Request
                  production access in the{" "}
                  <a
                    href={`https://${value.region}.console.aws.amazon.com/ses/home?region=${value.region}#/account`}
                    target="_blank"
                    rel="noreferrer"
                  >
                    SES console ↗
                  </a>
                  . Mailcrab does not request access for you.
                </p>
              )}
              {(!value.account.sending_enabled ||
                value.account.enforcement !== "HEALTHY") && (
                <p className="error">
                  AWS sending is disabled or the account is under review.
                  Resolve this in the SES console.
                </p>
              )}
            </>
          )}
          {!value.enabled && (
            <div className="setup-hint">
              <strong>Operator setup required</strong>
              <p>
                Configure an IAM role or AWS_PROFILE on the server, then enable
                SES checks. No access keys are requested here.
              </p>
            </div>
          )}
          <div className="setup-actions">
            <button
              className={value.account ? "" : "primary"}
              disabled={busy || !value.enabled || value.region_changed}
              onClick={() =>
                void run(
                  () => mutate("/connection/check"),
                  "SES connection checked. No AWS resources were changed.",
                )
              }
            >
              {value.account ? "Recheck connection" : "Check connection"}
            </button>
            <span className="small muted">Read-only AWS request</span>
          </div>
          <details className="setup-details">
            <summary>Server configuration & permissions</summary>
            <pre>{`# .env — restart Crab after changes\nSES_ENABLED=true\nSES_SETUP_ENABLED=true\nAWS_REGION=${value.region}\nAWS_PROFILE=mailcrab\nSES_SNS_TOPIC_ARN=arn:aws:sns:${value.region}:YOUR_ACCOUNT:YOUR_TOPIC`}</pre>
            <p>
              Use a least-privilege IAM role in production. SES_SETUP_ENABLED
              permits only the cloud changes you explicitly confirm below. It
              does not unlock sending.
            </p>
            <p>
              The operator needs SES account/identity/configuration-set
              permissions plus SNS GetTopicAttributes, Subscribe,
              ConfirmSubscription, and GetSubscriptionAttributes. Full policy
              and topic setup: <code>docs/ses-setup.md</code>.
            </p>
          </details>
        </div>
      </SetupCard>

      <SetupCard
        id="domain-title"
        title="Sending domain"
        number="2"
        initiallyOpen={nextSection === "domain-title"}
        status={
          <Status
            ok={value.domains.some((d) => d.status === "verified" && d.fresh)}
          >
            {
              value.domains.filter((d) => d.status === "verified" && d.fresh)
                .length
            }{" "}
            verified
          </Status>
        }
      >
        <div className="setup-card-body">
          <p className="muted">
            Use a domain you own. Your DNS provider stays unchanged; you’ll add
            the records shown here.
          </p>
          <form
            className="domain-add"
            onSubmit={(e) => {
              e.preventDefault();
              void run(async () => {
                const domain = await api<Domain>(
                  path + "/delivery/domains",
                  "POST",
                  { domain: domainName },
                );
                setDomainName("");
                setExpandedDomain(domain.id);
              }, "Domain added locally. Connect it to SES to generate DNS records.");
            }}
          >
            <Field label="Domain">
              <input
                name="domain"
                value={domainName}
                onChange={(e) => setDomainName(e.target.value)}
                placeholder="newsletter.example.com"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                required
                maxLength={253}
              />
            </Field>
            <button
              disabled={busy || !domainName.trim() || value.region_changed}
            >
              Add domain
            </button>
          </form>
          {!value.domains.length && (
            <p className="setup-hint">
              No domains connected. Adding one here only saves it in this
              workspace.
            </p>
          )}
          <div className="domain-list">
            {value.domains.map((domain) => (
              <div className="domain-entry" key={domain.id}>
                <div className="domain-heading">
                  <button
                    className="domain-toggle"
                    aria-expanded={expandedDomain === domain.id}
                    onClick={() =>
                      setExpandedDomain(
                        expandedDomain === domain.id ? null : domain.id,
                      )
                    }
                  >
                    <span aria-hidden="true">
                      {expandedDomain === domain.id ? "⌄" : "›"}
                    </span>
                    <strong>{domain.domain}</strong>
                  </button>
                  <Status ok={domain.status === "verified" && domain.fresh}>
                    {domain.status === "verified" && !domain.fresh
                      ? "Recheck required"
                      : statusNames[domain.status] || domain.status}
                  </Status>
                </div>
                {expandedDomain === domain.id && (
                  <div className="domain-body">
                    {domain.last_error && (
                      <p className="error">{domain.last_error}</p>
                    )}
                    <p className="small muted">
                      MAIL FROM: <code>{domain.mail_from}</code>. Kept separate
                      from your regular email and website.
                    </p>
                    {domain.identity && (
                      <div className="identity-progress">
                        <span>
                          SES identity: {domain.identity.verification}
                        </span>
                        <span>DKIM: {domain.identity.dkim}</span>
                        <span>MAIL FROM: {domain.identity.mail_from}</span>
                      </div>
                    )}
                    {domain.dns.length > 0 && (
                      <>
                        <div className="dns-heading">
                          <h3>DNS records</h3>
                          <button
                            disabled={
                              busy ||
                              !domain.dns.some((r) => r.status === "pending")
                            }
                            onClick={() =>
                              void copy(
                                domain.dns
                                  .filter((r) => r.status === "pending")
                                  .map(
                                    (r) => `${r.type}\t${r.name}\t${r.value}`,
                                  )
                                  .join("\n"),
                              )
                            }
                          >
                            Copy pending records
                          </button>
                        </div>
                        <p className="setup-hint">
                          Use DNS-only (not proxied) CNAMEs. Some providers
                          append your domain automatically. Keep existing DMARC
                          policies; do not add duplicate SPF or DMARC records.
                        </p>
                        <div className="table-wrap">
                          <table className="dns-table">
                            <thead>
                              <tr>
                                <th>Type / purpose</th>
                                <th>Name / host</th>
                                <th>Value / target</th>
                                <th>Status</th>
                              </tr>
                            </thead>
                            <tbody>
                              {domain.dns.map((record) => (
                                <tr key={record.type + record.name}>
                                  <td>
                                    <strong className="mono">
                                      {record.type}
                                    </strong>
                                    <span className="small muted">
                                      {record.purpose}
                                    </span>
                                  </td>
                                  <td>
                                    <code>{record.name}</code>
                                    <button
                                      className="copy-record"
                                      aria-label={`Copy name for ${record.purpose} ${record.name}`}
                                      onClick={() => void copy(record.name)}
                                    >
                                      Copy name
                                    </button>
                                  </td>
                                  <td>
                                    {record.priority !== null && (
                                      <p className="small muted">
                                        Priority: {record.priority}
                                      </p>
                                    )}
                                    <code>{record.target}</code>
                                    <button
                                      className="copy-record"
                                      aria-label={`Copy value for ${record.purpose} ${record.name}`}
                                      onClick={() => void copy(record.target)}
                                    >
                                      Copy value
                                    </button>
                                    {record.status === "conflict" && (
                                      <p className="dns-conflict">
                                        Existing: {record.found.join(" · ")}
                                      </p>
                                    )}
                                  </td>
                                  <td>
                                    <Status ok={record.status === "verified"}>
                                      {statusNames[record.status] ||
                                        record.status}
                                    </Status>
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        <p className="setup-hint">
                          DNS can take up to 72 hours. A lookup failure is not a
                          failed domain; retry after checking your DNS provider.
                          Last checked {localDate(domain.checked_at)}.
                        </p>
                      </>
                    )}
                    <div className="setup-actions">
                      {domain.status !== "not_connected" &&
                        domain.status !== "disconnected" && (
                          <button
                            disabled={
                              busy || !value.enabled || value.region_changed
                            }
                            onClick={() =>
                              void run(
                                () => mutate(`/domains/${domain.id}/check`),
                                "DNS and SES verification status refreshed.",
                              )
                            }
                          >
                            Check DNS records
                          </button>
                        )}
                      {(domain.status === "not_connected" ||
                        domain.status === "disconnected" ||
                        domain.status === "error") && (
                        <button
                          disabled={
                            busy ||
                            !value.enabled ||
                            !value.setup_enabled ||
                            value.region_changed
                          }
                          onClick={() => consent(domain.id)}
                        >
                          Connect to SES
                        </button>
                      )}
                      {domain.status !== "not_connected" &&
                        domain.status !== "disconnected" && (
                          <button
                            className="text-button danger"
                            disabled={busy}
                            onClick={() => {
                              if (
                                window.confirm(
                                  "Disconnect this domain from Mailcrab? This prevents SES readiness; AWS DNS records and identities are left untouched.",
                                )
                              )
                                void run(
                                  () =>
                                    mutate(`/domains/${domain.id}/disconnect`),
                                  "Domain disconnected locally. No DNS or AWS identity was deleted.",
                                );
                            }}
                          >
                            Disconnect
                          </button>
                        )}
                    </div>
                    {(!value.enabled || !value.setup_enabled) && (
                      <p className="setup-hint">
                        The operator must enable SES checks and setup before
                        connecting this domain to AWS.
                      </p>
                    )}
                    {cloudConsent === domain.id && (
                      <div className="cloud-confirm">
                        <strong>
                          Register {domain.domain} with Amazon SES?
                        </strong>
                        <p>
                          This creates or checks the SES identity and sets{" "}
                          <code>{domain.mail_from}</code> as its MAIL FROM
                          subdomain in {value.region}. It does not edit DNS or
                          send email.
                        </p>
                        <label className="check">
                          <input
                            type="checkbox"
                            checked={acknowledged}
                            onChange={(e) => setAcknowledged(e.target.checked)}
                          />{" "}
                          I own this domain and authorize these AWS changes.
                        </label>
                        <div className="actions">
                          <button
                            className="primary"
                            disabled={busy || !acknowledged}
                            onClick={() =>
                              void run(
                                () =>
                                  mutate(`/domains/${domain.id}/connect`, {
                                    authorize_aws: true,
                                  }),
                                "SES identity connected. Add the DNS records below, then check verification.",
                              )
                            }
                          >
                            Generate DNS records
                          </button>
                          <button
                            disabled={busy}
                            onClick={() => setCloudConsent(null)}
                          >
                            Cancel
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </SetupCard>

      <SetupCard
        id="sender-title"
        title="Sender identity"
        number="3"
        initiallyOpen={nextSection === "sender-title"}
        status={
          <Status ok={value.senders.some((s) => s.ses_ready)}>
            {value.senders.filter((s) => s.ses_ready).length} ready
          </Status>
        }
      >
        <div className="setup-card-body">
          <p className="muted">
            Choose the name and address readers see. A sender must use an
            authenticated domain in this workspace.
          </p>
          <form
            key={senderEdit?.id || "new"}
            onSubmit={(e) => {
              const f = formData(e);
              const form = e.currentTarget;
              void run(
                async () => {
                  await api<Sender>(
                    path + "/senders" + (senderEdit ? "/" + senderEdit.id : ""),
                    senderEdit ? "PUT" : "POST",
                    {
                      name: f.get("name"),
                      email: f.get("email"),
                      reply_to: f.get("reply_to"),
                    },
                  );
                  setSenderEdit(null);
                  form.reset();
                },
                senderEdit
                  ? "Sender updated. Existing queued messages are unchanged."
                  : value.real_sending
                    ? "Sender added. If verification is required, its confirmation will be sent by SES once sending is unpaused."
                    : "Sender added. If the domain is not verified, its confirmation is in the local Activity outbox.",
              );
            }}
          >
            <div className="form-row">
              <Field label="From name">
                <input
                  name="name"
                  defaultValue={senderEdit?.name || ""}
                  placeholder="Your team"
                  required
                  maxLength={120}
                />
              </Field>
              <Field label="From email">
                <input
                  name="email"
                  type="email"
                  defaultValue={senderEdit?.email || ""}
                  placeholder={
                    value.domains[0]
                      ? `hello@${value.domains[0].domain}`
                      : "hello@example.com"
                  }
                  required
                  readOnly={!!senderEdit}
                />
              </Field>
            </div>
            <Field label="Reply-To (optional)">
              <input
                name="reply_to"
                type="email"
                defaultValue={senderEdit?.reply_to || ""}
                placeholder="Where replies should go"
              />
            </Field>
            <div className="actions">
              <button disabled={busy}>
                {senderEdit ? "Save sender" : "Add sender"}
              </button>
              {senderEdit && (
                <button type="button" onClick={() => setSenderEdit(null)}>
                  Cancel
                </button>
              )}
            </div>
          </form>
          {value.senders.length > 0 && (
            <div className="sender-setup-list">
              {value.senders.map((sender) => (
                <div className="sender-setup-row" key={sender.id}>
                  <div className="grow">
                    <strong>{sender.name}</strong>
                    <p className="small muted">
                      {sender.email}
                      {sender.reply_to && <> · Replies to {sender.reply_to}</>}
                    </p>
                  </div>
                  <Status ok={sender.ses_ready}>
                    {sender.ses_ready
                      ? "Ready"
                      : sender.verified_at
                        ? "Domain not ready"
                        : "Confirm sender"}
                  </Status>
                  <div className="actions">
                    <button
                      onClick={() => setSenderEdit(sender)}
                      disabled={busy}
                    >
                      Edit
                    </button>
                    {!sender.verified_at && (
                      <button
                        disabled={busy}
                        onClick={() =>
                          void run(
                            () =>
                              api(
                                path + `/senders/${sender.id}/verify`,
                                "POST",
                                {},
                              ),
                            (result) =>
                              (result as { verified: boolean }).verified
                                ? "Sender confirmed using the authenticated domain."
                                : value.real_sending
                                  ? "Confirmation queued through SES. Ask the sender to check their inbox after the worker runs."
                                  : "Confirmation queued in the local Activity outbox.",
                          )
                        }
                      >
                        {sender.domain_status === "verified" &&
                        sender.domain_fresh
                          ? "Verify sender"
                          : "Resend confirmation"}
                      </button>
                    )}
                    {sender.verified_at && (
                      <button
                        className="text-button danger"
                        disabled={busy}
                        onClick={() => {
                          if (
                            window.confirm(
                              "Revoke this sender? Pending jobs using it will be suppressed.",
                            )
                          )
                            void run(
                              () =>
                                api(
                                  path + `/senders/${sender.id}/revoke`,
                                  "POST",
                                  {},
                                ),
                              "Sender revoked.",
                            );
                        }}
                      >
                        Revoke
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
          {value.senders.some((sender) => !sender.verified_at) && (
            <p className="setup-hint">
              Confirmation messages appear in Activity with their original
              transport. Fake links work only before this workspace has ever
              been authorized for SES. Live confirmations go only to the
              sender’s inbox; their links are never exposed here.{" "}
              {onActivity && (
                <button className="text-button" onClick={onActivity}>
                  Open Activity →
                </button>
              )}
            </p>
          )}
          <Field label="Default sender for confirmation emails">
            <select
              value={value.default_sender_id || ""}
              disabled={busy || !value.senders.length}
              onChange={(e) =>
                void run(
                  () =>
                    preferences({ default_sender_id: Number(e.target.value) }),
                  "Default sender saved for future confirmation messages.",
                )
              }
            >
              <option value="" disabled>
                Choose a sender
              </option>
              {value.senders.map((sender) => (
                <option key={sender.id} value={sender.id}>
                  {sender.name} · {sender.email}
                </option>
              ))}
            </select>
          </Field>
          <p className="setup-hint">
            Local confirmation alone does not verify a domain in SES. Campaigns
            can use other verified senders; this default is for system
            confirmation messages once live delivery is explicitly authorized.
          </p>
        </div>
      </SetupCard>

      <SetupCard
        id="feedback-title"
        title="Delivery feedback"
        number="4"
        initiallyOpen={nextSection === "feedback-title"}
        status={
          <Status ok={value.feedback?.ready && value.feedback_fresh}>
            {value.feedback?.ready
              ? value.feedback_fresh
                ? "Connected"
                : "Recheck required"
              : value.subscription_pending
                ? "Confirmation received"
                : value.subscription_requested
                  ? "Waiting for SNS"
                  : "Not connected"}
          </Status>
        }
      >
        <div className="setup-card-body">
          <p className="muted">
            SES reports deliveries, bounces, and complaints through SNS.
            Permanent bounces and complaints suppress future marketing
            automatically.
          </p>
          {value.feedback_error && (
            <p className="error">{value.feedback_error}</p>
          )}
          {value.feedback && !value.feedback.configuration_sending_enabled && (
            <p className="error">
              This SES configuration set has sending disabled. Ask the operator
              to review and enable it in the SES console, then Check feedback.
              Reconnecting does not override this safety switch.
            </p>
          )}
          {value.feedback?.subscription_problem === "raw_delivery_enabled" && (
            <p className="error">
              Raw SNS message delivery is enabled. Turn it off on the SNS
              subscription so signed notifications can be verified, then Check
              feedback.
            </p>
          )}
          {value.feedback?.subscription_problem === "endpoint_mismatch" && (
            <p className="error">
              The SNS subscription endpoint or topic does not match this
              workspace. Check APP_URL and reconnect feedback.
            </p>
          )}
          <div className="feedback-checks">
            <span>
              Event publishing{" "}
              <strong>
                {value.feedback?.events_connected
                  ? "Connected"
                  : value.feedback
                    ? "Missing event destination"
                    : "Not checked"}
              </strong>
            </span>
            <span>
              HTTPS subscription{" "}
              <strong>
                {value.feedback?.subscription_confirmed
                  ? "Confirmed"
                  : "Pending"}
              </strong>
            </span>
            <span>
              SES suppression{" "}
              <strong>
                {value.feedback?.suppression_enabled
                  ? "Enabled"
                  : value.feedback
                    ? "Disabled — reconnect to repair"
                    : "Not checked"}
              </strong>
            </span>
          </div>
          {!value.topic_configured && (
            <p className="setup-hint">
              Operator action: supply an existing SNS topic in{" "}
              <code>SES_SNS_TOPIC_ARN</code>. No topic is created automatically.
            </p>
          )}
          {value.subscription_requested &&
            !value.subscription_pending &&
            !value.feedback?.subscription_confirmed && (
              <p className="setup-hint" role="status">
                Waiting for the signed SNS confirmation. This page checks for it
                every 15 seconds. Use Refresh status to check now; no AWS
                changes are made.
              </p>
            )}
          <div className="setup-actions">
            {!value.feedback?.ready && (
              <button
                disabled={
                  busy ||
                  !value.setup_enabled ||
                  !value.enabled ||
                  !value.topic_configured ||
                  value.region_changed
                }
                onClick={() => consent("feedback")}
              >
                {value.subscription_pending
                  ? "Reconnect feedback"
                  : "Connect feedback"}
              </button>
            )}
            {value.subscription_pending && (
              <button
                className="primary"
                disabled={busy || !value.setup_enabled}
                onClick={() => {
                  if (
                    window.confirm(
                      "Confirm the signed SNS subscription using the configured AWS account? No email will be sent.",
                    )
                  )
                    void run(
                      () =>
                        mutate("/feedback/confirm", { authorize_aws: true }),
                      "SNS subscription confirmed and feedback configuration checked.",
                    );
                }}
              >
                Confirm subscription
              </button>
            )}
            <button
              disabled={
                busy ||
                !value.enabled ||
                !value.topic_configured ||
                value.region_changed
              }
              onClick={() =>
                void run(
                  () => mutate("/feedback/check"),
                  "Feedback configuration checked.",
                )
              }
            >
              Check feedback
            </button>
          </div>
          {cloudConsent === "feedback" && (
            <div className="cloud-confirm">
              <strong>Connect SES events to your existing SNS topic?</strong>
              <p>
                This creates or updates configuration set{" "}
                <code>{value.configuration_set}</code> and subscribes this
                workspace’s HTTPS webhook to the operator-provided topic. It
                also enables bounce and complaint suppression on that
                configuration set. SNS usage may incur AWS charges. No topic,
                paid plan, or email campaign is created.
              </p>
              <label className="check">
                <input
                  type="checkbox"
                  checked={acknowledged}
                  onChange={(e) => setAcknowledged(e.target.checked)}
                />{" "}
                I authorize these AWS configuration changes and the SNS
                subscription.
              </label>
              <div className="actions">
                <button
                  className="primary"
                  disabled={busy || !acknowledged}
                  onClick={() =>
                    void run(
                      () =>
                        mutate("/feedback/connect", { authorize_aws: true }),
                      (result) =>
                        (result as DeliveryOverview).feedback
                          ?.subscription_confirmed
                          ? "Feedback is connected and the existing subscription is already confirmed."
                          : (result as DeliveryOverview).subscription_pending
                            ? "Signed confirmation received. Confirm the subscription below."
                            : "Feedback connection requested. Waiting for the signed SNS confirmation; this page updates automatically.",
                    )
                  }
                >
                  Set up feedback
                </button>
                <button disabled={busy} onClick={() => setCloudConsent(null)}>
                  Cancel
                </button>
              </div>
            </div>
          )}
          <details className="setup-details">
            <summary>Webhook & troubleshooting</summary>
            <p>
              Endpoint: <code>{value.webhook_url}</code>. APP_URL must be your
              public HTTPS origin. Localhost cannot receive SNS callbacks.
            </p>
            <p>
              Configuration set: <code>{value.configuration_set}</code>. Use
              standard signed JSON notifications, not raw message delivery. The
              SNS topic policy must allow SES to publish. After Connect
              feedback, refresh this page to see a received confirmation, then
              confirm it.
            </p>
            <p>
              Last checked {localDate(value.feedback_checked_at)}. This verifies
              configuration, not a real end-to-end delivery test. Unmatched
              events are stored and reconciled by the bounded worker; no email
              is resent.
            </p>
          </details>
        </div>
      </SetupCard>

      <SetupCard
        id="ready-title"
        title="Readiness"
        initiallyOpen={nextSection === "ready-title"}
        status={
          <Status ok={value.ready}>
            {value.ready ? "Ready for launch review" : "Setup incomplete"}
          </Status>
        }
      >
        <div className="setup-card-body">
          <ul className="readiness-list">
            {value.checks.map((check) => (
              <li key={check.id}>
                <span aria-hidden="true">{check.ok ? "✓" : "○"}</span>
                <div className="grow">
                  {check.label}
                  {!check.ok && <p className="small muted">{check.reason}</p>}
                </div>
                {check.ok ? (
                  <span>Passed</span>
                ) : (
                  <button
                    className="text-button"
                    onClick={() => jumpTo(check.section)}
                  >
                    Fix →
                  </button>
                )}
              </li>
            ))}
          </ul>
          <form
            className="postal-address"
            onSubmit={(e) => {
              const f = formData(e);
              void run(
                () =>
                  preferences({
                    postal_address: String(f.get("postal_address")),
                  }),
                "Postal address saved for future messages.",
              );
            }}
          >
            <Field label="Postal mailing address">
              <textarea
                name="postal_address"
                key={value.postal_address}
                defaultValue={value.postal_address}
                rows={2}
                maxLength={500}
                required
              />
            </Field>
            <button disabled={busy}>Save address</button>
          </form>
          <details className="setup-details">
            <summary>
              {value.real_sending
                ? "Sending controls"
                : "Future launch controls"}
            </summary>
            <form
              className="sending-controls"
              onSubmit={(e) => {
                const f = formData(e);
                void run(
                  () =>
                    preferences({
                      rate_per_second: Number(f.get("rate")),
                    }),
                  value.real_sending
                    ? "SES sending controls saved. Fake jobs are unchanged."
                    : "Launch controls saved. Real sending is still locked.",
                );
              }}
            >
              <Field label="Maximum emails per second">
                <input
                  name="rate"
                  type="number"
                  min={1}
                  max={100}
                  defaultValue={value.rate_per_second}
                  required
                />
              </Field>
              <label className="check">
                <input
                  name="paused"
                  type="checkbox"
                  checked={value.paused}
                  disabled={busy}
                  onChange={(e) => {
                    const paused = e.currentTarget.checked;
                    void run(
                      () => preferences({ paused }),
                      paused
                        ? "SES sending paused. In-flight sends cannot be recalled."
                        : "SES pause removed. Authorized SES jobs can send once all readiness checks pass.",
                    );
                  }}
                />{" "}
                {value.real_sending
                  ? "Pause SES sending"
                  : "Keep SES sending paused after authorization"}
              </label>
              <button disabled={busy}>Save controls</button>
            </form>
          </details>
          <p className="setup-hint">
            {value.real_sending
              ? "SES sending is explicitly authorized. Pausing or revoking authorization defers SES jobs without consuming retry attempts. Shared rate slots and a conservative daily quota guard are enforced before every send. In-flight sends cannot be recalled."
              : "These controls cannot unlock real sending. An operator must enable the separate server live gate and explicitly authorize this workspace through the CLI. Authorization starts paused. Existing fake jobs, campaigns and welcome sequences never become real implicitly."}{" "}
            Setup checks expire after one hour; refresh them before sending.
          </p>
        </div>
      </SetupCard>
    </div>
  );
}
