import { useEffect, useState, type FormEvent } from "react";
import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { BadgeCheck, CalendarClock, CalendarDays, LoaderCircle, MapPin, MessageCircle, Phone, Plus, Repeat, Star, TriangleAlert, Truck } from "lucide-react";
import { toast } from "sonner";
import { ConfirmDialog, Pill, type Tone } from "@/components/admin/ui";
import { VerifyPhoneDialog } from "@/components/store/account-dialog";
import { AddressDialog } from "@/components/store/address-dialog";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { errorMessage } from "@/lib/api";
import { useSession } from "@/lib/auth";
import { formatMoney, formatPhone, formatQuantity, timeAgo } from "@/lib/format";
import { useAddresses } from "@/lib/queries";
import { quoteStatus, scheduleText, useQuoteActions, useQuoteRequest, type QuoteOffer, type QuoteRequest } from "@/lib/quotes";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/quotes/$requestId")({
  component: QuotePage,
});

function QuotePage() {
  return (
    <ShopPage title="Your request" back={{ to: "/quotes", label: "Requests for quotes" }} signInReason="Sign in to see this request.">
      <QuoteDetail />
    </ShopPage>
  );
}

const offerStatus: Record<QuoteOffer["status"], { label: string; tone: Tone }> = {
  pending: { label: "Waiting for you", tone: "wait" },
  accepted: { label: "Accepted", tone: "good" },
  declined: { label: "Declined", tone: "plain" },
  withdrawn: { label: "Withdrawn by the farm", tone: "plain" },
};

const gettingIt: Record<QuoteRequest["fulfilment"], string> = {
  either: "Delivery or collection",
  delivery: "Delivered",
  pickup: "Collected from the farm",
};

const longDate = (date: string) => new Date(date.length === 10 ? `${date}T00:00:00` : date).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });

function QuoteDetail() {
  const { requestId } = Route.useParams();
  const query = useQuoteRequest(requestId);
  const { close, decline } = useQuoteActions();
  const [closing, setClosing] = useState(false);
  const [declining, setDeclining] = useState<QuoteOffer | null>(null);
  const [ordering, setOrdering] = useState<{ offer: QuoteOffer; again: boolean } | null>(null);
  const request = query.data;

  if (!request) {
    return query.isError ? (
      <div className="empty-state shop-card">
        <TriangleAlert />
        <h3>This request didn't load</h3>
        <p>{errorMessage(query.error)}</p>
        <Button onClick={() => query.refetch()}>Try again</Button>
      </div>
    ) : (
      <div className="shop-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }

  const status = quoteStatus[request.status];
  const offers = request.offers ?? [];
  const live = offers.filter((offer) => offer.status !== "withdrawn");

  return (
    <>
      <section className="shop-card quote-summary">
        <div className="quote-summary-top">
          <div>
            <h2>
              {formatQuantity(request.quantity, request.unit)} of {request.title}
            </h2>
            <small>{request.category.name}</small>
          </div>
          <Pill tone={status.tone}>{status.label}</Pill>
        </div>
        <ul className="quote-facts">
          <li>
            {request.frequency === "one_off" ? <CalendarDays /> : <Repeat />} {scheduleText(request, longDate(request.needed_by))}
          </li>
          <li>
            <Truck /> {gettingIt[request.fulfilment]}
          </li>
          <li>
            <MapPin /> {request.district}, {request.province}
          </li>
          {request.status === "open" && (
            <li>
              <CalendarClock /> Taking offers until {longDate(request.closes_at)}
            </li>
          )}
        </ul>
        {request.notes && <p className="admin-quote">“{request.notes}”</p>}
        {request.status === "open" && (
          <div className="quote-summary-actions">
            <Button variant="ghost" size="sm" onClick={() => setClosing(true)}>
              Stop taking offers
            </Button>
          </div>
        )}
      </section>

      <h2 className="quote-offers-heading">
        Offers <span>{live.length > 1 ? `${live.length} · cheapest first` : ""}</span>
      </h2>
      {!live.length ? (
        <div className="empty-state shop-card">
          <CalendarClock />
          <h3>{request.status === "open" ? "No offers yet" : "No offers"}</h3>
          <p>
            {request.status === "open"
              ? `Farms in ${request.province} that sell ${request.category.name.toLowerCase()} see your request and can send a price. Offers show up here, often within a day.`
              : "No farm sent an offer. You can post a new request with a longer time to answer, or a nearby province."}
          </p>
          {request.status !== "open" && (
            <Button asChild>
              <Link to="/quotes/new">New request</Link>
            </Button>
          )}
        </div>
      ) : (
        <div className="quote-offers">
          {live.map((offer, index) => (
            <OfferCard
              key={offer.id}
              offer={offer}
              cheapest={index === 0 && live.length > 1 && request.status === "open"}
              canDecide={request.status === "open" && offer.status === "pending"}
              onAccept={() => setOrdering({ offer, again: false })}
              onDecline={() => setDeclining(offer)}
              onOrderAgain={() => setOrdering({ offer, again: true })}
            />
          ))}
        </div>
      )}

      <ConfirmDialog
        open={closing}
        onOpenChange={setClosing}
        title="Stop taking offers?"
        description="Farms can no longer send offers and the ones waiting are declined. You can always post a new request."
        confirmLabel="Stop taking offers"
        destructive
        onConfirm={async () => {
          await close.mutateAsync(request.id);
          toast.success("Request closed");
        }}
      />
      <ConfirmDialog
        open={declining !== null}
        onOpenChange={(open) => !open && setDeclining(null)}
        title={`Decline ${declining?.farm?.name ?? "this farm"}'s offer?`}
        description="We'll let the farm know. Other offers stay open."
        confirmLabel="Decline offer"
        destructive
        onConfirm={async () => {
          if (!declining) return;
          await decline.mutateAsync(declining.id);
          toast.success("Offer declined");
        }}
      />
      {ordering && <OrderDialog request={request} offer={ordering.offer} again={ordering.again} onClose={() => setOrdering(null)} />}
    </>
  );
}

type OfferProps = {
  offer: QuoteOffer;
  cheapest: boolean;
  canDecide: boolean;
  onAccept: () => void;
  onDecline: () => void;
  onOrderAgain: () => void;
};

function OfferCard({ offer, cheapest, canDecide, onAccept, onDecline, onOrderAgain }: OfferProps) {
  const money = (amount: string) => formatMoney(amount, offer.currency);
  const status = offerStatus[offer.status];
  const delivery = offer.fulfilment === "delivery";

  return (
    <article className={cn("shop-card quote-offer", offer.status === "accepted" && "accepted", offer.status === "declined" && "faded")}>
      <header className="quote-offer-top">
        <div>
          <h3>
            {offer.farm?.name ?? "A farm"}
            {offer.farm?.verified && <BadgeCheck className="quote-verified" aria-label="Verified farm" />}
          </h3>
          <small>
            {offer.farm?.rating.count ? (
              <>
                <Star className="quote-star" /> {offer.farm.rating.average} ({offer.farm.rating.count})
              </>
            ) : (
              "No reviews yet"
            )}
            {offer.updated_at && ` · ${timeAgo(offer.updated_at)}`}
          </small>
        </div>
        <span className="admin-pills">
          {cheapest && <Pill tone="good">Lowest total</Pill>}
          {offer.status !== "pending" && <Pill tone={status.tone}>{status.label}</Pill>}
        </span>
      </header>

      <dl className="summary-lines">
        <div>
          <dt>
            {formatQuantity(offer.quantity, offer.listing.unit)} of {offer.listing.title}
            {offer.listing.label && ` (${offer.listing.label})`}
          </dt>
          <dd>
            {money(offer.unit_price)} / {offer.listing.unit.name.toLowerCase()}
          </dd>
        </div>
        <div>
          <dt>{delivery ? "Delivery" : "You collect from the farm"}</dt>
          <dd>{delivery ? money(offer.delivery_fee) : "Free"}</dd>
        </div>
        <div className="summary-total">
          <dt>Each order</dt>
          <dd>{money(offer.total)}</dd>
        </div>
      </dl>
      {offer.message && <p className="admin-quote">“{offer.message}”</p>}
      {offer.valid_until && <small className="field-hint">Price holds until {longDate(offer.valid_until)}</small>}

      <div className="quote-offer-actions">
        {canDecide && (
          <>
            <Button onClick={onAccept}>Accept · {money(offer.total)}</Button>
            <Button variant="ghost" onClick={onDecline}>
              Decline
            </Button>
          </>
        )}
        {offer.can_order_again && (
          <Button onClick={onOrderAgain}>
            <Repeat /> Order again · {money(offer.total)}
          </Button>
        )}
        {offer.farm && (
          <Button variant="secondary" asChild>
            <Link to="/messages/chat" search={{ farm: offer.farm.id, product: offer.listing.product_id, name: offer.farm.name }}>
              <MessageCircle /> Message the farm
            </Link>
          </Button>
        )}
      </div>
    </article>
  );
}

/** Accepting an offer, or ordering again on an accepted one: both place an order for the farm to confirm. */
function OrderDialog({ request, offer, again, onClose }: { request: QuoteRequest; offer: QuoteOffer; again: boolean; onClose: () => void }) {
  const navigate = useNavigate();
  const { user } = useSession();
  const addresses = useAddresses();
  const { accept, orderAgain } = useQuoteActions();
  const action = again ? orderAgain : accept;
  const delivery = offer.fulfilment === "delivery";
  const [addressId, setAddressId] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [verifying, setVerifying] = useState(false);

  useEffect(() => {
    if (addressId === null && addresses.data?.length) setAddressId((addresses.data.find((address) => address.is_default) ?? addresses.data[0]).id);
  }, [addresses.data, addressId]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setProblem(null);
    if (!user?.phone_verified) {
      setVerifying(true);
      return;
    }
    if (delivery && !addressId) {
      setProblem("Add the address the farm should deliver to.");
      return;
    }
    try {
      const order = await action.mutateAsync({ offerId: offer.id, address_id: delivery ? addressId : null, note: note.trim() || undefined });
      toast.success(`Order ${order.number} placed`, { description: `We've told ${offer.farm?.name ?? "the farm"}. You pay once they confirm.` });
      onClose();
      navigate({ to: "/orders/$orderId", params: { orderId: order.id }, search: { placed: true } });
    } catch (error) {
      setProblem(errorMessage(error));
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>{again ? "Order again" : `Accept ${offer.farm ? `${offer.farm.name}'s` : "this"} offer`}</DialogTitle>
          <DialogDescription>
            {formatQuantity(offer.quantity, offer.listing.unit)} of {offer.listing.title} for {formatMoney(offer.total, offer.currency)}
            {delivery ? ", delivered." : ", collected from the farm."}{" "}
            {again ? "" : request.status === "open" ? "Other offers are declined. " : ""}
            The farm confirms first; then you pay by mobile money.
          </DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={submit}>
          {!user?.phone_verified && (
            <div className="shop-notice">
              <Phone />
              <p>Confirm your phone number first. The farm uses it to reach you about the order.</p>
              <Button type="button" variant="secondary" size="sm" onClick={() => setVerifying(true)}>
                Confirm number
              </Button>
            </div>
          )}
          {delivery && (
            <div className="form-field">
              <span className="field-label">Deliver to</span>
              {addresses.data?.length ? (
                <div className="address-options" role="radiogroup" aria-label="Delivery address">
                  {addresses.data.map((address) => (
                    <button
                      key={address.id}
                      type="button"
                      role="radio"
                      aria-checked={addressId === address.id}
                      className={cn("location-option", addressId === address.id && "selected")}
                      onClick={() => setAddressId(address.id)}
                    >
                      <MapPin />
                      <span>
                        <strong>{address.label ?? address.recipient_name}</strong>
                        <small>
                          {address.address_line}, {address.district} · {address.recipient_name}, {formatPhone(address.phone)}
                        </small>
                      </span>
                    </button>
                  ))}
                </div>
              ) : (
                <p className="field-hint">Add the address the farm should deliver to.</p>
              )}
              <Button type="button" variant="ghost" className="add-address" onClick={() => setAdding(true)}>
                <Plus /> Add a new address
              </Button>
            </div>
          )}
          <div className="form-field">
            <label className="field-label" htmlFor="quote-order-note">
              Note for the farm (optional)
            </label>
            <textarea id="quote-order-note" className="admin-textarea" maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} placeholder="e.g. Deliver to the back door before 8am" />
          </div>
          {problem && <p className="field-error">{problem}</p>}
          <Button type="submit" className="form-submit" disabled={action.isPending}>
            {action.isPending && <LoaderCircle className="animate-spin" />}
            Place order · {formatMoney(offer.total, offer.currency)}
          </Button>
        </form>
        <VerifyPhoneDialog open={verifying} onOpenChange={setVerifying} />
        <AddressDialog open={adding} onOpenChange={setAdding} onSaved={(address) => setAddressId(address.id)} />
      </DialogContent>
    </Dialog>
  );
}
