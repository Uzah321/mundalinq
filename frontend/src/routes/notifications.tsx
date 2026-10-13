import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { Bell, CheckCheck } from "lucide-react";
import { toast } from "sonner";
import { QueueList, rowsOf } from "@/components/admin/ui";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { timeAgo } from "@/lib/format";
import { useAlertSettings, useNotificationActions, useNotifications } from "@/lib/shop";
import type { AppNotification } from "@/lib/types";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/notifications")({
  component: Notifications,
});

function Notifications() {
  const { readAll } = useNotificationActions();
  return (
    <ShopPage
      title="Notifications"
      signInReason="Sign in to see your notifications."
      actions={
        <Button variant="ghost" size="sm" onClick={() => readAll.mutate()} disabled={readAll.isPending}>
          <CheckCheck /> Mark all as read
        </Button>
      }
    >
      <AlertChannel />
      <NotificationList />
    </ShopPage>
  );
}

/** SMS or WhatsApp for alerts sent outside the website and app. */
function AlertChannel() {
  const { settings, save } = useAlertSettings();
  const channel = settings.data?.channel;
  if (!channel) return null;

  return (
    <section className="shop-card alert-channel">
      <div>
        <strong>Also send my alerts by</strong>
        <small>Order updates and messages, as well as here. Choose which kinds in the app under SMS updates.</small>
      </div>
      <div className="choice-row" role="radiogroup" aria-label="Send alerts by">
        {(["sms", "whatsapp"] as const).map((option) => (
          <button
            key={option}
            type="button"
            role="radio"
            aria-checked={channel === option}
            className={cn("choice", channel === option && "selected")}
            disabled={save.isPending}
            onClick={() =>
              save.mutate(
                { channel: option },
                { onSuccess: () => toast.success(option === "whatsapp" ? "Alerts will come by WhatsApp" : "Alerts will come by SMS") },
              )
            }
          >
            {option === "sms" ? "SMS" : "WhatsApp"}
          </button>
        ))}
      </div>
    </section>
  );
}

function NotificationList() {
  const list = useNotifications();
  const { read } = useNotificationActions();
  const navigate = useNavigate();
  const items = rowsOf(list.data);

  function open(notification: AppNotification) {
    if (!notification.read_at) read.mutate(notification.id);
    const { order_id, conversation_id, seller_order_id, farm_id, quote_request_id } = notification.context;
    if (order_id) return navigate({ to: "/orders/$orderId", params: { orderId: order_id } });
    if (conversation_id) return navigate({ to: "/messages/chat", search: { conversation: conversation_id } });
    // Offers on the buyer's own request; a farm's alerts about requests also name the farm.
    if (quote_request_id && !farm_id) return navigate({ to: "/quotes/$requestId", params: { requestId: quote_request_id } });
    // Farm work (accepting orders, listings, payouts) happens in the app.
    if (seller_order_id || farm_id) toast.info("Open the MundaLinq app and switch to selling to manage your farm's orders.");
  }

  return (
    <QueueList query={list} count={items.length} empty={{ title: "Nothing new", text: "Order updates, messages from farms and account news show up here." }}>
      {items.map((notification) => (
        <li key={notification.id}>
          <button type="button" className={cn("admin-row notification-row", !notification.read_at && "unread")} onClick={() => open(notification)}>
            <span className="admin-row-icon">
              <Bell />
            </span>
            <span className="admin-row-main">
              <strong>{notification.title}</strong>
              {notification.body && <small>{notification.body}</small>}
              {notification.created_at && <small className="admin-when">{timeAgo(notification.created_at)}</small>}
            </span>
            {!notification.read_at && <span className="unread-dot" aria-label="Unread" />}
          </button>
        </li>
      ))}
    </QueueList>
  );
}
