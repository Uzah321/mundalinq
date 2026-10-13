import { Link } from "@tanstack/react-router";
import { ChevronDown, MapPin, ShoppingBag, Sprout } from "lucide-react";
import { Button } from "@/components/ui/button";

type Props = {
  areaLabel: string;
  basketCount: number;
  onChooseArea: () => void;
  onOpenBasket: () => void;
};

export function AppHeader({ areaLabel, basketCount, onChooseArea, onOpenBasket }: Props) {
  return (
    <header className="app-header">
      <div className="header-inner">
        <Link to="/" className="wordmark" aria-label="MundaLinq home">
          <span className="brand-icon">
            <Sprout />
          </span>
          mundalinq<span className="brand-dot">.</span>
        </Link>

        <Button variant="ghost" className="delivery-location" onClick={onChooseArea}>
          <MapPin />
          <span>
            <small>SHOPPING NEAR</small>
            <strong>{areaLabel}</strong>
          </span>
          <ChevronDown />
        </Button>

        <div className="header-actions">
          <Button
            variant="secondary"
            size="icon"
            className="header-basket"
            onClick={onOpenBasket}
            aria-label={basketCount ? `Basket, ${basketCount} items` : "Basket"}
          >
            <ShoppingBag />
            {basketCount > 0 && <span className="count-badge">{basketCount}</span>}
          </Button>
        </div>
      </div>
    </header>
  );
}
