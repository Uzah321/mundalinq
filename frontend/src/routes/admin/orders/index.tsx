import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, Receipt } from "lucide-react";
import { FilterTabs, PageHeading, Pill, QueueList, rowsOf, SearchBox } from "@/components/admin/ui";
import { orderStatuses, statusOf, useAdminList } from "@/lib/admin";
import { formatMoney, formatPhone, timeAgo } from "@/lib/format";
import type { AdminOrder } from "@/lib/types";

type Status = AdminOrder["status"] | "all";

const TABS: readonly { value: Status; label: string }[] = [
  { value: "all", label: "All" },
  { value: "awaiting_confirmation", label: "Waiting for farms" },
  { value: "awaiting_payment", label: "Awaiting payment" },
  { value: "paid", label: "Paid" },
  { value: "completed", label: "Completed" },
  { value: "cancelled", label: "Cancelled" },
];

type OrderSearch = { q?: string; status?: Exclude<Status, "all"> };

export const Route = createFileRoute("/admin/orders/")({
  validateSearch: (search: Record<string, unknown>): OrderSearch => ({
    q: typeof search.q === "string" && search.q.trim() ? search.q.trim() : undefined,
    status: TABS.find((tab) => tab.value !== "all" && tab.value === search.status)?.value as OrderSearch["status"],
  }),
  component: Orders,
});

function Orders() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();
  const list = useAdminList<AdminOrder>("/admin/orders", { q: search.q, status: search.status });
  const orders = rowsOf(list.data);

  return (
    <>
      <PageHeading title="Orders" text="Every order on MundaLinq. Search by order number or the buyer's phone number." />
      <div className="admin-filters">
        <SearchBox
          value={search.q ?? ""}
          onChange={(q) => navigate({ search: (current) => ({ ...current, q: q || undefined }), replace: true })}
          placeholder="Order number or buyer's phone"
        />
      </div>
      <FilterTabs
        options={TABS}
        value={search.status ?? "all"}
        onSelect={(value) => navigate({ search: (current) => ({ ...current, status: value === "all" ? undefined : value }) })}
      />

      <QueueList query={list} count={orders.length} empty={{ title: "No orders found", text: "Try another number, or clear the search and filters." }}>
        {orders.map((order) => {
          const status = statusOf(orderStatuses, order.status);
          return (
            <li key={order.id}>
              <Link to="/admin/orders/$orderId" params={{ orderId: order.id }} className="admin-row">
                <span className="admin-row-icon">
                  <Receipt />
                </span>
                <span className="admin-row-main">
                  <strong>
                    {order.number} · {formatMoney(order.total, order.currency)}
                  </strong>
                  <small>
                    {order.buyer.name} · {formatPhone(order.buyer.phone)}
                  </small>
                  <small>{order.seller_orders?.map((part) => part.farm.name).join(", ")}</small>
                  <span className="admin-pills">
                    <Pill tone={status.tone}>{status.label}</Pill>
                    {order.created_at && <small className="admin-when">Placed {timeAgo(order.created_at)}</small>}
                  </span>
                </span>
                <ChevronRight className="admin-row-arrow" />
              </Link>
            </li>
          );
        })}
      </QueueList>
    </>
  );
}
