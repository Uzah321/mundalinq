import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, Receipt } from "lucide-react";
import { Pill, QueueList, rowsOf } from "@/components/admin/ui";
import { ShopPage } from "@/components/store/shop-page";
import { formatMoney, timeAgo } from "@/lib/format";
import { buyerOrderStatus } from "@/lib/order-status";
import { useOrders } from "@/lib/shop";

export const Route = createFileRoute("/orders/")({
  component: Orders,
});

function Orders() {
  return (
    <ShopPage title="My orders" signInReason="Sign in to see your orders.">
      <OrderList />
    </ShopPage>
  );
}

function OrderList() {
  const list = useOrders();
  const orders = rowsOf(list.data);

  return (
    <QueueList query={list} count={orders.length} empty={{ title: "No orders yet", text: "Orders you place show up here, so you can follow, pay for and collect them." }}>
      {orders.map((order) => {
        const status = buyerOrderStatus(order);
        return (
          <li key={order.id}>
            <Link to="/orders/$orderId" params={{ orderId: order.id }} className="admin-row">
              <span className="admin-row-icon">
                <Receipt />
              </span>
              <span className="admin-row-main">
                <strong>
                  {order.number} · {formatMoney(order.total, order.currency)}
                </strong>
                <small>{order.seller_orders?.map((part) => part.farm.name).join(", ")}</small>
                <span className="admin-pills">
                  <Pill tone={status.tone}>{status.label}</Pill>
                  {order.created_at && <small className="admin-when">Placed {timeAgo(order.created_at)}</small>}
                </span>
              </span>
              <ChevronRight className="admin-row-arrow" />
            </Link>
          </li>
        );
      })}
    </QueueList>
  );
}
