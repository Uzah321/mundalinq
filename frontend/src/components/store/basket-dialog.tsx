import { ShoppingBag, Sprout, TriangleAlert, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { errorMessage } from "@/lib/api";
import { useSession } from "@/lib/auth";
import { useBasket } from "@/lib/basket";
import { formatMoney } from "@/lib/format";
import { QuantityControl } from "./product-card";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSignIn: () => void;
  onOpenProduct: (id: string) => void;
};

export function BasketDialog({ open, onOpenChange, onSignIn, onOpenProduct }: Props) {
  const { signedIn } = useSession();
  const basket = useBasket();
  const cart = basket.cart.data;
  const farmCount = cart?.sellers.length ?? 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="store-dialog basket-dialog">
        <DialogHeader>
          <DialogTitle>Your basket</DialogTitle>
          <DialogDescription>
            {basket.count > 0
              ? `${basket.count} ${basket.count === 1 ? "item" : "items"} from ${farmCount} ${farmCount === 1 ? "farm" : "farms"}`
              : "Fresh produce from local farms, priced by the farmers."}
          </DialogDescription>
        </DialogHeader>

        {!signedIn ? (
          <div className="empty-state">
            <ShoppingBag />
            <h3>Sign in to start your basket</h3>
            <p>Your basket is saved to your account, so it follows you from this browser to the app.</p>
            <Button onClick={onSignIn}>Sign in or create an account</Button>
          </div>
        ) : basket.cart.isPending ? (
          <div className="basket-loading" aria-label="Loading your basket">
            {[0, 1].map((row) => (
              <div key={row} className="basket-row">
                <span className="skeleton basket-thumb" />
                <div>
                  <span className="skeleton skeleton-line" />
                  <span className="skeleton skeleton-line short" />
                </div>
              </div>
            ))}
          </div>
        ) : basket.cart.error ? (
          <div className="empty-state">
            <TriangleAlert />
            <h3>We couldn't load your basket</h3>
            <p>{errorMessage(basket.cart.error)}</p>
            <Button onClick={() => basket.cart.refetch()}>Try again</Button>
          </div>
        ) : !cart || basket.count === 0 ? (
          <div className="empty-state">
            <ShoppingBag />
            <h3>Your basket is empty</h3>
            <p>Fresh produce from local farms is a tap away.</p>
            <Button onClick={() => onOpenChange(false)}>Start shopping</Button>
          </div>
        ) : (
          <>
            {cart.sellers.map((seller) => (
              <section key={seller.farm.id} className="basket-seller" aria-label={seller.farm.name}>
                <h3>
                  <Sprout /> {seller.farm.name}
                </h3>
                {seller.items.map((line) => (
                  <div key={line.cart_item_id} className="basket-row">
                    <button type="button" className="basket-thumb" onClick={() => onOpenProduct(line.product_id)} aria-label={`View ${line.title}`}>
                      {line.thumb_url ? <img src={line.thumb_url} alt="" /> : <Sprout />}
                    </button>
                    <div>
                      <strong>{line.title}</strong>
                      <small>
                        {line.label ? `${line.label} · ` : ""}
                        {formatMoney(line.unit_price, cart.currency)} / {line.unit.code}
                      </small>
                      {line.problem && <small className="basket-problem">{line.problem.message}</small>}
                    </div>
                    <div className="basket-line-end">
                      <strong>{formatMoney(line.line_total, cart.currency)}</strong>
                      <QuantityControl
                        label={line.title}
                        quantity={line.quantity}
                        disabled={basket.busy}
                        onStep={(direction) => basket.step(line, direction)}
                      />
                    </div>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="basket-remove"
                      disabled={basket.busy}
                      onClick={() => basket.remove(line)}
                      aria-label={`Remove ${line.title}`}
                    >
                      <X />
                    </Button>
                  </div>
                ))}
              </section>
            ))}

            <div className="basket-total">
              <span>Subtotal</span>
              <strong>{formatMoney(cart.subtotal, cart.currency)}</strong>
            </div>
            <p className="prototype-note">
              Delivery fees depend on each farm and your address, so they're added at checkout. Checkout is coming to
              the web next; your basket is saved to your account until then.
            </p>
            <Button
              variant="link"
              className="basket-clear"
              disabled={basket.busy}
              onClick={() => basket.actions.clear.mutate()}
            >
              Empty basket
            </Button>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
