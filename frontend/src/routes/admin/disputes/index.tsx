import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, MessageSquareWarning } from "lucide-react";
import { FilterTabs, PageHeading, QueueList, rowsOf } from "@/components/admin/ui";
import { disputeReasons, useAdminList, useDashboard, isAdmin } from "@/lib/admin";
import { useSession } from "@/lib/auth";
import { formatMoney, timeAgo } from "@/lib/format";
import type { Dispute } from "@/lib/types";

type Status = Dispute["status"];

const TABS: readonly { value: Status; label: string }[] = [
  { value: "open", label: "Open" },
  { value: "resolved", label: "Resolved" },
  { value: "withdrawn", label: "Withdrawn" },
];

const EMPTY: Record<Status, { title: string; text: string }> = {
  open: { title: "No open disputes", text: "When a buyer reports a problem with an order, it waits here for your decision." },
  resolved: { title: "Nothing resolved yet", text: "Disputes you decide are listed here." },
  withdrawn: { title: "Nothing withdrawn", text: "Disputes buyers withdrew themselves are listed here." },
};

export const Route = createFileRoute("/admin/disputes/")({
  validateSearch: (search: Record<string, unknown>): { status?: Status } => ({
    status: TABS.find((tab) => tab.value !== "open" && tab.value === search.status)?.value,
  }),
  component: Disputes,
});

function Disputes() {
  const { status = "open" } = Route.useSearch();
  const navigate = Route.useNavigate();
  const { user } = useSession();
  const list = useAdminList<Dispute>("/admin/disputes", { status });
  const open = useDashboard(30, isAdmin(user)).data?.queues.open_disputes;
  const disputes = rowsOf(list.data);

  return (
    <>
      <PageHeading
        title="Disputes"
        text="Problems buyers reported after receiving an order. Read both sides, then decide: a full refund, part of it, or none. The farm's earnings for the order wait until you do."
      />
      <FilterTabs
        options={TABS.map((tab) => ({ ...tab, count: tab.value === "open" ? open : undefined }))}
        value={status}
        onSelect={(value) => navigate({ search: value === "open" ? {} : { status: value } })}
      />
      <QueueList query={list} count={disputes.length} empty={EMPTY[status]}>
        {disputes.map((dispute) => (
          <li key={dispute.id}>
            <Link to="/admin/disputes/$disputeId" params={{ disputeId: dispute.id }} className="admin-row">
              <span className="admin-row-icon warn">
                <MessageSquareWarning />
              </span>
              <span className="admin-row-main">
                <strong>
                  {disputeReasons[dispute.reason] ?? dispute.reason} · {dispute.seller_order.number}
                </strong>
                <small>
                  {dispute.buyer.name} against {dispute.farm.name} · order {formatMoney(dispute.seller_order.total, dispute.currency)}
                  {dispute.requested_refund && `, asks for ${formatMoney(dispute.requested_refund, dispute.currency)} back`}
                </small>
                {dispute.description && <small className="admin-clip">“{dispute.description}”</small>}
                <small className="admin-when">
                  {dispute.status === "open"
                    ? dispute.created_at && `Reported ${timeAgo(dispute.created_at)}`
                    : dispute.resolved_at
                      ? `Resolved ${timeAgo(dispute.resolved_at)}`
                      : dispute.withdrawn_at && `Withdrawn ${timeAgo(dispute.withdrawn_at)}`}
                </small>
              </span>
              <ChevronRight className="admin-row-arrow" />
            </Link>
          </li>
        ))}
      </QueueList>
    </>
  );
}
