import { Link } from "@tanstack/react-router";
import { Bell, ChevronDown, MapPin, MessageCircle, ShoppingBag, Sprout } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useSession } from "@/lib/auth";
import { useConversations, useUnreadNotifications } from "@/lib/shop";

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
        <Wordmark />

        <Button variant="ghost" className="delivery-location" onClick={onChooseArea}>
          <MapPin />
          <span>
            <small>SHOPPING NEAR</small>
            <strong>{areaLabel}</strong>
          </span>
          <ChevronDown />
        </Button>

        <div className="header-actions">
          <InboxLinks />
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

export function Wordmark() {
  return (
    <Link to="/" className="wordmark" aria-label="MundaLinq home">
      <span className="brand-icon">
        <Sprout />
      </span>
      mundalinq<span className="brand-dot">.</span>
    </Link>
  );
}

/** Messages and notifications, each with how many are unread; only for signed-in people. */
export function InboxLinks() {
  const { signedIn } = useSession();
  const unread = useUnreadNotifications().data ?? 0;
  const unreadMessages = (useConversations().data ?? []).reduce((sum, conversation) => sum + (conversation.unread_count ?? 0), 0);
  if (!signedIn) return null;

  return (
    <>
      <Button variant="secondary" size="icon" className="header-basket" asChild>
        <Link to="/messages" aria-label={unreadMessages ? `Messages, ${unreadMessages} unread` : "Messages"}>
          <MessageCircle />
          {unreadMessages > 0 && <span className="count-badge">{unreadMessages}</span>}
        </Link>
      </Button>
      <Button variant="secondary" size="icon" className="header-basket" asChild>
        <Link to="/notifications" aria-label={unread ? `Notifications, ${unread} unread` : "Notifications"}>
          <Bell />
          {unread > 0 && <span className="count-badge">{unread}</span>}
        </Link>
      </Button>
    </>
  );
}
