import { useEffect, useRef, useState, type FormEvent } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { CircleCheck, FileDown, KeyRound, LoaderCircle, MapPin, MessageCircle, PartyPopper, Smartphone, Star, TriangleAlert } from "lucide-react";
import { toast } from "sonner";
import { ConfirmDialog, Pill } from "@/components/admin/ui";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { api, downloadFile, errorMessage } from "@/lib/api";
import { useSession } from "@/lib/auth";
import { formatDateTime, formatMoney, formatPhone } from "@/lib/format";
import { buyerOrderStatus, sellerOrderStatus } from "@/lib/order-status";
import { paymentStatus, startPayment, useOrder, useOrderAction } from "@/lib/shop";
import type { BuyerOrder, PaymentWithAction, SellerOrder } from "@/lib/types";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/orders/$orderId")({
  validateSearch: (search: Record<string, unknown>): { placed?: boolean } => ({ placed: search.placed === true || search.placed === "true" ? true : undefined }),
  component: OrderPage,
});

// Shown while the payment provider is the sandbox (development and the pilot's test builds).
const SANDBOX = import.meta.env.DEV || import.meta.env.VITE_SANDBOX_PAYMENTS === "true";

function OrderPage() {
  return (
    <ShopPage title="Your order" back={{ to: "/orders", label: "My orders" }} signInReason="Sign in to see this order.">
      <OrderDetail />
    </ShopPage>
  );
}

function OrderDetail() {
  const { orderId } = Route.useParams();
  const { placed } = Route.useSearch();
  const query = useOrder(orderId);
  const [cancelling, setCancelling] = useState(false);
  const [saving, setSaving] = useState(false);
  const cancel = useOrderAction(() => api(`/orders/${orderId}/cancel`, { method: "POST" }));
  const order = query.data;

  if (!order) {
    return query.isError ? (
      <div className="empty-state shop-card">
        <TriangleAlert />
        <h3>This order didn't load</h3>
        <p>{errorMessage(query.error)}</p>
        <Button onClick={() => query.refetch()}>Try again</Button>
      </div>
    ) : (
      <div className="shop-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }

  const status = buyerOrderStatus(order);
  const money = (amount: string) => formatMoney(amount, order.currency);

  async function saveReceipt() {
    setSaving(true);
    try {
      await downloadFile(`/orders/${order!.id}/receipt`, `MundaLinq-${order!.number}.pdf`);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSaving(false);
    }
  }
  const canCancel = order.status === "awaiting_confirmation" || order.status === "awaiting_payment";

  return (
    <>
      {placed && order.status === "awaiting_confirmation" && (
        <div className="shop-notice good">
          <PartyPopper />
          <p>
            <strong>Order placed.</strong> The farm has been sent a notification and an SMS. They can message you here if they have a question.
          </p>
        </div>
      )}

      <section className="shop-card order-head">
        <div>
          <Pill tone={status.tone}>{status.label}</Pill>
          <h2>
            {order.number} · {money(order.total)}
          </h2>
          <p>{status.text}</p>
          {order.created_at && <small className="field-hint">Placed {formatDateTime(order.created_at)}</small>}
        </div>
        <div className="order-head-actions">
          <Button variant="outline" size="sm" onClick={saveReceipt} disabled={saving}>
            {saving ? <LoaderCircle className="animate-spin" /> : <FileDown />}
            {order.status === "paid" || order.status === "completed" ? "Receipt (PDF)" : "Order summary (PDF)"}
          </Button>
          {canCancel && (
            <Button variant="ghost" className="order-cancel" onClick={() => setCancelling(true)}>
              Cancel order
            </Button>
          )}
        </div>
      </section>

      {order.status === "awaiting_payment" && <PayPanel order={order} onPaid={() => query.refetch()} />}

      {order.seller_orders?.map((part) => <FarmPart key={part.id} order={order} part={part} />)}

      <section className="shop-card">
        <dl className="summary-lines">
          <div>
            <dt>Produce</dt>
            <dd>{money(order.subtotal)}</dd>
          </div>
          <div>
            <dt>Delivery</dt>
            <dd>{money(order.delivery_fee)}</dd>
          </div>
          <div className="summary-total">
            <dt>Total</dt>
            <dd>{money(order.total)}</dd>
          </div>
        </dl>
        {order.note && <p className="field-hint">Your note: “{order.note}”</p>}
      </section>

      <ConfirmDialog
        open={cancelling}
        onOpenChange={setCancelling}
        title={`Cancel ${order.number}?`}
        description="The farm is told straight away. Nothing has been charged."
        confirmLabel="Cancel order"
        destructive
        onConfirm={async () => {
          await cancel.mutateAsync(undefined);
          toast.success("Order cancelled");
        }}
      />
    </>
  );
}

function FarmPart({ order, part }: { order: BuyerOrder; part: SellerOrder }) {
  const status = sellerOrderStatus(part);
  const money = (amount: string) => formatMoney(amount, order.currency);
  const [dialog, setDialog] = useState<null | "receipt" | "problem">(null);
  const confirm = useOrderAction(() => api(`/seller-orders/${part.id}/confirm-receipt`, { method: "POST" }));
  const pickup = part.fulfilment_method === "pickup";

  return (
    <section className="shop-card farm-part">
      <div className="farm-part-head">
        <h2>{part.farm.name}</h2>
        <Pill tone={status.tone}>{status.label}</Pill>
      </div>
      <p className="farm-part-status">{status.text}</p>

      {part.handover_code && (
        <div className="handover-code">
          <KeyRound />
          <div>
            <small>Your handover code</small>
            <strong>{part.handover_code}</strong>
            <span>{pickup ? "Show it when you collect your order." : "Give it to the person delivering your order."}</span>
          </div>
        </div>
      )}

      <ul className="checkout-lines">
        {part.items?.map((item) => {
          const short = item.confirmed_quantity !== null && item.confirmed_quantity !== item.quantity;
          return (
            <li key={item.id}>
              <span>
                {item.quantity} {Number(item.quantity) === 1 ? item.unit.name : item.unit.plural_name} · {item.label ? `${item.title} (${item.label})` : item.title}
                {short && <small className="admin-warn">The farm can supply {item.confirmed_quantity}</small>}
              </span>
              <strong>{money(item.line_total)}</strong>
            </li>
          );
        })}
      </ul>

      <p className="field-hint">
        {pickup ? "Collection at the farm" : `Delivery${Number(part.delivery_fee) > 0 ? ` (${money(part.delivery_fee)})` : ""}`}
        {part.delivery_address?.address_line && ` to ${part.delivery_address.address_line}, ${part.delivery_address.district ?? ""}`}
      </p>
      {part.pickup_locations?.map((place, index) => (
        <a
          key={index}
          className="pickup-place"
          href={`https://www.google.com/maps/search/?api=1&query=${place.latitude},${place.longitude}`}
          target="_blank"
          rel="noreferrer"
        >
          <MapPin /> Collect at: {[place.name, place.address_line, place.district].filter(Boolean).join(", ")}
        </a>
      ))}

      <div className="farm-part-actions">
        <Button variant="outline" asChild>
          <Link to="/messages/chat" search={{ farm: part.farm.id, order: part.id, name: part.farm.name }}>
            <MessageCircle /> Message {part.farm.name}
          </Link>
        </Button>
        {part.farm.contact_phone && (
          <Button variant="ghost" asChild>
            <a href={`tel:${part.farm.contact_phone}`}>Call {formatPhone(part.farm.contact_phone)}</a>
          </Button>
        )}
        {part.status === "delivered" && (
          <>
            <Button onClick={() => setDialog("receipt")}>
              <CircleCheck /> I received it
            </Button>
            <Button variant="ghost" onClick={() => setDialog("problem")}>
              Report a problem
            </Button>
          </>
        )}
      </div>

      {part.status === "completed" && !part.review && <ReviewForm part={part} />}
      {part.review && (
        <p className="field-hint">
          You rated {part.farm.name} {part.review.rating} out of 5. Thank you!
        </p>
      )}

      <ConfirmDialog
        open={dialog === "receipt"}
        onOpenChange={(open) => !open && setDialog(null)}
        title="Got everything?"
        description={`This completes the order and releases the payment to ${part.farm.name}.`}
        confirmLabel="Yes, I received it"
        onConfirm={async () => {
          await confirm.mutateAsync(undefined);
          toast.success("Thanks! The order is complete.");
        }}
      />
      {dialog === "problem" && <ProblemDialog part={part} currency={order.currency} onClose={() => setDialog(null)} />}
    </section>
  );
}

const PROBLEMS = [
  { value: "missing_items", label: "Items missing" },
  { value: "damaged", label: "Arrived damaged" },
  { value: "poor_quality", label: "Poor quality" },
  { value: "wrong_items", label: "Wrong items" },
  { value: "other", label: "Something else" },
] as const;

function ProblemDialog({ part, currency, onClose }: { part: SellerOrder; currency: string; onClose: () => void }) {
  const [reason, setReason] = useState<string | null>(null);
  const [description, setDescription] = useState("");
  const [refund, setRefund] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const report = useOrderAction(() =>
    api(`/seller-orders/${part.id}/dispute`, { method: "POST", body: { reason, description: description.trim(), ...(refund ? { requested_refund: refund } : {}) } }),
  );

  async function send(event: FormEvent) {
    event.preventDefault();
    if (!reason) return setProblem("Choose what went wrong.");
    if (description.trim().length < 10) return setProblem("Describe what happened in a sentence or two.");
    setProblem(null);
    try {
      await report.mutateAsync(undefined);
      toast.success("Problem reported", { description: "MundaLinq will look into it and message you." });
      onClose();
    } catch (error) {
      setProblem(errorMessage(error));
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>Report a problem with {part.number}</DialogTitle>
          <DialogDescription>MundaLinq hears both sides and decides on a refund. The farm's payment waits until then.</DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={send} noValidate>
          <div className="reason-chips" role="radiogroup" aria-label="What went wrong">
            {PROBLEMS.map((option) => (
              <button key={option.value} type="button" role="radio" aria-checked={reason === option.value} className={cn("choice", reason === option.value && "selected")} onClick={() => setReason(option.value)}>
                {option.label}
              </button>
            ))}
          </div>
          <label className="field-label" htmlFor="problem-description">
            What happened?
          </label>
          <textarea id="problem-description" className="admin-textarea" maxLength={2000} value={description} onChange={(event) => setDescription(event.target.value)} />
          <label className="field-label" htmlFor="problem-refund">
            How much would you like back? (optional, up to {formatMoney(part.total, currency)})
          </label>
          <Input id="problem-refund" inputMode="decimal" value={refund} onChange={(event) => setRefund(event.target.value.replace(/[^0-9.]/g, ""))} placeholder="e.g. 2.00" />
          {problem && <p className="field-error">{problem}</p>}
          <div className="dialog-actions">
            <Button type="button" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={report.isPending}>
              {report.isPending && <LoaderCircle className="animate-spin" />}
              Report problem
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ReviewForm({ part }: { part: SellerOrder }) {
  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const review = useOrderAction(() => api(`/seller-orders/${part.id}/review`, { method: "POST", body: { rating, comment: comment.trim() || null } }));

  async function send(event: FormEvent) {
    event.preventDefault();
    if (!rating) return setProblem("Tap the stars to choose a rating.");
    setProblem(null);
    try {
      await review.mutateAsync(undefined);
      toast.success("Thanks for your review");
    } catch (error) {
      setProblem(errorMessage(error));
    }
  }

  return (
    <form className="review-form" onSubmit={send}>
      <strong>How was {part.farm.name}?</strong>
      <div className="star-picker" role="radiogroup" aria-label="Rating">
        {[1, 2, 3, 4, 5].map((value) => (
          <button key={value} type="button" role="radio" aria-checked={rating === value} aria-label={`${value} star${value === 1 ? "" : "s"}`} onClick={() => setRating(value)}>
            <Star className={value <= rating ? "on" : undefined} />
          </button>
        ))}
      </div>
      <textarea className="admin-textarea" maxLength={1000} value={comment} onChange={(event) => setComment(event.target.value)} placeholder="What did you like? (optional)" />
      {problem && <p className="field-error">{problem}</p>}
      <Button type="submit" disabled={review.isPending}>
        Send review
      </Button>
    </form>
  );
}

/** Mobile money: the buyer approves a prompt on their phone, and the page checks until the money arrives. */
function PayPanel({ order, onPaid }: { order: BuyerOrder; onPaid: () => void }) {
  const { user } = useSession();
  const [phone, setPhone] = useState(user?.phone ?? "");
  const [payment, setPayment] = useState<PaymentWithAction | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const checks = useRef(0);
  const paid = useRef(onPaid);
  paid.current = onPaid;

  // While the prompt is open, check every 3 seconds for up to 3 minutes.
  useEffect(() => {
    if (payment?.status !== "pending") return;
    const timer = window.setInterval(async () => {
      checks.current += 1;
      try {
        const latest = await paymentStatus(payment.id);
        if (latest.status !== "pending" || checks.current > 60) {
          window.clearInterval(timer);
          setPayment(latest);
          if (latest.status === "succeeded") {
            toast.success("Payment received", { description: "The farm is getting your order ready." });
            paid.current();
          } else if (latest.status === "pending") {
            setProblem("We haven't heard back from your phone. Check it and try again.");
            setPayment(null);
          } else {
            setProblem(latest.failure_reason ?? "The payment didn't go through. Try again.");
          }
        }
      } catch {
        // A blip: try again on the next tick.
      }
    }, 3_000);
    return () => window.clearInterval(timer);
  }, [payment]);

  async function pay(event: FormEvent) {
    event.preventDefault();
    if (!phone.trim()) return setProblem("Enter the mobile money number to pay from.");
    setProblem(null);
    setStarting(true);
    checks.current = 0;
    try {
      setPayment(await startPayment(order.id, phone.trim()));
    } catch (error) {
      setProblem(errorMessage(error));
    } finally {
      setStarting(false);
    }
  }

  const waiting = payment?.status === "pending";

  return (
    <section className="shop-card pay-panel">
      <h2>
        <Smartphone /> Pay {formatMoney(order.total, order.currency)} by mobile money
      </h2>
      {waiting ? (
        <div className="pay-waiting">
          <LoaderCircle className="animate-spin" />
          <p>{payment.next_action?.message ?? `Check your phone (${formatPhone(payment.payer_phone ?? phone)}) and approve the payment.`}</p>
        </div>
      ) : (
        <form className="pay-form" onSubmit={pay}>
          <label className="field-label" htmlFor="pay-phone">
            EcoCash or OneMoney number
          </label>
          <div className="pay-row">
            <Input id="pay-phone" type="tel" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="0771 234 567" />
            <Button type="submit" disabled={starting}>
              {starting && <LoaderCircle className="animate-spin" />}
              Pay now
            </Button>
          </div>
          {SANDBOX && <small className="field-hint">Test payments: 0771111111 approves, 0773333333 is cancelled. No real money moves.</small>}
        </form>
      )}
      {problem && <p className="field-error">{problem}</p>}
    </section>
  );
}
