import { Heart, House, LayoutGrid, ShoppingBag, User, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export type NavKey = "home" | "browse" | "favorites" | "basket" | "account";

const ITEMS: { key: NavKey; label: string; icon: LucideIcon }[] = [
  { key: "home", label: "Home", icon: House },
  { key: "browse", label: "Browse", icon: LayoutGrid },
  { key: "favorites", label: "Favorites", icon: Heart },
  { key: "basket", label: "Basket", icon: ShoppingBag },
  { key: "account", label: "Account", icon: User },
];

type Props = {
  active: NavKey;
  basketCount: number;
  onSelect: (key: NavKey) => void;
};

export function BottomNav({ active, basketCount, onSelect }: Props) {
  return (
    <nav className="bottom-nav" aria-label="Main">
      <div>
        {ITEMS.map(({ key, label, icon: Icon }) => (
          <Button
            key={key}
            variant="ghost"
            className={cn("nav-item", active === key && "active")}
            aria-current={active === key ? "page" : undefined}
            onClick={() => onSelect(key)}
          >
            <span className="nav-icon">
              <Icon />
              {key === "basket" && basketCount > 0 && <span className="count-badge">{basketCount}</span>}
            </span>
            {label}
            {active === key && <i />}
          </Button>
        ))}
      </div>
    </nav>
  );
}
