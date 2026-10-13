import { useEffect, useState } from "react";
import { useInfiniteQuery, useMutation, useQuery, useQueryClient, type Query } from "@tanstack/react-query";
import { api, apiBlob } from "./api";
import { meKey } from "./auth";
import type { AdminFarm, Dashboard, FarmerType, FarmVerification, Paginated, User, VerificationDocument, VerificationStatus } from "./types";

// Staff tools. Cached under "me" like everything else tied to the session, so signing out clears them.

export type StaffRole = "super_admin" | "admin" | "support_agent";

const hasRole = (user: User | null, roles: readonly string[]) => user?.roles?.some((role) => roles.includes(role)) ?? false;

/** Anyone who gets into /admin: the API's staff roles. */
export const isStaff = (user: User | null) => hasRole(user, ["super_admin", "admin", "support_agent"]);
/** Verifications, approvals, money, catalogue (the API's role:admin,super_admin). */
export const isAdmin = (user: User | null) => hasRole(user, ["super_admin", "admin"]);
/** Roles and the audit log. */
export const isSuperAdmin = (user: User | null) => hasRole(user, ["super_admin"]);

export const roleNames: Record<string, string> = {
  super_admin: "Super admin",
  admin: "Admin",
  support_agent: "Support agent",
  farmer: "Farmer",
  buyer: "Buyer",
  business_buyer: "Business buyer",
  delivery_partner: "Delivery partner",
};

export const roleName = (role: string) => roleNames[role] ?? role.replaceAll("_", " ");

export type ReviewStatus = FarmVerification["status"];

export const farmerTypes: Record<FarmerType, string> = {
  smallholder: "Smallholder farmer",
  commercial: "Commercial farm",
  cooperative: "Co-operative",
  supply_agent: "Supply agent",
};

export const idDocumentTypes: Record<FarmVerification["id_document_type"], string> = {
  national_id: "National ID",
  passport: "Passport",
};

export const documentTypes: Record<VerificationDocument["type"], string> = {
  identity: "ID document",
  business_registration: "Registration certificate",
  land_proof: "Proof of land",
  other: "Other document",
};

/** Everything the API records in the audit log (AuditLogger::record calls). */
export const auditActions: Record<string, string> = {
  "user.suspended": "Suspended an account",
  "user.reactivated": "Reactivated an account",
  "user.role_granted": "Added a role",
  "user.role_revoked": "Removed a role",
  "farm.verification_submitted": "Sent farm documents",
  "farm.verification_document_viewed": "Viewed an ID document",
  "farm.verification_approved": "Approved a farm",
  "farm.verification_rejected": "Rejected a farm",
  "farm.payout_account_changed": "Changed the payout account",
  "payout.recorded": "Recorded a payout",
  "product.approved": "Approved a listing",
  "product.rejected": "Rejected a listing",
  "product.taken_down": "Took down a listing",
  "product.featured": "Featured a listing",
  "product.featuring_stopped": "Stopped featuring a listing",
  "review.hidden": "Hid a review",
  "review.unhidden": "Showed a review again",
  "dispute.resolved": "Resolved a dispute",
  "payment.refunded": "Refunded a payment",
  "refund.paid_manually": "Marked a refund as paid",
};

export const farmStatuses: Record<VerificationStatus, { label: string; tone: "wait" | "good" | "bad" | "plain" }> = {
  unverified: { label: "ID not sent", tone: "plain" },
  pending: { label: "Waiting for review", tone: "wait" },
  approved: { label: "Verified", tone: "good" },
  rejected: { label: "Not approved", tone: "bad" },
};

export const disputeReasons: Record<string, string> = {
  missing_items: "Items missing",
  damaged: "Arrived damaged",
  poor_quality: "Poor quality",
  wrong_items: "Wrong items",
  other: "Something else",
};

export const disputeResolutions: Record<string, string> = {
  refund_full: "Full refund",
  refund_partial: "Part refund",
  no_refund: "No refund",
};

type Tone = "wait" | "good" | "bad" | "plain";

export const orderStatuses: Record<string, { label: string; tone: Tone }> = {
  awaiting_confirmation: { label: "Waiting for farms", tone: "wait" },
  awaiting_payment: { label: "Awaiting payment", tone: "wait" },
  paid: { label: "Paid", tone: "good" },
  completed: { label: "Completed", tone: "good" },
  cancelled: { label: "Cancelled", tone: "bad" },
};

/** One farm's part of an order. */
export const sellerOrderStatuses: Record<string, { label: string; tone: Tone }> = {
  awaiting_confirmation: { label: "Waiting for the farm", tone: "wait" },
  accepted: { label: "Accepted", tone: "plain" },
  rejected: { label: "Declined by the farm", tone: "bad" },
  expired: { label: "Not answered in time", tone: "bad" },
  cancelled: { label: "Cancelled", tone: "bad" },
  preparing: { label: "Preparing", tone: "plain" },
  ready: { label: "Ready", tone: "plain" },
  dispatched: { label: "On the way", tone: "plain" },
  delivered: { label: "Handed over", tone: "good" },
  disputed: { label: "Problem reported", tone: "bad" },
  completed: { label: "Completed", tone: "good" },
};

export const paymentStatuses: Record<string, { label: string; tone: Tone }> = {
  pending: { label: "Waiting for approval", tone: "wait" },
  succeeded: { label: "Paid", tone: "good" },
  failed: { label: "Failed", tone: "bad" },
  expired: { label: "Expired", tone: "plain" },
};

export const refundStatuses: Record<string, { label: string; tone: Tone }> = {
  pending: { label: "Sending", tone: "wait" },
  succeeded: { label: "Refunded", tone: "good" },
  failed: { label: "Failed", tone: "bad" },
};

export const statusOf = (map: Record<string, { label: string; tone: Tone }>, status: string) => map[status] ?? { label: humanize(status), tone: "plain" as Tone };

/** "awaiting_confirmation" -> "Awaiting confirmation", for statuses without a nicer name. */
export const humanize = (value: string) => value.charAt(0).toUpperCase() + value.slice(1).replaceAll("_", " ");

const adminKey = [...meKey, "admin"] as const;
// Files are fetched once and kept: every view of an ID document or dispute photo is audited.
const FILE_KEYS = ["verification-documents", "files"];

type Query_ = Record<string, string | number | boolean | undefined | null>;

/** A paged staff list, newest page last; "Show more" fetches the next. */
export function useAdminList<T>(path: string, query: Query_ = {}, enabled = true) {
  return useInfiniteQuery({
    queryKey: [...adminKey, path, query],
    queryFn: ({ pageParam, signal }) =>
      api<Paginated<T>>(path, { query: { per_page: 20, ...stringify(query), page: pageParam }, signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    enabled,
  });
}

/** One record (or report) from a staff endpoint that answers {data: ...}. */
export function useAdminItem<T>(path: string, query: Query_ = {}, options: { enabled?: boolean; refetchInterval?: number } = {}) {
  return useQuery({
    queryKey: [...adminKey, path, query],
    queryFn: ({ signal }) => api<{ data: T }>(path, { query: stringify(query), signal }).then((response) => response.data),
    ...options,
  });
}

function stringify(query: Query_): Record<string, string | number | undefined | null> {
  return Object.fromEntries(Object.entries(query).map(([key, value]) => [key, typeof value === "boolean" ? (value ? "1" : "0") : value]));
}

/** After any staff action, lists, details and queue counts reload; fetched files are left alone. */
export function useAdminRefresh() {
  const queryClient = useQueryClient();
  return () =>
    queryClient.invalidateQueries({
      queryKey: adminKey,
      predicate: (query: Query) => !FILE_KEYS.includes(String(query.queryKey[adminKey.length])),
    });
}

/** A staff action (approve, suspend, refund…) that refreshes what it changed. */
export function useAdminAction<TInput, TResult = unknown>(action: (input: TInput) => Promise<TResult>) {
  return useMutation({ mutationFn: action, onSuccess: useAdminRefresh() });
}

/** Queue sizes and pilot numbers; the nav badges use the queues. Admins only. */
export function useDashboard(days: number, enabled = true) {
  return useAdminItem<Dashboard>("/admin/dashboard", { days }, { enabled, refetchInterval: 60_000 });
}

/** A file behind sign-in as an object URL the page can show; released when the page no longer needs it. */
export function useFileUrl(path: string, group: "verification-documents" | "files" = "files") {
  const file = useQuery({
    queryKey: [...adminKey, group, path],
    queryFn: ({ signal }) => apiBlob(path, signal),
    staleTime: Infinity,
    gcTime: 5 * 60_000,
  });
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!file.data) return;
    const next = URL.createObjectURL(file.data);
    setUrl(next);
    return () => {
      URL.revokeObjectURL(next);
      setUrl(null);
    };
  }, [file.data]);

  return { url, type: file.data?.type ?? null, loading: file.isPending, error: file.error };
}

/** API links (e.g. dispute photos) are absolute; the client wants the path under /api/v1. */
export const apiPath = (url: string) => url.replace(/^.*\/api\/v1/, "");

// Farm verifications

export function useVerifications(status: ReviewStatus) {
  return useAdminList<FarmVerification>("/admin/farm-verifications", { status });
}

/** Farms that signed up but haven't sent their ID yet, newest first, so staff can remind them. */
export function useFarmsWithoutDocuments() {
  return useAdminList<AdminFarm>("/admin/farms", { verification_status: "unverified" });
}

export function useVerification(id: string) {
  return useAdminItem<FarmVerification>(`/admin/farm-verifications/${id}`);
}

export function useDocumentUrl(verificationId: string, documentId: string) {
  return useFileUrl(`/admin/farm-verifications/${verificationId}/documents/${documentId}`, "verification-documents");
}

export function useApproveVerification() {
  return useAdminAction((id: string) =>
    api<{ data: FarmVerification }>(`/admin/farm-verifications/${id}/approve`, { method: "POST" }).then((response) => response.data),
  );
}

export function useRejectVerification() {
  return useAdminAction(({ id, reason }: { id: string; reason: string }) =>
    api<{ data: FarmVerification }>(`/admin/farm-verifications/${id}/reject`, { method: "POST", body: { reason } }).then(
      (response) => response.data,
    ),
  );
}
