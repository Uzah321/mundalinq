import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { BadgeCheck, CalendarDays, Heart, LoaderCircle, MapPin, MessageCircle, Minus, Plus, Share2, Sprout, Store, Truck } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { ApiError, errorMessage, session } from "@/lib/api";
import { useBasket } from "@/lib/basket";
import {
  formatMoney,
  formatQuantity,
  fromMilli,
  fromMinor,
  harvestedLabel,
  initialQuantity,
  lineTotalMinor,
  maxOrderMilli,
  toMilli,
} from "@/lib/format";
import { useFavorites } from "@/lib/preferences";
import { productQuery, useUnits } from "@/lib/queries";
import type { ProductDetail, Variant } from "@/lib/types";
import { shareFarm, shareListing } from "@/lib/share";
import { cn } from "@/lib/utils";
import { ProductPlaceholder } from "./category-visual";

type Props = {
  productId: string | undefined;
  onClose: () => void;
  onRequireAccount: (reason: string, then: () => void) => void;
  onOpenBasket: () => void;
};

export function ProductDialog({ productId, onClose, onRequireAccount, onOpenBasket }: Props) {
  return (
    <Dialog open={productId !== undefined} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="store-dialog product-dialog">
        {productId && (
          <ProductDetailView
            key={productId}
            id={productId}
            onClose={onClose}
            onRequireAccount={onRequireAccount}
            onOpenBasket={onOpenBasket}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function ProductDetailView({ id, onClose, onRequireAccount, onOpenBasket }: Omit<Props, "productId"> & { id: string }) {
  const { data: product, isPending, error, refetch } = useQuery(productQuery(id));

  if (isPending) {
    return (
      <div className="detail-loading">
        <DialogTitle className="sr-only">Loading listing</DialogTitle>
        <div className="detail-image skeleton" />
        <span className="skeleton skeleton-line short" />
        <span className="skeleton skeleton-line" />
        <span className="skeleton skeleton-line" />
      </div>
    );
  }

  if (error) {
    const gone = error instanceof ApiError && error.status === 404;
    return (
      <div className="empty-state">
        <Sprout />
        <DialogTitle asChild>
          <h3>{gone ? "This listing has been picked" : "We couldn't load this listing"}</h3>
        </DialogTitle>
        <DialogDescription>
          {gone ? "It sold out or the farm took it down. There's plenty more in the market." : errorMessage(error)}
        </DialogDescription>
        <div className="empty-actions">
          {!gone && <Button onClick={() => refetch()}>Try again</Button>}
          <Button variant="outline" onClick={onClose}>
            Back to the market
          </Button>
        </div>
      </div>
    );
  }

  return <ProductDetailBody product={product} onClose={onClose} onRequireAccount={onRequireAccount} onOpenBasket={onOpenBasket} />;
}

function ProductDetailBody({
  product,
  onClose,
  onRequireAccount,
  onOpenBasket,
}: { product: ProductDetail } & Omit<Props, "productId">) {
  const basket = useBasket();
  const favorites = useFavorites();
  const { data: units } = useUnits();

  const [imageIndex, setImageIndex] = useState(0);
  const [variantId, setVariantId] = useState(
    () => product.variants.find((variant) => basket.lines.some((line) => line.variant_id === variant.id))?.id ?? product.variants[0]?.id,
  );
  const variant = product.variants.find((option) => option.id === variantId) ?? product.variants[0];
  const inBasket = variant ? basket.lines.find((line) => line.variant_id === variant.id) : undefined;
  const [typedQuantity, setTypedQuantity] = useState<string | null>(null);

  const images = product.images ?? [];
  const harvested = harvestedLabel(product.last_harvested_on);
  const favorite = favorites.has(product.id);

  return (
    <>
      <div className="detail-media">
        {images.length > 0 ? (
          <img className="detail-image" src={images[imageIndex]?.url} alt={product.title} />
        ) : (
          <ProductPlaceholder slug={product.category.slug} className="detail-image product-placeholder" />
        )}
        <Button
          variant="ghost"
          size="icon"
          className={cn("favorite-button", favorite && "is-favorite")}
          aria-pressed={favorite}
          aria-label={favorite ? "Remove from favourites" : "Save to favourites"}
          onClick={() => favorites.toggle(product.id)}
        >
          <Heart />
        </Button>
      </div>

      {images.length > 1 && (
        <div className="detail-thumbs">
          {images.map((image, index) => (
            <button
              key={image.id}
              type="button"
              className={cn(index === imageIndex && "selected")}
              onClick={() => setImageIndex(index)}
              aria-label={`Photo ${index + 1} of ${images.length}`}
            >
              <img src={image.thumb_url} alt="" />
            </button>
          ))}
        </div>
      )}

      <DialogHeader className="detail-header">
        <span className="farm-name">
          <Sprout />
          {product.farm.name}
          {product.farm.verified && (
            <span className="verified">
              <BadgeCheck /> Verified farm
            </span>
          )}
        </span>
        <DialogTitle>{product.title}</DialogTitle>
        <DialogDescription className="detail-meta">
          <span>
            <MapPin />
            {product.location.district}, {product.location.province}
          </span>
          {harvested && (
            <span>
              <CalendarDays />
              {harvested}
            </span>
          )}
        </DialogDescription>
        <div className="detail-links">
          <Link to="/messages/chat" search={{ farm: product.farm.id, product: product.id, name: product.farm.name }} className="ask-farm">
            <MessageCircle /> Ask {product.farm.name} a question
          </Link>
          <a href={shareListing(product)} target="_blank" rel="noreferrer" className="ask-farm whatsapp">
            <Share2 /> Share on WhatsApp
          </a>
          <a href={shareFarm(product.farm, product.location.district)} target="_blank" rel="noreferrer" className="ask-farm whatsapp">
            <Share2 /> Share the farm
          </a>
        </div>
      </DialogHeader>

      <div className="detail-tags">
        {product.featured && <span className="featured-tag">Featured</span>}
        {product.quality_grade && <span>Grade {product.quality_grade}</span>}
        {product.offers_pickup && (
          <span>
            <Store /> Farm pickup
          </span>
        )}
        {product.offers_delivery && (
          <span>
            <Truck /> Delivers within {product.delivery_radius_km} km
          </span>
        )}
      </div>

      {product.description && <p className="detail-description">{product.description}</p>}

      {variant ? (
        <>
          {product.variants.length > 1 && (
            <fieldset className="variant-options">
              <legend>Choose how to buy</legend>
              {product.variants.map((option) => (
                <label key={option.id} className={cn("variant-option", option.id === variant.id && "selected")}>
                  <input
                    type="radio"
                    name="variant"
                    value={option.id}
                    checked={option.id === variant.id}
                    onChange={() => {
                      setVariantId(option.id);
                      setTypedQuantity(null);
                    }}
                  />
                  <span>
                    <strong>{variantName(option)}</strong>
                    {option.price_tiers.length > 0 && <small>{tierSummary(option, product.currency)}</small>}
                  </span>
                  <b>
                    {formatMoney(option.price, product.currency)} / {option.unit.code}
                  </b>
                </label>
              ))}
            </fieldset>
          )}

          <QuantityPicker
            key={variant.id}
            product={product}
            variant={variant}
            allowsFraction={units?.get(variant.unit.code)?.allows_fraction}
            quantity={typedQuantity ?? inBasket?.quantity ?? initialQuantity(variant)}
            onQuantityChange={setTypedQuantity}
            inBasket={inBasket !== undefined}
            busy={basket.busy}
            onSubmit={(quantity) => {
              const add = () =>
                basket.actions.add.mutate(
                  { variantId: variant.id, quantity },
                  {
                    onSuccess: () => {
                      toast.success(`${formatQuantity(quantity, variant.unit)} of ${product.title} in your basket`, {
                        action: { label: "View basket", onClick: onOpenBasket },
                      });
                      onClose();
                    },
                    onError: (error) => toast.error(errorMessage(error)),
                  },
                );

              if (session.token()) add();
              else onRequireAccount(`Sign in to add ${product.title} to your basket.`, add);
            }}
          />
        </>
      ) : (
        <p className="prototype-note">This listing has no selling options right now.</p>
      )}
    </>
  );
}

type QuantityPickerProps = {
  product: ProductDetail;
  variant: Variant;
  /** Unknown until the unit rules load; the server checks again either way. */
  allowsFraction: boolean | undefined;
  quantity: string;
  onQuantityChange: (quantity: string) => void;
  inBasket: boolean;
  busy: boolean;
  onSubmit: (quantity: string) => void;
};

function QuantityPicker({ product, variant, allowsFraction, quantity, onQuantityChange, inBasket, busy, onSubmit }: QuantityPickerProps) {
  const milli = toMilli(quantity);
  const min = toMilli(variant.min_order_quantity) ?? 1000;
  const max = maxOrderMilli(variant);
  const soldOut = max < min;
  const problem = quantityProblem(variant, milli, min, max, allowsFraction);
  const total = problem || milli === null ? null : fromMinor(lineTotalMinor(variant, quantity));
  const inputId = `quantity-${variant.id}`;

  return (
    <form
      className="detail-quantity"
      onSubmit={(event) => {
        event.preventDefault();
        if (!problem) onSubmit(quantity);
      }}
    >
      <Label htmlFor={inputId}>Quantity ({variant.unit.plural_name})</Label>
      <div className="quantity-field">
        <Button
          type="button"
          variant="outline"
          size="icon"
          aria-label="Less"
          disabled={soldOut || milli === null || milli <= min}
          onClick={() => onQuantityChange(fromMilli(Math.max(min, (milli ?? min) - 1000)))}
        >
          <Minus />
        </Button>
        <input
          id={inputId}
          value={quantity}
          inputMode={allowsFraction === false ? "numeric" : "decimal"}
          aria-invalid={problem !== null}
          aria-describedby={`${inputId}-hint`}
          onChange={(event) => onQuantityChange(event.target.value.replace(",", "."))}
        />
        <Button
          type="button"
          variant="outline"
          size="icon"
          aria-label="More"
          disabled={soldOut || milli === null || milli >= max}
          onClick={() => onQuantityChange(fromMilli(Math.min(max, (milli ?? 0) + 1000)))}
        >
          <Plus />
        </Button>
      </div>
      <small id={`${inputId}-hint`} className={problem ? "field-error" : "field-hint"}>
        {problem ?? `${formatQuantity(fromMilli(max), variant.unit)} available`}
      </small>

      <Button type="submit" className="detail-add" disabled={problem !== null || busy}>
        {busy && <LoaderCircle className="animate-spin" />}
        {soldOut ? "Sold out" : inBasket ? "Update basket" : "Add to basket"}
        {total && ` · ${formatMoney(total, product.currency)}`}
      </Button>
    </form>
  );
}

function quantityProblem(variant: Variant, milli: number | null, min: number, max: number, allowsFraction: boolean | undefined): string | null {
  if (max < min) return "Sold out for now. Check back after the next harvest.";
  if (milli === null || milli <= 0) return "Enter a quantity.";
  if (allowsFraction === false && milli % 1000 !== 0) return `Order whole ${variant.unit.plural_name}.`;
  if (milli < min) return `The minimum order is ${formatQuantity(variant.min_order_quantity, variant.unit)}.`;
  if (milli > max) return `Only ${formatQuantity(fromMilli(max), variant.unit)} available.`;
  return null;
}

function variantName(variant: Variant): string {
  if (variant.label) return variant.label;
  return variant.unit.name.charAt(0).toUpperCase() + variant.unit.name.slice(1);
}

function tierSummary(variant: Variant, currency: string): string {
  return variant.price_tiers
    .map((tier) => `${tier.min_quantity}+ ${variant.unit.plural_name}: ${formatMoney(tier.price, currency)} each`)
    .join(" · ");
}
