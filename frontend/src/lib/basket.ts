import { useMemo } from "react";
import { toast } from "sonner";
import { errorMessage } from "./api";
import { fromMilli, toMilli } from "./format";
import { useCart, useCartActions, useCartBusy } from "./queries";
import type { CartLine } from "./types";

/** The server-side basket plus the everyday changes made from cards and the basket sheet. */
export function useBasket() {
  const cart = useCart();
  const actions = useCartActions();
  const busy = useCartBusy();

  const lines = useMemo(() => cart.data?.sellers.flatMap((seller) => seller.items) ?? [], [cart.data]);
  const linesByProduct = useMemo(() => {
    const byProduct = new Map<string, CartLine[]>();
    for (const line of lines) byProduct.set(line.product_id, [...(byProduct.get(line.product_id) ?? []), line]);
    return byProduct;
  }, [lines]);

  function remove(line: CartLine) {
    actions.remove.mutate(line.cart_item_id, {
      onSuccess: () =>
        toast(`Removed ${line.title}`, {
          action: {
            label: "Undo",
            onClick: () =>
              actions.add.mutate(
                { variantId: line.variant_id, quantity: line.quantity },
                { onError: (error) => toast.error(errorMessage(error)) },
              ),
          },
        }),
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  /** One unit more or less; stepping below one unit removes the line. */
  function step(line: CartLine, direction: 1 | -1) {
    const next = (toMilli(line.quantity) ?? 0) + direction * 1000;
    if (next <= 0) return remove(line);

    actions.update.mutate(
      { itemId: line.cart_item_id, quantity: fromMilli(next) },
      { onError: (error) => toast.error(errorMessage(error)) },
    );
  }

  return { cart, actions, busy, lines, linesByProduct, count: lines.length, step, remove };
}
