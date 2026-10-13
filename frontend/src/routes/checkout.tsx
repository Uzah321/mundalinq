import { useEffect, useMemo, useState } from "react";
import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { Clock, LoaderCircle, MapPin, Phone, Plus, ShoppingBag, Store, Truck, TriangleAlert } from "lucide-react";
import { toast } from "sonner";
import { VerifyPhoneDialog } from "@/components/store/account-dialog";
import { AddressDialog } from "@/components/store/address-dialog";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { ApiError, errorMessage } from "@/lib/api";
import { useSession } from "@/lib/auth";
import { formatMoney, formatPhone, formatQuantity } from "@/lib/format";
import { useAddresses } from "@/lib/queries";
import { usePlaceOrder, useQuote, type Fulfilment } from "@/lib/shop";
import type { Cart, CartSeller } from "@/lib/types";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/checkout")({
  component: Checkout,
});

function Checkout() {
  return (
    <ShopPage title="Checkout" back={{ to: "/", label: "Keep shopping" }} signInReason="Sign in to check out your basket.">
      <CheckoutForm />
    </ShopPage>
  );
}

function CheckoutForm() {
  const { user } = useSession();
  const navigate = useNavigate();
  const addresses = useAddresses();
  const place = usePlaceOrder();
  const [fulfilment, setFulfilment] = useState<Record<string, Fulfilment>>({});
  const [addressId, setAddressId] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [verifying, setVerifying] = useState(false);
  const [adding, setAdding] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  // Start with the default (or only) address.
  useEffect(() => {
    if (addressId === null && addresses.data?.length) setAddressId((addresses.data.find((address) => address.is_default) ?? addresses.data[0]).id);
  }, [addresses.data, addressId]);

  const choice = useMemo(() => ({ fulfilment, addressId }), [fulfilment, addressId]);
  const quote = useQuote(choice, !addresses.isPending);
  const cart = quote.data;
  const methodFor = (seller: CartSeller) => fulfilment[seller.farm.id] ?? seller.fulfilment_method;
  const needsAddress = cart?.sellers.some((seller) => methodFor(seller) === "delivery") ?? false;

  if (!cart) {
    return quote.isError ? (
      <div className="empty-state shop-card">
        <TriangleAlert />
        <h3>We couldn't price your order</h3>
        <p>{errorMessage(quote.error)}</p>
        <Button onClick={() => quote.refetch()}>Try again</Button>
      </div>
    ) : (
      <div className="shop-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }

  if (cart.sellers.length === 0) {
    return (
      <div className="empty-state shop-card">
        <ShoppingBag />
        <h3>Your basket is empty</h3>
        <p>Add something from the market first.</p>
        <Button asChild>
          <Link to="/">Go to the market</Link>
        </Button>
      </div>
    );
  }

  async function placeOrder(current: Cart) {
    setProblem(null);
    if (!user?.phone_verified) {
      setVerifying(true);
      return;
    }
    if (needsAddress && !addressId) {
      setProblem("Add the address the farm should deliver to.");
      return;
    }
    if (!current.can_place_order) {
      const first = current.problems[0] ?? current.sellers.flatMap((seller) => seller.problems)[0];
      setProblem(first?.message ?? "Sort out the problems shown above, then place your order.");
      return;
    }
    try {
      const order = await place.mutateAsync({ choice, expectedTotal: current.total, note });
      toast.success(`Order ${order.number} placed`, { description: "We've told the farm. You pay once they confirm." });
      navigate({ to: "/orders/$orderId", params: { orderId: order.id }, search: { placed: true } });
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        await quote.refetch();
        setProblem("Prices or delivery charges changed. Check the new total and place your order again.");
      } else {
        setProblem(errorMessage(error));
        if (error instanceof ApiError && error.status === 422) quote.refetch();
      }
    }
  }

  return (
    <div className="checkout-layout">
      <div className="checkout-main">
        {!user?.phone_verified && (
          <div className="shop-notice">
            <Phone />
            <p>Confirm your phone number to place orders. Farms use it to reach you about your order.</p>
            <Button variant="secondary" size="sm" onClick={() => setVerifying(true)}>
              Confirm number
            </Button>
          </div>
        )}

        {cart.sellers.map((seller) => (
          <SellerCard
            key={seller.farm.id}
            seller={seller}
            currency={cart.currency}
            method={methodFor(seller)}
            onChoose={(method) => setFulfilment({ ...fulfilment, [seller.farm.id]: method })}
          />
        ))}

        {needsAddress && (
          <section className="shop-card">
            <h2>Deliver to</h2>
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
            <Button variant="ghost" className="add-address" onClick={() => setAdding(true)}>
              <Plus /> Add a new address
            </Button>
          </section>
        )}

        <section className="shop-card">
          <label className="field-label" htmlFor="order-note">
            Note for the farm (optional)
          </label>
          <textarea
            id="order-note"
            className="admin-textarea"
            maxLength={500}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="e.g. Please call when you arrive"
          />
        </section>
      </div>

      <aside className="checkout-summary shop-card">
        <h2>Your order</h2>
        <dl className="summary-lines">
          <div>
            <dt>Produce</dt>
            <dd>{formatMoney(cart.subtotal, cart.currency)}</dd>
          </div>
          <div>
            <dt>Delivery</dt>
            <dd>{formatMoney(cart.delivery_fee, cart.currency)}</dd>
          </div>
          <div className="summary-total">
            <dt>Total</dt>
            <dd>{formatMoney(cart.total, cart.currency)}</dd>
          </div>
        </dl>
        {cart.problems.map((item) => (
          <p key={item.code + item.message} className="field-error">
            {item.message}
          </p>
        ))}
        {problem && <p className="field-error checkout-problem">{problem}</p>}
        <Button className="form-submit" onClick={() => placeOrder(cart)} disabled={place.isPending}>
          {(place.isPending || quote.isFetching) && <LoaderCircle className="animate-spin" />}
          Place order · {formatMoney(cart.total, cart.currency)}
        </Button>
        <p className="checkout-next">
          <Clock /> Nothing is charged yet. The farm confirms they have everything, usually within a few hours. Then you pay by mobile money.
        </p>
      </aside>

      <VerifyPhoneDialog open={verifying} onOpenChange={setVerifying} />
      <AddressDialog open={adding} onOpenChange={setAdding} onSaved={(address) => setAddressId(address.id)} />
    </div>
  );
}

function SellerCard({ seller, currency, method, onChoose }: { seller: CartSeller; currency: string; method: Fulfilment | null; onChoose: (method: Fulfilment) => void }) {
  return (
    <section className="shop-card">
      <h2 className="seller-name">
        <Store /> {seller.farm.name}
      </h2>
      <ul className="checkout-lines">
        {seller.items.map((line) => (
          <li key={line.cart_item_id}>
            <span>
              {formatQuantity(line.quantity, line.unit)} · {line.title}
              {line.problem && <small className="field-error">{line.problem.message}</small>}
            </span>
            <strong>{formatMoney(line.line_total, currency)}</strong>
          </li>
        ))}
      </ul>
      <div className="method-choices" role="radiogroup" aria-label={`How to get it from ${seller.farm.name}`}>
        {seller.available_methods.includes("pickup") && (
          <button type="button" role="radio" aria-checked={method === "pickup"} className={cn("method-choice", method === "pickup" && "selected")} onClick={() => onChoose("pickup")}>
            <Store />
            <strong>Collect at the farm</strong>
            <small>Free</small>
          </button>
        )}
        {seller.available_methods.includes("delivery") && (
          <button type="button" role="radio" aria-checked={method === "delivery"} className={cn("method-choice", method === "delivery" && "selected")} onClick={() => onChoose("delivery")}>
            <Truck />
            <strong>Delivery</strong>
            <small>{method === "delivery" ? formatMoney(seller.delivery_fee, currency) : "Fee shown when chosen"}</small>
          </button>
        )}
      </div>
      {seller.problems.map((item) => (
        <p key={item.code + item.message} className="field-error">
          {item.message}
        </p>
      ))}
    </section>
  );
}
