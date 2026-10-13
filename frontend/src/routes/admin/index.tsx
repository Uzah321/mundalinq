import type { ReactNode } from "react";
import { createFileRoute, Link, Navigate } from "@tanstack/react-router";
import { Banknote, CircleAlert, MessageSquareWarning, Package, RotateCcw, ShieldCheck, type LucideIcon } from "lucide-react";
import { FilterTabs, ItemState, PageHeading } from "@/components/admin/ui";
import { isAdmin, useDashboard } from "@/lib/admin";
import { useSession } from "@/lib/auth";
import { formatMoney } from "@/lib/format";

const PERIODS = [7, 30, 90] as const;
type Period = (typeof PERIODS)[number];

export const Route = createFileRoute("/admin/")({
  validateSearch: (search: Record<string, unknown>): { days?: Period } => ({
    days: PERIODS.find((days) => days !== 30 && days === Number(search.days)),
  }),
  component: Overview,
});

const percent = (value: string | null) => (value === null ? "–" : `${Number(value).toLocaleString("en", { maximumFractionDigits: 1 })}%`);

function Overview() {
  const { user } = useSession();
  const { days = 30 } = Route.useSearch();
  const navigate = Route.useNavigate();
  const dashboard = useDashboard(days, isAdmin(user));

  // Support agents start at the disputes queue.
  if (!isAdmin(user)) return <Navigate to="/admin/disputes" replace />;

  const data = dashboard.data;
  if (!data) return <ItemState query={dashboard} missing="The overview isn't available" />;
  const money = (amount: string) => formatMoney(amount, data.currency);
  const { queues } = data;

  return (
    <>
      <PageHeading title="Overview" text="What's waiting for you, and how the marketplace is doing." />

      <h2 className="admin-subheading">Waiting for you</h2>
      <div className="queue-grid">
        <QueueCard to="/admin/verifications" icon={ShieldCheck} count={queues.farm_verifications} label="Farms to verify" />
        <QueueCard to="/admin/listings" icon={Package} count={queues.listings_to_review} label="Listings to review" />
        <QueueCard to="/admin/disputes" icon={MessageSquareWarning} count={queues.open_disputes} label="Open disputes" />
        <QueueCard to="/admin/money" tab="payments" icon={RotateCcw} count={queues.payments_to_refund} label="Payments to refund" />
        <QueueCard to="/admin/money" tab="refunds" icon={CircleAlert} count={queues.failed_refunds} label="Refunds that failed" />
        <QueueCard to="/admin/money" tab="due" icon={Banknote} count={queues.farms_awaiting_payout} label="Farms to pay" />
      </div>

      <div className="admin-heading-row admin-period">
        <h2 className="admin-subheading">How it's going</h2>
        <FilterTabs
          label="Period"
          options={PERIODS.map((value) => ({ value: String(value) as `${Period}`, label: `${value} days` }))}
          value={String(days) as `${Period}`}
          onSelect={(value) => navigate({ search: Number(value) === 30 ? {} : { days: Number(value) as Period } })}
        />
      </div>
      <div className="kpi-grid">
        <Kpi label="Sales" value={money(data.gmv)} note="Paid orders, delivery included" />
        <Kpi
          label="Orders placed"
          value={data.orders.placed}
          note={`${data.orders.paid} paid · ${data.orders.completed} completed · ${data.orders.cancelled} cancelled`}
        />
        <Kpi label="Orders fulfilled" value={percent(data.orders.fulfilment_rate)} note={`Of finished orders; ${percent(data.orders.cancellation_rate)} cancelled`} />
        <Kpi label="Platform fees" value={money(data.revenue.platform_fees)} note={`${money(data.revenue.refunds)} refunded to buyers`} />
        <Kpi label="Buyers who paid" value={data.buyers.active} note={`${percent(data.buyers.repeat_rate)} bought more than once · ${data.buyers.new_accounts} new accounts`} />
        <Kpi label="Farms selling" value={data.sellers.active_verified} note={`Verified, with stock on sale · ${data.sellers.new_farms} new farms`} />
      </div>
    </>
  );
}

function QueueCard({ to, tab, icon: Icon, count, label }: { to: "/admin/verifications" | "/admin/listings" | "/admin/disputes" | "/admin/money"; tab?: "due" | "payments" | "refunds"; icon: LucideIcon; count: number; label: string }) {
  const content = (
    <>
      <Icon />
      <strong>{count}</strong>
      <span>{label}</span>
    </>
  );
  return to === "/admin/money" ? (
    <Link to={to} search={tab === "due" ? {} : { tab }} className={count ? "queue-card busy" : "queue-card"}>
      {content}
    </Link>
  ) : (
    <Link to={to} className={count ? "queue-card busy" : "queue-card"}>
      {content}
    </Link>
  );
}

function Kpi({ label, value, note }: { label: string; value: ReactNode; note: string }) {
  return (
    <div className="kpi">
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{note}</small>
    </div>
  );
}
