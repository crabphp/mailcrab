import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api, ApiError, setCsrf, sendTest, previewText } from "./api";

describe("workspace API client", () => {
  beforeEach(() => {
    setCsrf("csrf-test");
  });
  afterEach(() => vi.unstubAllGlobals());
  it("uses same-origin cookies and CSRF for mutations", async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{"ok":true}'));
    vi.stubGlobal("fetch", fetch);
    await api("/workspaces/1/campaigns", "POST", { subject: "Hello" });
    expect(fetch).toHaveBeenCalledWith(
      "/api/v1/workspaces/1/campaigns",
      expect.objectContaining({
        credentials: "same-origin",
        method: "POST",
        body: '{"subject":"Hello"}',
        headers: expect.objectContaining({
          "X-CSRF-Token": "csrf-test",
          "Content-Type": "application/json",
        }),
      }),
    );
  });
  it("preserves an idempotency key supplied for test sends", async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{"delivery_id":3}'));
    vi.stubGlobal("fetch", fetch);
    await api(
      "/workspaces/1/campaigns/2/test",
      "POST",
      {},
      { "Idempotency-Key": "stable-request-1234" },
    );
    expect(fetch.mock.calls[0][1].headers["Idempotency-Key"]).toBe(
      "stable-request-1234",
    );
  });
  it("reuses a pending test key after a lost response, then rotates only after success", async () => {
    const storage = new Map<string, string>();
    vi.stubGlobal("sessionStorage", {
      getItem: (k: string) => storage.get(k) || null,
      setItem: (k: string, v: string) => storage.set(k, v),
      removeItem: (k: string) => storage.delete(k),
    });
    const fetch = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("Lost response"))
      .mockResolvedValueOnce(new Response('{"delivery_id":3}'))
      .mockResolvedValueOnce(new Response('{"delivery_id":4}'));
    vi.stubGlobal("fetch", fetch);
    const path = "/workspaces/1/campaigns/2/test";
    await expect(sendTest(path)).rejects.toThrow("Lost response");
    await sendTest(path);
    expect(fetch.mock.calls[0][1].headers["Idempotency-Key"]).toBe(
      fetch.mock.calls[1][1].headers["Idempotency-Key"],
    );
    expect(storage.size).toBe(0);
    await sendTest(path);
    expect(fetch.mock.calls[2][1].headers["Idempotency-Key"]).not.toBe(
      fetch.mock.calls[1][1].headers["Idempotency-Key"],
    );
  });
  it("keeps live acknowledgement and pending operation keys separate from fake mode", async () => {
    const storage = new Map<string, string>();
    vi.stubGlobal("sessionStorage", {
      getItem: (k: string) => storage.get(k) || null,
      setItem: (k: string, v: string) => storage.set(k, v),
      removeItem: (k: string) => storage.delete(k),
    });
    const fetch = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("Lost fake response"))
      .mockResolvedValueOnce(new Response('{"delivery_id":8}'));
    vi.stubGlobal("fetch", fetch);
    const path = "/workspaces/3/campaigns/4/test";
    await expect(sendTest(path)).rejects.toThrow("Lost fake response");
    await sendTest(path, true);
    expect(fetch.mock.calls[0][1].headers["Idempotency-Key"]).not.toBe(
      fetch.mock.calls[1][1].headers["Idempotency-Key"],
    );
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({
      confirm_live: true,
    });
    expect(storage.size).toBe(1);
  });
  it("does not put CSRF or a request body on read operations", async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{"data":[]}'));
    vi.stubGlobal("fetch", fetch);
    await api("/workspaces/1/subscribers");
    expect(fetch.mock.calls[0][1].body).toBeUndefined();
    expect(fetch.mock.calls[0][1].headers["X-CSRF-Token"]).toBeUndefined();
  });
  it("surfaces framework errors and handles no-content logout", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response('{"error":{"message":"Verify the sender first."}}', {
          status: 422,
        }),
      ),
    );
    await expect(
      api("/workspaces/1/campaigns/1/schedule", "POST", {}),
    ).rejects.toEqual(new ApiError(422, "Verify the sender first."));
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response(null, { status: 204 })),
    );
    await expect(api("/auth/logout", "POST")).resolves.toBeNull();
  });
  it("bounds list previews without changing the source", () => {
    expect(previewText("x".repeat(121))).toBe("x".repeat(120) + "…");
    expect(previewText("Hello")).toBe("Hello");
  });
});
