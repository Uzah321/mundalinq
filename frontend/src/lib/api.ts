// Thin client for the MundaLinq JSON API (/api/v1). Signed-in requests carry a Sanctum bearer token,
// one per browser, which the user can see and revoke from their device list like any phone.

const API_URL = (import.meta.env.VITE_API_URL || "/api/v1").replace(/\/$/, "");
const TOKEN_KEY = "mundalinq.token";

export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: Record<string, string[]>;

  constructor(status: number, message: string, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.fieldErrors = fieldErrors;
  }

  field(name: string): string | undefined {
    return this.fieldErrors[name]?.[0];
  }

  /** The most specific message: the first field error, else the summary. */
  get detail(): string {
    return Object.values(this.fieldErrors)[0]?.[0] ?? this.message;
  }
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.detail;
  return "Something went wrong. Please try again.";
}

// The token lives in a tiny external store so the fetch client and React components agree on it.
let token = readStoredToken();
const listeners = new Set<() => void>();

function readStoredToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export const session = {
  token: () => token,
  setToken(next: string | null) {
    token = next;
    try {
      if (next) localStorage.setItem(TOKEN_KEY, next);
      else localStorage.removeItem(TOKEN_KEY);
    } catch {
      // Private mode or blocked storage: the session still works until the tab closes.
    }
    listeners.forEach((listener) => listener());
  },
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  },
};

type RequestOptions = {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | undefined | null>;
  signal?: AbortSignal;
};

export async function api<T>(path: string, { method = "GET", body, query, signal }: RequestOptions = {}): Promise<T> {
  const url = new URL(API_URL + path, window.location.origin);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
  }

  const headers: Record<string, string> = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (token) headers.Authorization = `Bearer ${token}`;

  let response: Response;
  try {
    response = await fetch(url, { method, headers, signal, body: body === undefined ? undefined : JSON.stringify(body) });
  } catch (error) {
    if (signal?.aborted) throw error;
    throw new ApiError(0, "We couldn't reach MundaLinq. Check your connection and try again.");
  }

  if (response.status === 204) return undefined as T;

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    // An expired or revoked token: drop it so the app falls back to browsing as a guest.
    if (response.status === 401 && token) session.setToken(null);

    throw new ApiError(response.status, payload?.message || fallbackMessage(response.status), payload?.errors ?? {});
  }

  return payload as T;
}

/** A file behind sign-in (e.g. a verification document), fetched with the token so it can be shown from memory. */
export async function apiBlob(path: string, signal?: AbortSignal): Promise<Blob> {
  let response: Response;
  try {
    response = await fetch(new URL(API_URL + path, window.location.origin), {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      signal,
    });
  } catch (error) {
    if (signal?.aborted) throw error;
    throw new ApiError(0, "We couldn't reach MundaLinq. Check your connection and try again.");
  }

  if (!response.ok) {
    if (response.status === 401 && token) session.setToken(null);
    throw new ApiError(response.status, fallbackMessage(response.status));
  }

  return response.blob();
}

/** Saves a file the API sends (a PDF receipt, a CSV report) under the given name. */
export async function downloadFile(path: string, filename: string): Promise<void> {
  const url = URL.createObjectURL(await apiBlob(path));
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function fallbackMessage(status: number): string {
  if (status === 429) return "That was a lot of tries. Please wait a minute and try again.";
  if (status >= 500) return "Something went wrong on our side. Please try again shortly.";
  return "That didn't work. Please try again.";
}

/** Shown in the user's list of signed-in devices. */
export function deviceName(): string {
  const ua = navigator.userAgent;
  const browser = /Edg\//.test(ua) ? "Edge" : /Firefox\//.test(ua) ? "Firefox" : /Chrome\//.test(ua) ? "Chrome" : /Safari\//.test(ua) ? "Safari" : "Browser";
  const os = /Windows/.test(ua) ? "Windows" : /Android/.test(ua) ? "Android" : /iPhone|iPad/.test(ua) ? "iOS" : /Mac OS X/.test(ua) ? "macOS" : /Linux/.test(ua) ? "Linux" : null;

  return `Web · ${browser}${os ? ` on ${os}` : ""}`;
}
