import { useEffect, useSyncExternalStore } from "react";
import { useMutation, useQuery, useQueryClient, type QueryClient } from "@tanstack/react-query";
import { api, deviceName, session } from "./api";
import type { AuthResponse, User } from "./types";

// Everything that belongs to the signed-in user is cached under the "me" key, so signing out
// (or a token expiring) clears it in one go.
export const meKey = ["me"] as const;

export function useSession() {
  const token = useSyncExternalStore(session.subscribe, session.token);
  const me = useQuery({
    queryKey: meKey,
    queryFn: ({ signal }) => api<{ data: User }>("/me", { signal }).then((response) => response.data),
    enabled: token !== null,
    staleTime: 5 * 60_000,
  });

  return {
    signedIn: token !== null,
    user: token ? (me.data ?? null) : null,
    loadingUser: token !== null && me.isPending,
  };
}

/** Drops cached account data whenever the session ends, including when the API rejects the token. */
export function useSessionCleanup() {
  const queryClient = useQueryClient();

  useEffect(
    () =>
      session.subscribe(() => {
        if (session.token() === null) queryClient.removeQueries({ queryKey: meKey });
      }),
    [queryClient],
  );
}

function startSession(queryClient: QueryClient, response: AuthResponse) {
  queryClient.removeQueries({ queryKey: meKey });
  queryClient.setQueryData(meKey, response.user);
  session.setToken(response.token);
}

/** Staff sign-ins stop here until the code texted to their phone is confirmed. */
export type TwoFactorChallenge = { two_factor_required: true; challenge: string; message: string };

export function useSignIn() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { login: string; password: string }) =>
      api<AuthResponse | TwoFactorChallenge>("/auth/login", { method: "POST", body: { ...input, device_name: deviceName() } }),
    onSuccess: (response) => {
      if ("token" in response) startSession(queryClient, response);
    },
  });
}

export function useConfirmTwoFactor() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { challenge: string; code: string }) => api<AuthResponse>("/auth/two-factor", { method: "POST", body: input }),
    onSuccess: (response) => startSession(queryClient, response),
  });
}

export type RegisterInput = {
  name: string;
  phone: string;
  email: string;
  password: string;
  password_confirmation: string;
  role: "buyer" | "business_buyer";
};

export function useRegister() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: RegisterInput) =>
      api<AuthResponse>("/auth/register", { method: "POST", body: { ...input, device_name: deviceName() } }),
    onSuccess: (response) => startSession(queryClient, response),
  });
}

export function useVerifyPhone() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (code: string) =>
      api<{ data: User }>("/auth/phone/verify", { method: "POST", body: { code } }).then((response) => response.data),
    onSuccess: (user) => queryClient.setQueryData(meKey, user),
  });
}

export function useResendCode() {
  return useMutation({
    mutationFn: () => api<{ message: string }>("/auth/phone/send-code", { method: "POST" }),
  });
}

export function useSignOut() {
  return useMutation({
    mutationFn: async () => {
      // Revoke this browser's token; sign out locally even if the request fails.
      await api("/auth/logout", { method: "POST" }).catch(() => undefined);
      session.setToken(null);
    },
  });
}
