import { Heart, Leaf, LoaderCircle, Minus, Plus, Sprout, Trash } from "lucide-react";
import { Button } from "@/components/ui/button";
import { daysSince, formatMoney, toMilli } from "@/lib/format";
import type { ProductSummary } from "@/lib/queries";
import type { CartLine } from "@/lib/types";
import { cn } from "@/lib/utils";
import { ProductPlaceholder } from "./category-visual";

type Props = {
  product: ProductSummary;
  favorite: boolean;
  /** Set when the basket holds exactly one selling option of this product. */
  basketLine?: CartLine;
  adding: boolean;
  basketBusy: boolean;
  onOpen: () => void;
  onToggleFavorite: () => void;
  onAdd: () => void;
  onStep: (direction: 1 | -1) => void;
};

export function ProductCard({ product, favorite, basketLine, adding, basketBusy, onOpen, onToggleFavorite, onAdd, onStep }: Props) {
  const badge = productBadge(product);

  return (
    <article className="product-card">
      <div className="product-visual">
        <Button variant="ghost" className="product-image-button" onClick={onOpen} aria-label={`View ${product.title}`}>
          {product.image ? (
            <img src={product.image} alt="" loading="lazy" />
          ) : (
            <ProductPlaceholder slug={product.categorySlug} />
          )}
        </Button>
        {badge && (
          <span className={cn("product-badge", badge.warm && "warm-badge")}>
            {!badge.warm && <Leaf />}
            {badge.text}
          </span>
        )}
        <Button
          variant="ghost"
          size="icon"
          className={cn("favorite-button", favorite && "is-favorite")}
          aria-pressed={favorite}
          aria-label={favorite ? `Remove ${product.title} from favourites` : `Save ${product.title} to favourites`}
          onClick={onToggleFavorite}
        >
          <Heart />
        </Button>
      </div>

      <div className="product-info">
        <span className="farm-name">
          <Sprout />
          {product.farmName}
        </span>
        <Button variant="link" className="product-name" onClick={onOpen}>
          {product.title}
        </Button>
        <span className="product-unit">
          {product.unit && `per ${product.unit} · `}
          {product.distanceKm ? `${product.distanceKm} km away` : product.district}
        </span>
        <div className="product-bottom">
          <strong>{product.price ? formatMoney(product.price, product.currency) : "—"}</strong>
          {basketLine ? (
            <QuantityControl
              label={product.title}
              quantity={basketLine.quantity}
              disabled={basketBusy}
              onStep={onStep}
            />
          ) : (
            <Button
              size="icon"
              className="add-button"
              onClick={onAdd}
              disabled={adding}
              aria-label={`Add ${product.title} to basket`}
            >
              {adding ? <LoaderCircle className="animate-spin" /> : <Plus />}
            </Button>
          )}
        </div>
      </div>
    </article>
  );
}

export function QuantityControl({
  label,
  quantity,
  disabled,
  onStep,
}: {
  label: string;
  quantity: string;
  disabled?: boolean;
  onStep: (direction: 1 | -1) => void;
}) {
  const removes = (toMilli(quantity) ?? 0) <= 1000;

  return (
    <div className="quantity-control" role="group" aria-label={`${label} in basket`}>
      <Button
        variant="ghost"
        onClick={() => onStep(-1)}
        disabled={disabled}
        aria-label={removes ? `Remove ${label}` : `One less ${label}`}
      >
        {removes ? <Trash /> : <Minus />}
      </Button>
      <span aria-live="polite">{quantity}</span>
      <Button variant="ghost" onClick={() => onStep(1)} disabled={disabled} aria-label={`One more ${label}`}>
        <Plus />
      </Button>
    </div>
  );
}

export function ProductCardSkeleton() {
  return (
    <div className="product-card" aria-hidden>
      <div className="product-visual skeleton" />
      <div className="product-info">
        <span className="skeleton skeleton-line short" />
        <span className="skeleton skeleton-line" />
        <span className="skeleton skeleton-line short" />
        <div className="product-bottom">
          <span className="skeleton skeleton-line price" />
        </div>
      </div>
    </div>
  );
}

function productBadge(product: ProductSummary): { text: string; warm: boolean } | null {
  if (product.featured) return { text: "Featured", warm: true };
  if (product.harvestedOn && daysSince(product.harvestedOn) <= 2) return { text: "Fresh pick", warm: true };
  if (product.grade === "A") return { text: "Grade A", warm: false };
  return null;
}
