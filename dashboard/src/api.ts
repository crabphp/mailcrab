let csrf = "";
export function setCsrf(value: string) {
  csrf = value;
}
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message);
  }
}
export async function api<T>(
  path: string,
  method = "GET",
  data?: unknown,
  extra: Record<string, string> = {},
): Promise<T> {
  const response = await fetch("/api/v1" + path, {
    method,
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      ...(method !== "GET"
        ? { "Content-Type": "application/json", "X-CSRF-Token": csrf }
        : {}),
      ...extra,
    },
    ...(data !== undefined
      ? { body: JSON.stringify(data) }
      : method !== "GET"
        ? { body: "{}" }
        : {}),
  });
  const body = response.status === 204 ? null : await response.json();
  if (!response.ok)
    throw new ApiError(
      response.status,
      body?.error?.message || "Request failed.",
    );
  return body as T;
}
// Keep a test operation's key across a lost response, remount, or reload.
// A successful response ends that operation; a later deliberate send gets a new key.
export async function sendTest(
  path: string,
  confirmLive = false,
): Promise<{ delivery_id: number }> {
  const storageKey =
    "mailcrab:pending-test:" + path + (confirmLive ? ":ses" : "");
  const key = sessionStorage.getItem(storageKey) || crypto.randomUUID();
  sessionStorage.setItem(storageKey, key);
  const result = await api<{ delivery_id: number }>(
    path,
    "POST",
    confirmLive ? { confirm_live: true } : {},
    { "Idempotency-Key": key },
  );
  sessionStorage.removeItem(storageKey);
  return result;
}

export type Workspace = {
  id: number;
  name: string;
  slug: string;
  postal_address: string;
  delivery_mode?: "fake" | "ses";
};
export type Session = {
  user: { id: number; email: string };
  workspaces: Workspace[];
  csrf: string;
};
export type Sender = {
  id: number;
  name: string;
  email: string;
  verified_at: string | null;
};
export type Tag = { id: number; name: string };
export type Campaign = {
  id: number;
  subject: string;
  body: string;
  sender_id: number;
  tag_id: number | null;
  status: string;
  scheduled_at: string | null;
  transport?: "fake" | "ses";
};
export type Subscriber = {
  id: number;
  email: string;
  name: string;
  status: string;
  confirmed_at: string | null;
  consent_transport?: "fake" | "ses" | "recorded";
  tags: Tag[];
};
export type Welcome = {
  transport?: "fake" | "ses";
  sender_id: number;
  subject: string;
  body: string;
  enabled: boolean | number;
};
export type Delivery = {
  id: number;
  kind: string;
  transport?: "fake" | "ses";
  recipient: string;
  subject: string;
  body: string;
  status: string;
  attempts: number;
  provider_id: string | null;
  last_error: string | null;
};
export type Report = {
  subscribers: { status: string; count: number }[];
  deliveries: { status: string; count: number }[];
  accepted: number;
  events: { kind: string; count: number; unique_recipients: number }[];
  note: string;
  ses_eligible?: number;
  by_transport?: Record<
    "fake" | "ses",
    {
      accepted: number;
      deliveries: { status: string; count: number }[];
      events: { kind: string; count: number; unique_recipients: number }[];
    }
  >;
};
export const localDate = (value: string | null) =>
  value ? new Date(value.replace(" ", "T") + "Z").toLocaleString() : "—";
export function previewText(text: string): string {
  return text.length > 120 ? text.slice(0, 120) + "…" : text;
}
