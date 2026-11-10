import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import DeliverySettings from "./DeliverySettings";
import {
  api,
  ApiError,
  setCsrf,
  sendTest,
  localDate,
  previewText,
  type Session,
  type Workspace,
  type Sender,
  type Tag,
  type Campaign,
  type Subscriber,
  type Welcome,
  type Delivery,
  type Report,
} from "./api";

type Page =
  | "Campaigns"
  | "Audience"
  | "Welcome"
  | "Senders"
  | "Reports"
  | "Activity"
  | "Settings";
const nav: [Page, string][] = [
  ["Campaigns", "↗"],
  ["Audience", "◎"],
  ["Welcome", "✦"],
  ["Reports", "▥"],
  ["Activity", "≋"],
  ["Settings", "⚙"],
];
const blankReport: Report = {
  subscribers: [],
  deliveries: [],
  accepted: 0,
  events: [],
  note: "",
};
const count = (rows: { status: string; count: number }[], status: string) =>
  Number(rows.find((r) => r.status === status)?.count || 0);
function Badge({ children }: { children: ReactNode }) {
  return (
    <span className={"badge " + String(children).replaceAll(" ", "-")}>
      {children}
    </span>
  );
}
function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
    </label>
  );
}
function Empty({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="empty">
      <div className="empty-icon">✉</div>
      <h3>{title}</h3>
      <p>{children}</p>
    </div>
  );
}
const data = (event: FormEvent<HTMLFormElement>) => {
  event.preventDefault();
  return new FormData(event.currentTarget);
};

export default function App() {
  const [session, setSession] = useState<Session | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState("");
  const [workspaceId, setWorkspaceId] = useState(0);
  const restore = async () => {
    try {
      const s = await api<Session>("/auth/me");
      setCsrf(s.csrf);
      setSession(s);
      setWorkspaceId(Number(s.workspaces[0]?.id || 0));
    } catch (e) {
      if (!(e instanceof ApiError && e.status === 401)) setError(String(e));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    void restore();
  }, []);
  if (loading)
    return (
      <main className="login">
        <div className="wordmark">
          mailcrab<span> /</span>
        </div>
        <p>Opening your workspace…</p>
      </main>
    );
  if (!session)
    return (
      <main className="login">
        <div className="login-art">
          <div className="wordmark light">
            mailcrab<span> /</span>
          </div>
          <div>
            <span className="eyebrow">LESS SOFTWARE. MORE CONNECTION.</span>
            <h1>
              Good emails.
              <br />
              Nothing extra.
            </h1>
            <p>
              A little home for your newsletter.
              <br />
              Built for the people who want to hear from you.
            </p>
          </div>
          <span className="mono">CONSENT FIRST · ALWAYS</span>
        </div>
        <div className="login-form">
          <span className="eyebrow">WELCOME BACK</span>
          <h2>
            Make a little
            <br />
            inbox impact.
          </h2>
          <p className="muted">Sign in to your Mailcrab workspace.</p>
          {error && (
            <div role="alert" className="error">
              {error}
            </div>
          )}
          <form
            onSubmit={async (e) => {
              const f = data(e);
              setError("");
              setLoading(true);
              try {
                await api("/auth/login", "POST", {
                  email: f.get("email"),
                  password: f.get("password"),
                });
                await restore();
              } catch (err) {
                setError(
                  err instanceof Error ? err.message : "Sign-in failed.",
                );
                setLoading(false);
              }
            }}
          >
            <Field label="Email address">
              <input
                name="email"
                type="email"
                autoComplete="username"
                required
              />
            </Field>
            <Field label="Password">
              <input
                name="password"
                type="password"
                autoComplete="current-password"
                required
              />
            </Field>
            <button className="primary wide">
              Sign in <span>↗</span>
            </button>
          </form>
          <p className="small muted">
            First visit? Create an owner with{" "}
            <code>php84 cua mailcrab:workspace</code>. See the setup guide.
          </p>
          <div className="safe-note">
            ◉ Workspace delivery mode is shown after sign-in
          </div>
        </div>
      </main>
    );
  const workspace = session.workspaces.find(
    (w) => Number(w.id) === workspaceId,
  );
  if (!workspace)
    return (
      <main className="login">
        <h1>No workspace memberships</h1>
        <p>Create a workspace from the CLI.</p>
      </main>
    );
  return (
    <WorkspaceView
      key={workspaceId}
      workspace={workspace}
      session={session}
      onWorkspace={setWorkspaceId}
      onLogout={async () => {
        await api("/auth/logout", "POST");
        setSession(null);
        setCsrf("");
      }}
    />
  );
}

function WorkspaceView({
  workspace,
  session,
  onWorkspace,
  onLogout,
}: {
  workspace: Workspace;
  session: Session;
  onWorkspace: (id: number) => void;
  onLogout: () => Promise<void>;
}) {
  const [page, setPage] = useState<Page>("Campaigns"),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false),
    [loaded, setLoaded] = useState(false);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]),
    [senders, setSenders] = useState<Sender[]>([]),
    [subscribers, setSubscribers] = useState<Subscriber[]>([]),
    [tags, setTags] = useState<Tag[]>([]),
    [welcome, setWelcome] = useState<Welcome | null>(null),
    [deliveries, setDeliveries] = useState<Delivery[]>([]),
    [report, setReport] = useState<Report>(blankReport);
  const [subscriberNext, setSubscriberNext] = useState<number | null>(null),
    [campaignNext, setCampaignNext] = useState<number | null>(null);
  const [editor, setEditor] = useState<Campaign | "new" | null>(null),
    [audienceMode, setAudienceMode] = useState<"list" | "add" | "import">(
      "list",
    ),
    [audit, setAudit] = useState<unknown[] | null>(null),
    [tagEdit, setTagEdit] = useState<Subscriber | null>(null);
  const [settingsSection, setSettingsSection] = useState<string | undefined>();
  const [deliveryMode, setDeliveryMode] = useState(
    workspace.delivery_mode || "fake",
  );
  const path = `/workspaces/${workspace.id}`;
  const refresh = async () => {
    const [c, s, a, t, w, d, r, currentSession] = await Promise.all([
      api<{ data: Campaign[]; next: number | null }>(path + "/campaigns"),
      api<{ data: Sender[] }>(path + "/senders"),
      api<{ data: Subscriber[]; next: number | null }>(path + "/subscribers"),
      api<{ data: Tag[] }>(path + "/tags"),
      api<{ data: Welcome | null }>(path + "/welcome"),
      api<{ data: Delivery[] }>(path + "/outbox"),
      api<Report>(path + "/report"),
      api<Session>("/auth/me"),
    ]);
    setDeliveryMode(
      currentSession.workspaces.find(
        (w) => Number(w.id) === Number(workspace.id),
      )?.delivery_mode || "fake",
    );
    setCampaigns(c.data);
    setCampaignNext(c.next);
    setSenders(s.data);
    setSubscribers(a.data);
    setSubscriberNext(a.next);
    setTags(t.data);
    setWelcome(w.data);
    setDeliveries(d.data);
    setReport(r);
    setLoaded(true);
  };
  const run = async (action: () => Promise<unknown>, message = "") => {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await action();
      await refresh();
      setNotice(message);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Request failed.");
    } finally {
      setBusy(false);
    }
  };
  useEffect(() => {
    void run(async () => {});
  }, []);
  const active = count(report.subscribers, "active");
  const realSending = deliveryMode === "ses";
  const setNav = (p: Page) => {
    setSettingsSection(p === "Senders" ? "sender-title" : undefined);
    setPage(p === "Senders" ? "Settings" : p);
    setEditor(null);
    setAudit(null);
    setAudienceMode("list");
    setNotice("");
    setError("");
  };
  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="wordmark" href="/">
          mailcrab<span> /</span>
        </a>
        <label className="workspace-label">
          WORKSPACE
          <select
            aria-label="Workspace"
            value={workspace.id}
            onChange={(e) => onWorkspace(Number(e.target.value))}
          >
            {session.workspaces.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </label>
        <nav>
          {nav.map(([p, icon]) => (
            <button
              key={p}
              className={page === p ? "nav active" : "nav"}
              onClick={() => setNav(p)}
            >
              <span>{icon}</span>
              {p}
              {p === "Welcome" && <small>01</small>}
            </button>
          ))}
        </nav>
        <a
          className="signup-link"
          href={"/subscribe/" + workspace.slug}
          target="_blank"
          rel="noreferrer"
        >
          Your signup page <span>↗</span>
        </a>
        <div className="sidebar-bottom">
          <div className="avatar">
            {session.user.email.slice(0, 1).toUpperCase()}
          </div>
          <div>
            <strong>Workspace owner</strong>
            <span title={session.user.email}>{session.user.email}</span>
          </div>
          <button
            title="Sign out"
            aria-label="Sign out"
            onClick={() => void run(onLogout)}
          >
            ↪
          </button>
        </div>
      </aside>
      <div className="main-shell">
        <div className="dev-bar">
          <span>
            <i /> {realSending ? "SES MODE · REAL EMAIL" : "DEVELOPMENT MODE"}
          </span>
          <p>
            {realSending
              ? "SES is authorized for this workspace. Queued fake messages stay fake."
              : "Fake delivery only. No email leaves this machine."}
          </p>
          <button disabled={busy} onClick={() => void run(async () => {})}>
            Refresh ↻
          </button>
        </div>
        <main className="content">
          <header className="page-header">
            <div>
              <div className="eyebrow">
                {workspace.name.toUpperCase()} / {page.toUpperCase()}
              </div>
              <h1>
                {page === "Campaigns"
                  ? "A good day to hit send."
                  : {
                      Audience: "People, not a mailing list.",
                      Welcome: "Start on a good note.",
                      Senders: "A familiar name in the inbox.",
                      Reports: "The story after send.",
                      Activity: "Every email, accounted for.",
                      Settings: "Settings",
                    }[page]}
              </h1>
              <p className="muted">
                {
                  {
                    Campaigns:
                      "Write something worth opening. We’ll keep the rest simple.",
                    Audience:
                      "Only the people who asked to hear from you. Always.",
                    Welcome:
                      "One thoughtful email, sent after a confirmed signup.",
                    Senders: "Verify who you are before sending anything.",
                    Reports:
                      "Honest delivery signals. No invisible tracking pixels.",
                    Activity:
                      "Delivery transports, queue states, and retry history.",
                    Settings:
                      "Manage your sending account, domains, and delivery readiness.",
                  }[page]
                }
              </p>
            </div>
            {page === "Campaigns" && !editor && (
              <button
                className="primary"
                disabled={busy}
                onClick={() => setEditor("new")}
              >
                ＋ New campaign
              </button>
            )}
            {page === "Audience" && (
              <div className="actions">
                <button onClick={() => setAudienceMode("import")}>
                  Import CSV
                </button>
                <button
                  className="primary"
                  onClick={() => setAudienceMode("add")}
                >
                  ＋ Add subscriber
                </button>
              </div>
            )}
          </header>
          {error && (
            <div className="error" role="alert">
              {error}
            </div>
          )}
          {notice && (
            <div className="notice" role="status">
              {notice}
            </div>
          )}
          {!loaded ? (
            <p className="muted">
              {error
                ? "Could not load workspace. Correct the error and refresh."
                : "Loading workspace…"}
            </p>
          ) : (
            <>
              {page === "Campaigns" && (
                <>
                  {editor ? (
                    <CampaignEditor
                      key={typeof editor === "object" ? editor.id : "new"}
                      campaign={editor === "new" ? null : editor}
                      senders={senders}
                      tags={tags}
                      path={path}
                      busy={busy}
                      run={run}
                      onClose={() => setEditor(null)}
                      onSaved={setEditor}
                      realSending={realSending}
                    />
                  ) : (
                    <>
                      <Stats report={report} />
                      <section className="panel">
                        <div className="panel-title">
                          <h2>
                            Your campaigns <span>{campaigns.length}</span>
                          </h2>
                          <span className="small muted">
                            Simple words. Real connections.
                          </span>
                        </div>
                        {!campaigns.length ? (
                          <Empty title="Your next newsletter starts here.">
                            A blank page, a good idea, and the people who care.
                            <br />
                            Create your first campaign when you’re ready.
                          </Empty>
                        ) : (
                          <div className="campaign-list">
                            {campaigns.map((c) => (
                              <div className="campaign-row" key={c.id}>
                                <span className="mail-icon">↗</span>
                                <div className="grow">
                                  <button
                                    className="text-button campaign-title"
                                    onClick={() => setEditor(c)}
                                  >
                                    {c.subject}
                                  </button>
                                  <p className="small muted">
                                    {c.scheduled_at
                                      ? "Scheduled " + localDate(c.scheduled_at)
                                      : "Draft · " + previewText(c.body)}
                                  </p>
                                </div>
                                <Badge>{c.status}</Badge>
                                {c.status !== "draft" && (
                                  <Badge>
                                    {c.transport === "ses"
                                      ? "Real SES"
                                      : "Fake simulation"}
                                  </Badge>
                                )}
                                <button
                                  className="text-button"
                                  onClick={() => setEditor(c)}
                                >
                                  {c.status === "draft" ? "Edit" : "View"} ↗
                                </button>
                              </div>
                            ))}
                          </div>
                        )}
                        {campaignNext && (
                          <button
                            className="load-more"
                            disabled={busy}
                            onClick={async () => {
                              try {
                                const result = await api<{
                                  data: Campaign[];
                                  next: number | null;
                                }>(path + "/campaigns?before=" + campaignNext);
                                setCampaigns([...campaigns, ...result.data]);
                                setCampaignNext(result.next);
                              } catch (e) {
                                setError(String(e));
                              }
                            }}
                          >
                            Load older campaigns
                          </button>
                        )}
                      </section>
                      <section className="getting-started">
                        <div>
                          <span className="eyebrow">
                            A SMALL CHECKLIST. A STRONG START.
                          </span>
                          <h2>Make your first send a good one.</h2>
                        </div>
                        <div className="steps">
                          <button onClick={() => setNav("Senders")}>
                            <span
                              className={
                                senders.some((s) => s.verified_at) ? "done" : ""
                              }
                            >
                              01
                            </span>
                            <div>
                              <strong>Verify your sender</strong>
                              <p>Be a name people recognize.</p>
                            </div>
                            ↗
                          </button>
                          <button onClick={() => setNav("Audience")}>
                            <span className={active ? "done" : ""}>02</span>
                            <div>
                              <strong>Bring your opted-in people</strong>
                              <p>Start small. Start with permission.</p>
                            </div>
                            ↗
                          </button>
                          <button onClick={() => setEditor("new")}>
                            <span>03</span>
                            <div>
                              <strong>Write something good</strong>
                              <p>One idea is a great place to start.</p>
                            </div>
                            ↗
                          </button>
                        </div>
                      </section>
                    </>
                  )}
                </>
              )}
              {page === "Audience" && (
                <>
                  {audienceMode !== "list" ? (
                    <AudienceForm
                      mode={audienceMode}
                      busy={busy}
                      onClose={() => setAudienceMode("list")}
                      onSubmit={(payload) =>
                        run(async () => {
                          const result = await api<Record<string, unknown>>(
                            path +
                              (audienceMode === "import"
                                ? "/subscribers/import"
                                : "/subscribers"),
                            "POST",
                            payload,
                          );
                          setNotice(JSON.stringify(result));
                          setAudienceMode("list");
                        }, "Audience saved. Duplicates and suppressed addresses are never reactivated.")
                      }
                    />
                  ) : (
                    <>
                      <div className="callout">
                        <div>
                          <strong>Grow with a clear yes.</strong>
                          <p>
                            Share your double-opt-in signup page. New
                            subscribers confirm by email before joining.
                          </p>
                        </div>
                        <a
                          className="button"
                          href={"/subscribe/" + workspace.slug}
                          target="_blank"
                          rel="noreferrer"
                        >
                          Open signup page ↗
                        </a>
                      </div>
                      <section className="panel">
                        <div className="panel-title">
                          <h2>
                            Subscribers <span>{active} active</span>
                          </h2>
                          <span className="small muted">
                            Consent is part of every record
                          </span>
                        </div>
                        {!subscribers.length ? (
                          <Empty title="Room for your first subscriber.">
                            Share your signup page or import an audience that
                            has explicitly opted in.
                          </Empty>
                        ) : (
                          <div className="table-wrap">
                            <table>
                              <thead>
                                <tr>
                                  <th>Subscriber</th>
                                  <th>Status</th>
                                  <th>Tags</th>
                                  <th>Actions</th>
                                </tr>
                              </thead>
                              <tbody>
                                {subscribers.map((s) => (
                                  <tr key={s.id}>
                                    <td>
                                      <strong>{s.name || s.email}</strong>
                                      <div className="small muted">
                                        {s.name
                                          ? s.email
                                          : "Confirmed " +
                                            localDate(s.confirmed_at)}
                                      </div>
                                    </td>
                                    <td>
                                      <Badge>{s.status}</Badge>
                                      {s.status === "active" &&
                                        s.consent_transport === "fake" && (
                                          <p className="small muted">
                                            Simulation consent only · real DOI
                                            required for SES
                                          </p>
                                        )}
                                    </td>
                                    <td>
                                      <button
                                        className="text-button small"
                                        onClick={() => setTagEdit(s)}
                                      >
                                        {s.tags.map((t) => t.name).join(", ") ||
                                          "+ Add tags"}
                                      </button>
                                    </td>
                                    <td>
                                      <div className="actions">
                                        <button
                                          className="text-button small"
                                          onClick={async () => {
                                            try {
                                              const result = await api<{
                                                data: unknown[];
                                              }>(
                                                path +
                                                  `/subscribers/${s.id}/consent`,
                                              );
                                              setAudit(result.data);
                                            } catch (e) {
                                              setError(String(e));
                                            }
                                          }}
                                        >
                                          Consent
                                        </button>
                                        {["active", "pending"].includes(
                                          s.status,
                                        ) && (
                                          <button
                                            className="text-button small danger"
                                            disabled={busy}
                                            onClick={() => {
                                              if (
                                                confirm(
                                                  "Suppress this subscriber? Imports cannot undo this.",
                                                )
                                              )
                                                void run(
                                                  () =>
                                                    api(
                                                      path +
                                                        `/subscribers/${s.id}/suppress`,
                                                      "POST",
                                                    ),
                                                  "Subscriber suppressed.",
                                                );
                                            }}
                                          >
                                            Suppress
                                          </button>
                                        )}
                                      </div>
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        )}
                        {subscriberNext && (
                          <button
                            className="load-more"
                            disabled={busy}
                            onClick={async () => {
                              try {
                                const result = await api<{
                                  data: Subscriber[];
                                  next: number | null;
                                }>(
                                  path + "/subscribers?after=" + subscriberNext,
                                );
                                setSubscribers([
                                  ...subscribers,
                                  ...result.data,
                                ]);
                                setSubscriberNext(result.next);
                              } catch (e) {
                                setError(String(e));
                              }
                            }}
                          >
                            Load more subscribers
                          </button>
                        )}
                      </section>
                    </>
                  )}
                  {audit && (
                    <section className="panel detail">
                      <div className="panel-title">
                        <h2>Consent trail</h2>
                        <button onClick={() => setAudit(null)}>Close</button>
                      </div>
                      <pre>{JSON.stringify(audit, null, 2)}</pre>
                    </section>
                  )}
                  {tagEdit && (
                    <form
                      className="panel detail"
                      onSubmit={(e) => {
                        const f = data(e);
                        void run(async () => {
                          await api(
                            path + `/subscribers/${tagEdit.id}/tags`,
                            "PUT",
                            {
                              tags: String(f.get("tags"))
                                .split("|")
                                .map((t) => t.trim())
                                .filter(Boolean),
                            },
                          );
                          setTagEdit(null);
                        }, "Tags updated.");
                      }}
                    >
                      <h2>Tags for {tagEdit.email}</h2>
                      <Field label="Tag names, separated with |">
                        <input
                          name="tags"
                          defaultValue={tagEdit.tags
                            .map((t) => t.name)
                            .join("|")}
                        />
                      </Field>
                      <div className="actions">
                        <button className="primary" disabled={busy}>
                          Save tags
                        </button>
                        <button type="button" onClick={() => setTagEdit(null)}>
                          Cancel
                        </button>
                      </div>
                    </form>
                  )}
                </>
              )}
              {page === "Settings" && (
                <DeliverySettings
                  path={path}
                  onChanged={refresh}
                  onActivity={() => setNav("Activity")}
                  initialSection={settingsSection}
                />
              )}
              {page === "Welcome" && (
                <div className="split">
                  <form
                    key={welcome ? "saved" : "new"}
                    className="panel detail"
                    onSubmit={(e) => {
                      const f = data(e);
                      if (
                        realSending &&
                        f.get("enabled") === "on" &&
                        !window.confirm(
                          "Enable real SES welcome emails for future confirmed signups?",
                        )
                      )
                        return;
                      void run(
                        () =>
                          api(path + "/welcome", "PUT", {
                            confirm_live: realSending,
                            sender_id: Number(f.get("sender_id")),
                            subject: f.get("subject"),
                            body: f.get("body"),
                            enabled: f.get("enabled") === "on",
                          }),
                        "Welcome sequence saved. Only future confirmed signups enroll.",
                      );
                    }}
                  >
                    <h2>A warm, simple welcome.</h2>
                    <p className="muted">
                      One email. No workflow canvas required.
                    </p>
                    <p className="small muted">
                      Saved sequence:{" "}
                      {welcome
                        ? welcome.transport === "ses"
                          ? "Real SES"
                          : "Fake simulation"
                        : "Not saved"}
                      . Saving now sets{" "}
                      {realSending ? "real SES" : "fake simulation"} for future
                      enrollments only; queued messages keep their original
                      transport.
                    </p>
                    <Field label="Sender">
                      <select
                        name="sender_id"
                        defaultValue={welcome?.sender_id || ""}
                        required
                      >
                        <option value="" disabled>
                          Choose a sender
                        </option>
                        {senders.map((s) => (
                          <option key={s.id} value={s.id}>
                            {s.name} ·{" "}
                            {s.verified_at ? "verified" : "unverified"}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Subject">
                      <input
                        name="subject"
                        maxLength={180}
                        defaultValue={welcome?.subject || ""}
                        required
                        placeholder="You’re in. Welcome to Rebitplay."
                      />
                    </Field>
                    <Field label="Welcome message">
                      <textarea
                        name="body"
                        rows={12}
                        maxLength={50000}
                        defaultValue={welcome?.body || ""}
                        required
                        placeholder="Thanks for being here…"
                      />
                    </Field>
                    <label className="check">
                      <input
                        name="enabled"
                        type="checkbox"
                        defaultChecked={
                          welcome?.enabled === true ||
                          Number(welcome?.enabled) === 1
                        }
                      />{" "}
                      Enable for future confirmed signups
                    </label>
                    <button className="primary" disabled={busy}>
                      Save welcome email
                    </button>
                  </form>
                  <aside className="welcome-path">
                    <span className="eyebrow">THE WHOLE SEQUENCE</span>
                    <div>
                      <span>◎</span>
                      <h3>A person signs up</h3>
                      <p>They explicitly ask to join.</p>
                    </div>
                    <b>↓</b>
                    <div>
                      <span>✓</span>
                      <h3>They confirm their email</h3>
                      <p>Double opt-in. No shortcuts.</p>
                    </div>
                    <b>↓</b>
                    <div>
                      <span>✉</span>
                      <h3>A welcome, just once</h3>
                      <p>
                        Queued safely. Suppression checked again at send time.
                        Imports do not trigger it.
                      </p>
                    </div>
                  </aside>
                </div>
              )}
              {page === "Reports" && (
                <Reports path={path} report={report} campaigns={campaigns} />
              )}
              {page === "Activity" && (
                <>
                  <div className="callout">
                    <div>
                      <strong>
                        The queue moves one small batch at a time.
                      </strong>
                      <p>
                        Run a bounded worker tick in your terminal, then
                        refresh. Fake acceptance means a local file; SES
                        acceptance is not a delivery receipt. Every row retains
                        its original transport, even when workspace mode
                        changes.
                      </p>
                    </div>
                    <code>php84 cua mailcrab:tick --batch=50</code>
                  </div>
                  <section className="panel">
                    <div className="panel-title">
                      <h2>Delivery activity</h2>
                      <span className="small muted">
                        Latest 100 · workspace isolated
                      </span>
                    </div>
                    {!deliveries.length ? (
                      <Empty title="Quiet inbox. Ready when you are.">
                        Verification messages, tests, and campaigns will appear
                        here.
                      </Empty>
                    ) : (
                      deliveries.map((d) => (
                        <details className="delivery-row" key={d.id}>
                          <summary>
                            <span className="mail-icon">✉</span>
                            <span className="grow">
                              <strong>{d.subject}</strong>
                              <span className="small muted">
                                {d.recipient} · {d.transport || "fake"} ·{" "}
                                {d.kind} · {d.attempts} attempt(s)
                              </span>
                            </span>
                            <Badge>{d.status}</Badge>
                          </summary>
                          <div className="delivery-content">
                            {d.last_error && (
                              <p className="error">{d.last_error}</p>
                            )}
                            <pre>{d.body}</pre>
                            {d.kind === "confirmation" &&
                              d.transport !== "ses" &&
                              d.body.match(/\/confirm\/[a-f0-9]{64}/)?.[0] && (
                                <a
                                  className="button"
                                  target="_blank"
                                  rel="noreferrer"
                                  href={
                                    d.body.match(/\/confirm\/[a-f0-9]{64}/)![0]
                                  }
                                >
                                  Open local confirmation ↗
                                </a>
                              )}
                            <p className="small mono muted">
                              {d.provider_id || "Not accepted by provider yet"}
                            </p>
                          </div>
                        </details>
                      ))
                    )}
                  </section>
                </>
              )}
            </>
          )}
          <footer>
            <span className="wordmark tiny">mailcrab /</span>
            <span>Small batches. Clear permission. Better emails.</span>
            <span>Made to stay simple.</span>
          </footer>
        </main>
      </div>
    </div>
  );
}

type Run = (action: () => Promise<unknown>, message?: string) => Promise<void>;
function CampaignEditor({
  campaign,
  senders,
  tags,
  path,
  busy,
  run,
  onClose,
  onSaved,
  realSending,
}: {
  realSending: boolean;
  campaign: Campaign | null;
  senders: Sender[];
  tags: Tag[];
  path: string;
  busy: boolean;
  run: Run;
  onClose: () => void;
  onSaved: (c: Campaign) => void;
}) {
  const [subject, setSubject] = useState(campaign?.subject || ""),
    [body, setBody] = useState(campaign?.body || ""),
    [senderId, setSenderId] = useState(String(campaign?.sender_id || "")),
    [tagId, setTagId] = useState(String(campaign?.tag_id || "")),
    [schedule, setSchedule] = useState("");
  const dirty =
    subject !== (campaign?.subject || "") ||
    body !== (campaign?.body || "") ||
    senderId !== String(campaign?.sender_id || "") ||
    tagId !== String(campaign?.tag_id || "");
  const locked = !!campaign && campaign.status !== "draft";
  return (
    <section className="panel detail">
      <div className="panel-title">
        <h2>{campaign ? "Campaign #" + campaign.id : "A fresh page."}</h2>
        <button onClick={onClose}>← All campaigns</button>
      </div>
      <div className="editor-grid">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void run(async () => {
              const c = await api<Campaign>(
                path + "/campaigns" + (campaign ? "/" + campaign.id : ""),
                campaign ? "PUT" : "POST",
                {
                  subject,
                  body,
                  sender_id: Number(senderId),
                  tag_id: tagId ? Number(tagId) : null,
                },
              );
              onSaved(c);
            }, "Draft saved. Preview and test before scheduling.");
          }}
        >
          <fieldset disabled={locked || busy}>
            <div className="form-row">
              <Field label="From">
                <select
                  value={senderId}
                  onChange={(e) => setSenderId(e.target.value)}
                  required
                >
                  <option value="" disabled>
                    Choose a sender
                  </option>
                  {senders.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name} · {s.verified_at ? "verified" : "unverified"}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Audience">
                <select
                  value={tagId}
                  onChange={(e) => setTagId(e.target.value)}
                >
                  <option value="">All active subscribers</option>
                  {tags.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <Field label="Subject line">
              <input
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                required
                maxLength={180}
                placeholder="A little something worth sharing…"
              />
            </Field>
            <Field label="Your email">
              <textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                required
                rows={15}
                maxLength={50000}
                placeholder="Hey there,\n\nHere’s what’s new…"
              />
            </Field>
            <p className="small muted">
              Plain text, intentionally. Add links with{" "}
              <code>[read more](https://example.com)</code>. HTML is escaped.
              Your postal address and unsubscribe link are added automatically.
            </p>
            {!locked && (
              <button className="primary" disabled={busy}>
                Save draft
              </button>
            )}
          </fieldset>
        </form>
        <div className="preview-panel">
          <div className="preview-heading">
            <span className="eyebrow">EMAIL PREVIEW</span>
            <Badge>{campaign?.status || "unsaved"}</Badge>
            <Badge>
              {locked
                ? campaign?.transport === "ses"
                  ? "Real SES campaign"
                  : "Fake simulation campaign"
                : realSending
                  ? "Will schedule using SES"
                  : "Will schedule as fake"}
            </Badge>
          </div>
          {campaign ? (
            <iframe
              title="Saved email preview"
              sandbox=""
              src={"/api/v1" + path + "/campaigns/" + campaign.id + "/preview"}
              key={campaign.subject + campaign.body}
            />
          ) : (
            <div className="preview-empty">
              <span>✉</span>
              <p>Save your draft to see the email preview.</p>
            </div>
          )}
          {dirty && campaign && (
            <p className="small muted">
              Preview shows your saved draft. Save changes to update it.
            </p>
          )}
          <div className="send-controls">
            <button
              disabled={!campaign || dirty || busy}
              onClick={() => {
                if (
                  campaign &&
                  (!realSending ||
                    window.confirm(
                      "Send a real SES test email to your authenticated owner address?",
                    ))
                )
                  void run(
                    () =>
                      sendTest(
                        path + `/campaigns/${campaign.id}/test`,
                        realSending,
                      ),
                    realSending
                      ? "SES test queued to your owner email."
                      : "Test queued to your owner email in the fake outbox.",
                  );
              }}
            >
              {realSending ? "Send real SES test to me ↗" : "Send test to me ↗"}
            </button>
            {campaign && !locked && (
              <>
                <Field label="Schedule in your local timezone">
                  <input
                    type="datetime-local"
                    value={schedule}
                    onChange={(e) => setSchedule(e.target.value)}
                  />
                </Field>
                <button
                  className="primary wide"
                  disabled={!schedule || dirty || busy}
                  onClick={() => {
                    if (
                      confirm(
                        realSending
                          ? "Schedule a REAL SES campaign to this opted-in audience? Content will be locked; actual email will leave the server when the worker runs."
                          : "Queue this campaign with the fake provider? Content will be locked. No real email will be sent.",
                      )
                    )
                      void run(
                        async () => {
                          const c = await api<Campaign>(
                            path + `/campaigns/${campaign.id}/schedule`,
                            "POST",
                            {
                              scheduled_at: new Date(schedule).toISOString(),
                              confirm_live: realSending,
                            },
                          );
                          onSaved(c);
                        },
                        realSending
                          ? "Real SES campaign scheduled. The worker enforces readiness, pause and sending limits."
                          : "Campaign scheduled for fake delivery. Run the worker tick when due.",
                      );
                  }}
                >
                  {realSending
                    ? "Schedule real SES campaign ↗"
                    : "Schedule fake campaign ↗"}
                </button>
              </>
            )}
            {campaign &&
              ["scheduled", "queuing", "sending"].includes(campaign.status) && (
                <button
                  className="danger"
                  disabled={busy}
                  onClick={() => {
                    if (
                      confirm(
                        "Cancel pending deliveries? Already accepted emails cannot be recalled.",
                      )
                    )
                      void run(async () => {
                        await api(
                          path + `/campaigns/${campaign.id}/cancel`,
                          "POST",
                        );
                        onClose();
                      }, "Campaign cancelled.");
                  }}
                >
                  Cancel pending campaign
                </button>
              )}
          </div>
        </div>
      </div>
    </section>
  );
}

function AudienceForm({
  mode,
  busy,
  onClose,
  onSubmit,
}: {
  mode: "add" | "import";
  busy: boolean;
  onClose: () => void;
  onSubmit: (p: unknown) => Promise<void>;
}) {
  const [csv, setCsv] = useState(""),
    [fileError, setFileError] = useState("");
  return (
    <form
      className="panel detail narrow"
      onSubmit={(e) => {
        const f = data(e);
        const common = {
          consent: f.get("consent") === "on",
          source: f.get("source"),
          evidence: f.get("evidence"),
        };
        void onSubmit(
          mode === "import"
            ? { ...common, origin: "existing_opt_in", csv }
            : {
                ...common,
                email: f.get("email"),
                name: f.get("name"),
                tags: String(f.get("tags"))
                  .split("|")
                  .map((t) => t.trim())
                  .filter(Boolean),
                consent_at: new Date(String(f.get("consent_at"))).toISOString(),
              },
        );
      }}
    >
      <div className="panel-title">
        <h2>
          {mode === "import"
            ? "Bring your opted-in audience."
            : "Add a subscriber with permission."}
        </h2>
        <button type="button" onClick={onClose}>
          Cancel
        </button>
      </div>
      <p className="muted">
        No scraped or purchased lists. Suppressed addresses stay suppressed.
        Existing pending signups still need to confirm.
      </p>
      {mode === "import" ? (
        <>
          <Field label="CSV file · maximum 500 rows / 256 KiB">
            <input
              type="file"
              accept=".csv,text/csv"
              onChange={async (e) => {
                const f = e.target.files?.[0];
                if (!f) return;
                if (f.size > 262144) {
                  setFileError("File must be at most 256 KiB.");
                  return;
                }
                try {
                  setCsv(await f.text());
                  setFileError("");
                } catch {
                  setFileError("Could not read file.");
                }
              }}
            />
          </Field>
          {fileError && <p className="error">{fileError}</p>}
          <Field label="Or paste CSV · email,consent_at,name,tags">
            <textarea
              value={csv}
              onChange={(e) => setCsv(e.target.value)}
              rows={7}
              required
              placeholder={
                "email,consent_at,name,tags\nreader@example.com,2026-01-01T12:00:00Z,Reader,rebitplay|newsletter"
              }
            />
          </Field>
          <p className="small muted">
            email and consent_at are required. Each row needs its original
            consent timestamp including timezone. Tags are separated with |.
          </p>
        </>
      ) : (
        <>
          <div className="form-row">
            <Field label="Email">
              <input name="email" type="email" required />
            </Field>
            <Field label="Name (optional)">
              <input name="name" maxLength={120} />
            </Field>
          </div>
          <Field label="Tags, separated with | (optional)">
            <input name="tags" />
          </Field>
          <Field label="When did they consent? (your timezone)">
            <input name="consent_at" type="datetime-local" required />
          </Field>
        </>
      )}
      <Field label="Consent source">
        <input
          name="source"
          maxLength={500}
          placeholder="Rebitplay newsletter opt-in form"
          required
        />
      </Field>
      <Field label="Consent evidence">
        <textarea
          name="evidence"
          rows={3}
          maxLength={2000}
          placeholder="What they agreed to, and where that permission was recorded"
          required
        />
      </Field>
      <label className="check">
        <input name="consent" type="checkbox" required /> I attest that every
        address explicitly opted in to this newsletter. This is not a scraped or
        purchased audience.
      </label>
      <button className="primary" disabled={busy}>
        {mode === "import"
          ? "Import opted-in subscribers"
          : "Add opted-in subscriber"}
      </button>
    </form>
  );
}

function Stats({
  report,
  transport = "ses",
}: {
  report: Report;
  transport?: "fake" | "ses";
}) {
  const metrics = report.by_transport?.[transport] || report;
  const delivered =
    metrics.events.find((e) => e.kind === "delivered")?.unique_recipients || 0;
  const clicks =
    metrics.events.find((e) => e.kind === "click")?.unique_recipients || 0;
  const eligible = transport === "ses" && report.ses_eligible !== undefined;
  return (
    <div className="stats">
      <div>
        <span>
          {eligible ? "SES ELIGIBLE SUBSCRIBERS" : "ACTIVE SUBSCRIBERS"}
        </span>
        <strong>
          {(eligible
            ? Number(report.ses_eligible)
            : count(report.subscribers, "active")
          ).toLocaleString()}
        </strong>
        <small>
          {eligible ? "Live DOI or recorded consent" : "People who said yes"}{" "}
          <b>◎</b>
        </small>
      </div>
      <div>
        <span>{transport === "ses" ? "SES ACCEPTED" : "FAKE ACCEPTED"}</span>
        <strong>{Number(metrics.accepted).toLocaleString()}</strong>
        <small>
          {transport === "ses" ? "Not a delivery receipt" : "Simulation only"}{" "}
          <b>↗</b>
          {transport === "ses" && report.by_transport && (
            <> · {report.by_transport.fake.accepted} fake accepted excluded</>
          )}
        </small>
      </div>
      <div>
        <span>
          {transport === "ses" ? "SES DELIVERIES" : "SIMULATED DELIVERIES"}
        </span>
        <strong>{Number(delivered).toLocaleString()}</strong>
        <small>
          {transport === "ses" ? "Reported by SES" : "Simulated feedback only"}{" "}
          <b>✓</b>
        </small>
      </div>
      <div>
        <span>
          {transport === "ses"
            ? "SES EMAILS WITH CLICKS"
            : "SIMULATED EMAIL CLICKS"}
        </span>
        <strong>{Number(clicks).toLocaleString()}</strong>
        <small>
          May include scanners <b>↗</b>
        </small>
      </div>
    </div>
  );
}
function Reports({
  path,
  report,
  campaigns,
}: {
  path: string;
  report: Report;
  campaigns: Campaign[];
}) {
  const [transport, setTransport] = useState<"fake" | "ses">("ses");
  const [selected, setSelected] = useState(""),
    [filtered, setFiltered] = useState<Report | null>(null),
    [error, setError] = useState("");
  useEffect(() => {
    let live = true;
    setFiltered(null);
    setError("");
    if (selected)
      void api<Report>(path + "/report?campaign_id=" + selected)
        .then((r) => {
          if (live) setFiltered(r);
        })
        .catch((e) => {
          if (live) setError(String(e));
        });
    return () => {
      live = false;
    };
  }, [selected, path, report]);
  const raw = selected ? filtered : report;
  const value = raw ? { ...raw, ...raw.by_transport?.[transport] } : null;
  return (
    <>
      <Field label="Delivery transport">
        <select
          value={transport}
          onChange={(e) => setTransport(e.target.value as "fake" | "ses")}
        >
          <option value="ses">Real SES only</option>
          <option value="fake">Fake simulation only</option>
        </select>
      </Field>
      <Field label="Report scope">
        <select value={selected} onChange={(e) => setSelected(e.target.value)}>
          <option value="">Entire workspace</option>
          {campaigns.map((c) => (
            <option key={c.id} value={c.id}>
              {c.subject}
            </option>
          ))}
        </select>
      </Field>
      {error && <p className="error">{error}</p>}
      {value ? (
        <>
          <Stats report={value} transport={transport} />
          <div className="split">
            <section className="panel detail">
              <h2>Delivery states</h2>
              {!value.deliveries.length ? (
                <p className="muted">No deliveries yet.</p>
              ) : (
                value.deliveries.map((r) => (
                  <div className="report-row" key={r.status}>
                    <Badge>{r.status}</Badge>
                    <strong>{r.count}</strong>
                  </div>
                ))
              )}
            </section>
            <section className="panel detail">
              <h2>Engagement & feedback</h2>
              {["delivered", "click", "unsubscribe", "bounce", "complaint"].map(
                (kind) => (
                  <div className="report-row" key={kind}>
                    <span>
                      {kind === "click" ? "Unique link clicks" : kind}
                    </span>
                    <strong>
                      {value.events.find((e) => e.kind === kind)?.count || 0}
                    </strong>
                  </div>
                ),
              )}
            </section>
          </div>
          <p className="small muted">
            {value.note} Unsubscribe and feedback suppress future marketing.
            Reports count events, not promises.
          </p>
        </>
      ) : (
        <p>Loading report…</p>
      )}
    </>
  );
}
