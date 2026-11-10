// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import DeliverySettings, { type DeliveryOverview } from "./DeliverySettings";

const path = "/workspaces/1";
let overview: DeliveryOverview;
const calls: { url: string; method: string; body: Record<string, unknown> }[] =
  [];
const base = (): DeliveryOverview => ({
  enabled: true,
  setup_enabled: true,
  region: "us-east-1",
  region_changed: false,
  account: {
    production: true,
    sending_enabled: true,
    enforcement: "HEALTHY",
    daily_limit: 50000,
    sent_last_24h: 100,
    max_send_rate: 14,
  },
  account_checked_at: "2026-11-10 12:00:00",
  account_error: null,
  connection_fresh: true,
  feedback: null,
  feedback_error: null,
  feedback_checked_at: null,
  feedback_fresh: false,
  subscription_pending: false,
  subscription_requested: false,
  topic_configured: true,
  configuration_set: "mailcrab-workspace-1",
  webhook_path: "/webhooks/ses/1",
  webhook_url: "https://mailcrab.example.com/webhooks/ses/1",
  domains: [],
  senders: [],
  default_sender_id: null,
  paused: true,
  rate_per_second: 1,
  postal_address: "123 Example Street",
  checks: [
    {
      id: "connection",
      label: "SES connection",
      ok: true,
      reason: "",
      section: "ses-connection-title",
    },
    {
      id: "domain",
      label: "Authenticated sending domain",
      ok: false,
      reason: "Connect a domain and check DNS.",
      section: "domain-title",
    },
  ],
  ready: false,
  real_sending: false,
});
beforeEach(() => {
  overview = base();
  calls.length = 0;
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, options: RequestInit = {}) => {
      const method = options.method || "GET";
      const body = JSON.parse(String(options.body || "{}"));
      calls.push({ url, method, body });
      let result: unknown = overview;
      if (url.endsWith("/delivery/domains") && method === "POST") {
        const domain = {
          id: 1,
          domain: "news.example.com",
          mail_from: "bounce.news.example.com",
          status: "not_connected",
          dns: [],
          fresh: false,
          identity: null,
          checked_at: null,
          last_error: null,
        };
        overview.domains = [domain];
        result = domain;
      }
      if (url.endsWith("/domains/1/connect"))
        overview.domains[0].status = "pending";
      if (url.endsWith("/delivery/preferences") && method === "PUT")
        Object.assign(overview, body);
      if (url.endsWith("/feedback/connect"))
        overview.subscription_requested = true;
      return new Response(JSON.stringify(result), {
        status: method === "POST" && url.endsWith("/domains") ? 201 : 200,
        headers: { "Content-Type": "application/json" },
      });
    }),
  );
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
  });
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

async function open() {
  render(<DeliverySettings path={path} onChanged={async () => {}} />);
  await screen.findByText("Set up Amazon SES");
}

describe("Email delivery onboarding", () => {
  it("starts with a clear next step and only reads local cached settings", async () => {
    overview.enabled = false;
    overview.setup_enabled = false;
    overview.account = null;
    overview.checks[0].ok = false;
    overview.checks[0].reason = "Connect the operator-managed AWS account.";
    await open();
    expect(screen.getByText("Real sending is locked")).toBeTruthy();
    expect(screen.getByText("Next: SES connection")).toBeTruthy();
    expect(
      screen
        .getByRole("button", { name: "Check connection" })
        .hasAttribute("disabled"),
    ).toBe(true);
    expect(screen.queryByLabelText(/secret|access key|password/i)).toBeNull();
    expect(
      calls.every((c) => c.method === "GET" && c.url.endsWith("/delivery")),
    ).toBe(true);
    expect(
      screen.getByText("Future launch controls").closest("details")?.open,
    ).toBe(false);
  });
  it("shows live state accurately and exposes a one-click SES pause", async () => {
    overview.real_sending = true;
    overview.paused = false;
    await open();
    expect(screen.getByText("Real SES sending is authorized")).toBeTruthy();
    expect(screen.queryByText("Real sending is locked")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Pause sending" }));
    await screen.findByText("SES authorized · sending paused");
    expect(
      calls.find((c) => c.url.endsWith("/delivery/preferences"))?.body.paused,
    ).toBe(true);
    fireEvent.click(screen.getByText("Sending controls"));
    expect(
      (screen.getByLabelText("Pause SES sending") as HTMLInputElement).checked,
    ).toBe(true);
    fireEvent.change(screen.getByLabelText("Maximum emails per second"), {
      target: { value: "2" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save controls" }));
    await waitFor(() =>
      expect(
        calls.filter((c) => c.url.endsWith("/delivery/preferences")),
      ).toHaveLength(2),
    );
    expect(
      calls.filter((c) => c.url.endsWith("/delivery/preferences"))[1].body,
    ).toEqual({ rate_per_second: 2 });
    expect(overview.paused).toBe(true);
    expect(
      calls.every(
        (c) => !c.url.endsWith("/test") && !c.url.endsWith("/schedule"),
      ),
    ).toBe(true);
  });
  it("adds a domain locally, then requires a separate cloud acknowledgement", async () => {
    await open();
    fireEvent.change(screen.getByLabelText("Domain"), {
      target: { value: "news.example.com" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Add domain" }));
    await screen.findByText(
      "Domain added locally. Connect it to SES to generate DNS records.",
    );
    expect(calls.filter((c) => c.method === "POST")).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Connect to SES" }));
    const generate = screen.getByRole("button", {
      name: "Generate DNS records",
    });
    expect(generate.hasAttribute("disabled")).toBe(true);
    fireEvent.click(
      screen.getByLabelText(
        "I own this domain and authorize these AWS changes.",
      ),
    );
    fireEvent.click(generate);
    await waitFor(() =>
      expect(
        calls.some(
          (c) =>
            c.url.endsWith("/domains/1/connect") &&
            c.body.authorize_aws === true,
        ),
      ).toBe(true),
    );
    await screen.findByText(
      "SES identity connected. Add the DNS records below, then check verification.",
    );
    expect(
      screen.queryByText("Register news.example.com with Amazon SES?"),
    ).toBeNull();
  });
  it("copies only pending records and separates MX priority from the target", async () => {
    overview.domains = [
      {
        id: 1,
        domain: "news.example.com",
        mail_from: "bounce.news.example.com",
        status: "pending",
        fresh: true,
        last_error: null,
        checked_at: "2026-11-10 12:00:00",
        identity: {
          verification: "PENDING",
          dkim: "PENDING",
          mail_from: "PENDING",
        },
        dns: [
          {
            purpose: "DMARC",
            type: "TXT",
            name: "_dmarc.news.example.com",
            value: "v=DMARC1; p=reject;",
            target: "v=DMARC1; p=reject;",
            priority: null,
            status: "verified",
            found: ["v=DMARC1; p=reject;"],
          },
          {
            purpose: "SPF",
            type: "TXT",
            name: "bad.news.example.com",
            value: "v=spf1 include:amazonses.com ~all",
            target: "v=spf1 include:amazonses.com ~all",
            priority: null,
            status: "conflict",
            found: ["v=spf1 -all"],
          },
          {
            purpose: "DKIM",
            type: "CNAME",
            name: "failed._domainkey.news.example.com",
            value: "failed.dkim.amazonses.com",
            target: "failed.dkim.amazonses.com",
            priority: null,
            status: "lookup_failed",
            found: [],
          },
          {
            purpose: "MAIL FROM",
            type: "MX",
            name: "bounce.news.example.com",
            value: "10 feedback-smtp.us-east-1.amazonses.com",
            target: "feedback-smtp.us-east-1.amazonses.com",
            priority: 10,
            status: "pending",
            found: [],
          },
        ],
      },
    ];
    await open();
    fireEvent.click(screen.getByRole("button", { name: /news.example.com/ }));
    fireEvent.click(
      screen.getByRole("button", { name: "Copy pending records" }),
    );
    await waitFor(() =>
      expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
        "MX\tbounce.news.example.com\t10 feedback-smtp.us-east-1.amazonses.com",
      ),
    );
    expect(screen.getByText("Priority: 10")).toBeTruthy();
    fireEvent.click(
      screen.getByRole("button", {
        name: "Copy value for MAIL FROM bounce.news.example.com",
      }),
    );
    await waitFor(() =>
      expect(navigator.clipboard.writeText).toHaveBeenLastCalledWith(
        "feedback-smtp.us-east-1.amazonses.com",
      ),
    );
    expect(screen.getByText("DKIM: PENDING")).toBeTruthy();
  });
  it("discovers signed SNS confirmation via local refresh without repeating setup", async () => {
    overview.checks[1] = {
      id: "feedback",
      label: "Delivery feedback",
      ok: false,
      reason: "Confirm the subscription.",
      section: "feedback-title",
    };
    overview.subscription_requested = true;
    await open();
    expect(
      screen.getByText(/Waiting for the signed SNS confirmation/),
    ).toBeTruthy();
    overview.subscription_pending = true;
    fireEvent.click(screen.getByRole("button", { name: "Refresh status" }));
    await screen.findByRole("button", { name: "Confirm subscription" });
    expect(calls.every((c) => c.method === "GET")).toBe(true);
    expect(
      screen.getByText("Webhook & troubleshooting").parentElement?.textContent,
    ).toContain("https://mailcrab.example.com/webhooks/ses/1");
  });
  it("does not portray a successful cloud action as failed when workspace refresh fails", async () => {
    overview.checks[1] = {
      id: "feedback",
      label: "Delivery feedback",
      ok: false,
      reason: "Connect feedback.",
      section: "feedback-title",
    };
    render(
      <DeliverySettings
        path={path}
        onChanged={async () => {
          throw new Error("Reports unavailable");
        }}
      />,
    );
    await screen.findByText("Set up Amazon SES");
    fireEvent.click(screen.getByRole("button", { name: "Connect feedback" }));
    fireEvent.click(
      screen.getByLabelText(
        "I authorize these AWS configuration changes and the SNS subscription.",
      ),
    );
    fireEvent.click(screen.getByRole("button", { name: "Set up feedback" }));
    await screen.findByText(
      /Saved successfully; other workspace data could not refresh/,
    );
    expect(screen.queryByRole("alert")).toBeNull();
    expect(
      screen.queryByText("Connect SES events to your existing SNS topic?"),
    ).toBeNull();
    expect(
      calls.filter((c) => c.url.endsWith("/feedback/connect")),
    ).toHaveLength(1);
  });
  it("marks cached readiness unavailable after polling fails and recovers without a reload", async () => {
    const interval = vi.spyOn(window, "setInterval");
    await open();
    const refresh = interval.mock.calls[0][0] as () => void;
    vi.mocked(fetch).mockRejectedValueOnce(new Error("Offline"));
    await act(async () => {
      refresh();
    });
    await screen.findByText(
      "Status could not refresh. Reconnect and refresh before relying on these checks.",
    );
    expect(screen.getByText("Recheck required")).toBeTruthy();
    await act(async () => {
      refresh();
    });
    await waitFor(() => expect(screen.queryByRole("alert")).toBeNull());
    expect(screen.queryByText("Recheck required")).toBeNull();
  });
  it("explains disabled configuration sending and raw SNS delivery rather than suggesting a confirmation loop", async () => {
    overview.checks[1] = {
      id: "feedback",
      label: "Delivery feedback",
      ok: false,
      reason: "Check SNS configuration.",
      section: "feedback-title",
    };
    overview.feedback = {
      ready: false,
      events_connected: true,
      subscription_confirmed: false,
      suppression_enabled: true,
      configuration_sending_enabled: false,
      subscription_problem: "raw_delivery_enabled",
    };
    await open();
    expect(
      screen.getByText(/configuration set has sending disabled/),
    ).toBeTruthy();
    expect(
      screen.getByText(/Raw SNS message delivery is enabled/),
    ).toBeTruthy();
    expect(
      screen.queryByRole("button", { name: "Confirm subscription" }),
    ).toBeNull();
  });
  it("shows expired readiness honestly and keeps failure reasons actionable", async () => {
    overview.connection_fresh = false;
    overview.checks[0] = {
      id: "connection",
      label: "SES connection",
      ok: false,
      reason: "Connection check expired. Recheck SES.",
      section: "ses-connection-title",
    };
    await open();
    expect(screen.getByText("Recheck required")).toBeTruthy();
    expect(screen.getByText("Next: SES connection")).toBeTruthy();
    expect(
      screen.getAllByText("Connection check expired. Recheck SES.").length,
    ).toBeGreaterThan(0);
    fireEvent.click(
      screen.getByText("Readiness", { selector: "h2" }).closest("summary")!,
    );
    const checklist = screen.getByRole("list");
    expect(
      within(checklist).getAllByRole("button", { name: "Fix →" }).length,
    ).toBe(2);
  });
});
