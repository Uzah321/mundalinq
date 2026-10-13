import { useState, type FormEvent } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { Banknote, LoaderCircle, RotateCcw } from "lucide-react";
import { toast } from "sonner";
import { AdminField, ConfirmDialog, FilterTabs, ItemState, PageHeading, Pill, QueueList, ReasonDialog, rowsOf, useSubmit } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { humanize, paymentStatuses, refundStatuses, statusOf, useAdminAction, useAdminItem, useAdminList, useDashboard } from "@/lib/admin";
import { api } from "@/lib/api";
import { formatDateTime, formatMoney, formatPhone, timeAgo, toMinor } from "@/lib/format";
import type { Payment, Payout, PayoutDue, Refund } from "@/lib/types";

type Tab = "due" | "payouts" | "payments" | "refunds";
type Filter = "attention" | "all";

export const Route = createFileRoute("/admin/money")({
  validateSearch: (search: Record<string, unknown>): { tab?: Exclude<Tab, "due">; show?: "all" } => ({
    tab: (["payouts", "payments", "refunds"] as const).find((tab) => tab === search.tab),
    show: search.show === "all" ? "all" : undefined,
  }),
  component: Money,
});

function Money() {
  const { tab = "due", show } = Route.useSearch();
  const navigate = Route.useNavigate();
  const queues = useDashboard(30).data?.queues;
  const filter: Filter = show ?? "attention";

  return (
    <>
      <PageHeading title="Money" text="Pay farms what they've earned, and sort out payments and refunds that need a person." />
      <FilterTabs
        options={[
          { value: "due", label: "Pay farms", count: queues?.farms_awaiting_payout },
          { value: "payouts", label: "Payouts made" },
          { value: "payments", label: "Payments", count: queues?.payments_to_refund },
          { value: "refunds", label: "Refunds", count: queues?.failed_refunds },
        ]}
        value={tab}
        onSelect={(value) => navigate({ search: value === "due" ? {} : { tab: value } })}
      />
      {(tab === "payments" || tab === "refunds") && (
        <FilterTabs
          label="Which"
          options={[
            { value: "attention", label: tab === "payments" ? "Needs a refund" : "Failed" },
            { value: "all", label: tab === "payments" ? "All payments" : "All refunds" },
          ]}
          value={filter}
          onSelect={(value) => navigate({ search: { tab, show: value === "all" ? "all" : undefined } })}
        />
      )}
      {tab === "due" && <PayoutsDue />}
      {tab === "payouts" && <PayoutHistory />}
      {tab === "payments" && <Payments filter={filter} />}
      {tab === "refunds" && <Refunds filter={filter} />}
    </>
  );
}

function PayoutsDue() {
  const due = useAdminItem<PayoutDue[]>("/admin/payouts/due");
  const [paying, setPaying] = useState<PayoutDue | null>(null);
  if (!due.data) return <ItemState query={due} missing="Payouts aren't available" />;

  return (
    <>
      <p className="admin-hint admin-list-note">
        Send the money to the farm's account first (EcoCash, OneMoney or bank), then record it here with the transfer reference. The farm sees it under
        Earnings.
      </p>
      {due.data.length === 0 ? (
        <div className="empty-state admin-empty">
          <Banknote />
          <h3>No farm is owed money</h3>
          <p>Earnings become available to pay out a few days after an order is completed.</p>
        </div>
      ) : (
        <ul className="admin-list">
          {due.data.map((row) => {
            const account = row.payout_account;
            return (
              <li key={row.farm.id} className="admin-row">
                <span className="admin-row-icon">
                  <Banknote />
                </span>
                <span className="admin-row-main">
                  <strong>
                    {row.farm.name} · {formatMoney(row.available, row.currency)}
                  </strong>
                  {account ? (
                    <small>
                      {humanize(account.provider ?? account.method)} · {account.account_name} · ••••{account.account_number_last4}
                    </small>
                  ) : (
                    <small className="admin-reason">No payout account yet. Ask the farm to add one under Farm › Earnings.</small>
                  )}
                  {account?.on_hold_until && (
                    <small className="admin-warn">Account changed recently: on hold until {formatDateTime(account.on_hold_until)}</small>
                  )}
                </span>
                <Button variant="outline" size="sm" className="admin-row-action" onClick={() => setPaying(row)}>
                  Record payout
                </Button>
              </li>
            );
          })}
        </ul>
      )}
      {paying && <RecordPayout due={paying} onClose={() => setPaying(null)} />}
    </>
  );
}

function RecordPayout({ due, onClose }: { due: PayoutDue; onClose: () => void }) {
  const [amount, setAmount] = useState(due.available);
  const [reference, setReference] = useState("");
  const [note, setNote] = useState("");
  const [problems, setProblems] = useState<{ amount?: string; reference?: string }>({});
  const record = useAdminAction(() => api(`/admin/farms/${due.farm.id}/payouts`, { method: "POST", body: { amount, reference: reference.trim(), note: note.trim() || null } }));
  const submit = useSubmit();
  const money = (value: string) => formatMoney(value, due.currency);

  async function save(event: FormEvent) {
    event.preventDefault();
    const minor = toMinor(amount);
    const found: typeof problems = {};
    if (minor === null || minor <= 0) found.amount = "Enter the amount you sent, e.g. 120.00";
    else if (minor > (toMinor(due.available) ?? 0)) found.amount = `That's more than the ${money(due.available)} available.`;
    if (!reference.trim()) found.reference = "Enter the transfer reference from EcoCash or the bank.";
    setProblems(found);
    if (Object.keys(found).length) return;
    if (await submit.run(() => record.mutateAsync(undefined))) {
      toast.success(`Recorded ${money(amount)} paid to ${due.farm.name}`);
      onClose();
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>Record a payout to {due.farm.name}</DialogTitle>
          <DialogDescription>Only record money you've already sent. {money(due.available)} is available.</DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={save} noValidate>
          <AdminField id="payout-amount" label="Amount sent" error={problems.amount ?? submit.fieldError("amount")}>
            <Input id="payout-amount" inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value.replace(/[^0-9.]/g, ""))} />
          </AdminField>
          <AdminField id="payout-reference" label="Transfer reference" error={problems.reference ?? submit.fieldError("reference")}>
            <Input id="payout-reference" value={reference} maxLength={100} onChange={(event) => setReference(event.target.value)} placeholder="e.g. MP240913.1452.A12345" />
          </AdminField>
          <AdminField id="payout-note" label="Note (optional)">
            <Input id="payout-note" value={note} maxLength={300} onChange={(event) => setNote(event.target.value)} />
          </AdminField>
          {submit.generalError && <p className="field-error">{submit.generalError}</p>}
          <div className="dialog-actions">
            <Button type="button" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={submit.pending}>
              {submit.pending && <LoaderCircle className="animate-spin" />}
              Record payout
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PayoutHistory() {
  const list = useAdminList<Payout>("/admin/payouts");
  const payouts = rowsOf(list.data);
  return (
    <QueueList query={list} count={payouts.length} empty={{ title: "No payouts yet", text: "Payouts you record show up here." }}>
      {payouts.map((payout) => (
        <li key={payout.id} className="admin-row">
          <span className="admin-row-icon">
            <Banknote />
          </span>
          <span className="admin-row-main">
            <strong>
              {payout.farm?.name} · {formatMoney(payout.amount, payout.currency)}
            </strong>
            <small>
              {[humanize(payout.provider ?? payout.method), payout.account_name, payout.account_number_last4 && `••••${payout.account_number_last4}`, `ref ${payout.reference}`]
                .filter(Boolean)
                .join(" · ")}
            </small>
            {payout.note && <small>{payout.note}</small>}
            {payout.paid_at && <small className="admin-when">Paid {formatDateTime(payout.paid_at)}</small>}
          </span>
        </li>
      ))}
    </QueueList>
  );
}

function Payments({ filter }: { filter: Filter }) {
  const list = useAdminList<Payment>("/admin/payments", filter === "attention" ? { requires_refund: true } : {});
  const payments = rowsOf(list.data);
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const refund = useAdminAction((id: string) => api(`/admin/payments/${id}/refund`, { method: "POST" }));

  return (
    <>
      {filter === "attention" && (
        <p className="admin-hint admin-list-note">Money that arrived for an order that could no longer take it, e.g. paid after it was cancelled. Send it back.</p>
      )}
      <QueueList
        query={list}
        count={payments.length}
        empty={filter === "attention" ? { title: "Nothing to refund", text: "Payments that need sending back show up here." } : { title: "No payments yet", text: "Buyers' payments show up here." }}
      >
        {payments.map((payment) => {
          const status = statusOf(paymentStatuses, payment.status);
          return (
            <li key={payment.id} className="admin-row">
              <span className="admin-row-main">
                <strong>
                  {formatMoney(payment.amount, payment.currency)} · {humanize(payment.provider)}
                </strong>
                <small>
                  {[payment.payer_phone && formatPhone(payment.payer_phone), payment.created_at && timeAgo(payment.created_at), payment.failure_reason].filter(Boolean).join(" · ")}
                  {Number(payment.refunded) > 0 && ` · ${formatMoney(payment.refunded, payment.currency)} refunded`}
                </small>
                <span className="admin-pills">
                  <Pill tone={status.tone}>{status.label}</Pill>
                  {payment.requires_refund && <Pill tone="bad">Needs a refund</Pill>}
                  <Link to="/admin/orders/$orderId" params={{ orderId: payment.order_id }} className="admin-link">
                    See the order
                  </Link>
                </span>
              </span>
              {payment.requires_refund && (
                <Button variant="outline" size="sm" className="admin-row-action" onClick={() => setRefunding(payment)}>
                  <RotateCcw /> Refund
                </Button>
              )}
            </li>
          );
        })}
      </QueueList>
      <ConfirmDialog
        open={refunding !== null}
        onOpenChange={(open) => !open && setRefunding(null)}
        title={refunding ? `Send ${formatMoney(refunding.amount, refunding.currency)} back?` : ""}
        description={refunding?.payer_phone ? `It goes back to ${formatPhone(refunding.payer_phone)} through ${humanize(refunding.provider)}.` : "It goes back the way it was paid."}
        confirmLabel="Refund"
        onConfirm={async () => {
          await refund.mutateAsync(refunding!.id);
          toast.success("Refund sent");
        }}
      />
    </>
  );
}

function Refunds({ filter }: { filter: Filter }) {
  const list = useAdminList<Refund>("/admin/refunds", filter === "attention" ? { status: "failed" } : {});
  const refunds = rowsOf(list.data);
  const [action, setAction] = useState<null | { retry: Refund } | { manual: Refund }>(null);
  const retry = useAdminAction((id: string) => api(`/admin/refunds/${id}/retry`, { method: "POST" }));
  const complete = useAdminAction(({ id, reference }: { id: string; reference: string }) =>
    api(`/admin/refunds/${id}/complete`, { method: "POST", body: { reference } }),
  );
  const retrying = action && "retry" in action ? action.retry : null;
  const manual = action && "manual" in action ? action.manual : null;

  return (
    <>
      <QueueList
        query={list}
        count={refunds.length}
        empty={filter === "attention" ? { title: "No failed refunds", text: "Refunds the payment provider couldn't send show up here." } : { title: "No refunds yet", text: "Refunds show up here." }}
      >
        {refunds.map((refund) => {
          const status = statusOf(refundStatuses, refund.status);
          return (
            <li key={refund.id} className="admin-row">
              <span className="admin-row-main">
                <strong>{formatMoney(refund.amount, refund.currency)}</strong>
                <small>{[refund.created_at && timeAgo(refund.created_at), refund.provider_reference && `ref ${refund.provider_reference}`, refund.failure_reason].filter(Boolean).join(" · ")}</small>
                <span className="admin-pills">
                  <Pill tone={status.tone}>{status.label}</Pill>
                  <Link to="/admin/orders/$orderId" params={{ orderId: refund.order_id }} className="admin-link">
                    See the order
                  </Link>
                </span>
              </span>
              {refund.status === "failed" && (
                <span className="admin-row-buttons">
                  <Button variant="outline" size="sm" onClick={() => setAction({ retry: refund })}>
                    <RotateCcw /> Try again
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => setAction({ manual: refund })}>
                    Paid by hand
                  </Button>
                </span>
              )}
            </li>
          );
        })}
      </QueueList>
      <ConfirmDialog
        open={retrying !== null}
        onOpenChange={(open) => !open && setAction(null)}
        title={retrying ? `Try sending ${formatMoney(retrying.amount, retrying.currency)} again?` : ""}
        description="The payment provider is asked to send the refund again."
        confirmLabel="Try again"
        onConfirm={async () => {
          await retry.mutateAsync(retrying!.id);
          toast.success("Refund sent again");
        }}
      />
      <ReasonDialog
        open={manual !== null}
        onOpenChange={(open) => !open && setAction(null)}
        title="Record a refund you sent by hand"
        description={manual ? `Only if you've already sent ${formatMoney(manual.amount, manual.currency)} to the buyer yourself.` : ""}
        label="Transfer reference"
        field="reference"
        minLength={3}
        maxLength={100}
        tooShort="Enter the reference of the transfer you made."
        submitLabel="Mark as refunded"
        onSubmit={async (reference) => {
          await complete.mutateAsync({ id: manual!.id, reference });
          toast.success("Refund marked as paid");
        }}
      />
    </>
  );
}
