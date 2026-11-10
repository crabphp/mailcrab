// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import App from "./App";

const session = {
  user: { id: 1, email: "owner@example.test" },
  csrf: "test-csrf",
  workspaces: [
    {
      id: 1,
      name: "Rebitplay local",
      slug: "rebitplay",
      postal_address: "Test address",
    },
    {
      id: 2,
      name: "Second workspace",
      slug: "second",
      postal_address: "Other address",
    },
  ],
};
let realSending = false;
const requests: {
  url: string;
  method: string;
  body: Record<string, unknown>;
}[] = [];
const baseReport = () => ({
  by_transport: {
    fake: { accepted: 0, deliveries: [], events: [] },
    ses: { accepted: 0, deliveries: [], events: [] },
  },
  ses_eligible: 0,
  subscribers: [],
  deliveries: [],
  events: [],
  accepted: 0,
  note: "Accepted is not delivered.",
});
let report = baseReport();

beforeEach(() => {
  requests.length = 0;
  realSending = false;
  report = baseReport();
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, options: RequestInit = {}) => {
      requests.push({
        url,
        method: options.method || "GET",
        body: JSON.parse(String(options.body || "{}")),
      });
      let result: unknown = { data: [] };
      if (url.endsWith("/auth/me"))
        result = {
          ...session,
          workspaces: session.workspaces.map((w) => ({
            ...w,
            delivery_mode: realSending ? "ses" : "fake",
          })),
        };
      else if (url.endsWith("/report")) result = report;
      else if (url.endsWith("/senders"))
        result = {
          data: [
            {
              id: 1,
              name: "Rebitplay",
              email: "hello@example.test",
              verified_at: "2026-11-10 00:00:00",
            },
          ],
        };
      else if (url.endsWith("/welcome")) result = { data: null };
      else if (url.endsWith("/campaigns") && options.method === "POST")
        result = {
          id: 10,
          ...JSON.parse(String(options.body)),
          status: "draft",
          scheduled_at: null,
        };
      else if (url.endsWith("/campaigns/10/schedule"))
        result = {
          id: 10,
          sender_id: 1,
          tag_id: null,
          subject: "A thoughtful update",
          body: "Hello readers",
          status: "scheduled",
          scheduled_at: "2026-12-01 12:00:00",
        };
      return new Response(JSON.stringify(result), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }),
  );
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("Mailcrab dashboard", () => {
  it("shows real empty states and makes no sending calls on load", async () => {
    render(<App />);
    await screen.findByRole("heading", { name: "A good day to hit send." });
    await screen.findByText("Your next newsletter starts here.");
    expect(
      screen.getByText("Fake delivery only. No email leaves this machine."),
    ).toBeTruthy();
    expect(requests.every((r) => r.method === "GET")).toBe(true);
    expect(requests.every((r) => r.url.startsWith("/api/v1/"))).toBe(true);
  });
  it("saves a draft and requires explicit scheduling separately", async () => {
    render(<App />);
    await screen.findByText("Your next newsletter starts here.");
    fireEvent.click(screen.getByRole("button", { name: "＋ New campaign" }));
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "1" } });
    fireEvent.change(screen.getByLabelText("Subject line"), {
      target: { value: "A thoughtful update" },
    });
    fireEvent.change(screen.getByLabelText("Your email"), {
      target: { value: "Hello readers" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByText("Draft saved. Preview and test before scheduling.");
    const preview = screen.getByTitle("Saved email preview");
    expect(preview.getAttribute("sandbox")).toBe("");
    expect(preview.getAttribute("src")).toBe(
      "/api/v1/workspaces/1/campaigns/10/preview",
    );
    expect(requests.filter((r) => r.method === "POST")).toHaveLength(1);
    fireEvent.change(screen.getByLabelText("Schedule in your local timezone"), {
      target: { value: "2026-12-01T12:00" },
    });
    vi.spyOn(window, "confirm").mockReturnValue(true);
    fireEvent.click(
      screen.getByRole("button", { name: "Schedule fake campaign ↗" }),
    );
    await waitFor(() =>
      expect(
        requests.some(
          (r) => r.url.endsWith("/schedule") && r.method === "POST",
        ),
      ).toBe(true),
    );
    const schedule = requests.find((r) => r.url.endsWith("/schedule"))!;
    expect(String(schedule.body.scheduled_at)).toMatch(/Z$/);
    expect(requests.some((r) => r.url.includes("/tick"))).toBe(false);
  });
  it("labels live SES clearly and requires acknowledgement before a real test", async () => {
    realSending = true;
    render(<App />);
    await screen.findByText("Your next newsletter starts here.");
    expect(screen.getByText("SES MODE · REAL EMAIL")).toBeTruthy();
    expect(
      screen.queryByText("Fake delivery only. No email leaves this machine."),
    ).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "＋ New campaign" }));
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "1" } });
    fireEvent.change(screen.getByLabelText("Subject line"), {
      target: { value: "A thoughtful update" },
    });
    fireEvent.change(screen.getByLabelText("Your email"), {
      target: { value: "Hello readers" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByText("Draft saved. Preview and test before scheduling.");
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    fireEvent.click(
      screen.getByRole("button", { name: "Send real SES test to me ↗" }),
    );
    expect(requests.some((r) => r.url.endsWith("/test"))).toBe(false);
    confirm.mockReturnValue(true);
    fireEvent.click(
      screen.getByRole("button", { name: "Send real SES test to me ↗" }),
    );
    await waitFor(() =>
      expect(
        requests.find((r) => r.url.endsWith("/test"))?.body.confirm_live,
      ).toBe(true),
    );
  });
  it("excludes simulated outcomes from real metrics and offers separate fake reports", async () => {
    report.accepted = 9;
    report.by_transport.fake.accepted = 7;
    report.by_transport.ses.accepted = 2;
    render(<App />);
    await screen.findByText("Your next newsletter starts here.");
    expect(
      screen.getByText("SES ACCEPTED").parentElement?.querySelector("strong")
        ?.textContent,
    ).toBe("2");
    expect(screen.getByText(/7 fake accepted excluded/)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "▥ Reports" }));
    fireEvent.change(screen.getByLabelText("Delivery transport"), {
      target: { value: "fake" },
    });
    expect(
      screen.getByText("FAKE ACCEPTED").parentElement?.querySelector("strong")
        ?.textContent,
    ).toBe("7");
    expect(screen.queryByText("SES ACCEPTED")).toBeNull();
  });
  it("resets workspace state and scopes new reads when switching workspaces", async () => {
    render(<App />);
    await screen.findByText("Your next newsletter starts here.");
    fireEvent.click(screen.getByRole("button", { name: "◎ Audience" }));
    await screen.findByRole("heading", { name: "People, not a mailing list." });
    fireEvent.change(screen.getByLabelText("Workspace"), {
      target: { value: "2" },
    });
    await screen.findByRole("heading", { name: "A good day to hit send." });
    await waitFor(() =>
      expect(
        requests.some((r) => r.url === "/api/v1/workspaces/2/subscribers"),
      ).toBe(true),
    );
    expect(
      screen.queryByRole("heading", { name: "People, not a mailing list." }),
    ).toBeNull();
  });
});
