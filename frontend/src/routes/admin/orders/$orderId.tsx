import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, MessageSquareWarning, Star } from "lucide-react";
import { Fact, Facts, ItemState, Pill, Section } from "@/components/admin/ui";
import { orderStatuses, paymentStatuses, refundStatuses, sellerOrderStatuses, statusOf, useAdminItem } from "@/lib/admin";
import { formatDateTime, formatMoney, formatPhone } from "@/lib/format";
import type { AdminOrder, SellerOrder } from "@/lib/types";

export const Route = createFileRoute("/admin/orders/$orderId")({
  component: OrderPage,
});

function OrderPage() {
  const { orderId } = Route.useParams();
  const query = useAdminItem<AdminOrder>(`/admin/orders/${orderId}`);
  const order = query.data;
  if (!order) return <ItemState query={query} missing="This order doesn't exist" />;

  const money = (amount: string) => formatMoney(amount, order.currency);
  const status = statusOf(orderStatuses, order.status);
  const partNumber = new Map(order.seller_orders?.map((part) => [part.id, part.number]));

  return (
    <>
      <Link to="/admin/orders" className="admin-back">
        <ArrowLeft /> All orders
      </Link>
      <div className="admin-heading">
        <Pill tone={status.tone}>{status.label}</Pill>
        <h1>
          {order.number} · {money(order.total)}
        </h1>
        <p>{order.created_at && `Placed ${formatDateTime(order.created_at)}`}</p>
      </div>
      {order.cancellation_reason && (
        <div className="admin-outcome rejected">
          <p>Cancelled: {order.cancellation_reason}</p>
        </div>
      )}

      <div className="admin-grid">
        <Section title="Buyer">
          <Facts>
            <Fact label="Name">{order.buyer.name}</Fact>
            <Fact label="Phone">
              <Link to="/admin/users" search={{ q: order.buyer.phone }}>
                {formatPhone(order.buyer.phone)}
              </Link>
            </Fact>
            {order.note && <Fact label="Note">{order.note}</Fact>}
          </Facts>
        </Section>

        <Section title="Totals">
          <Facts>
            <Fact label="Goods">{money(order.subtotal)}</Fact>
            <Fact label="Delivery">{money(order.delivery_fee)}</Fact>
            <Fact label="Total">{money(order.total)}</Fact>
            {order.paid_at && <Fact label="Paid">{formatDateTime(order.paid_at)}</Fact>}
            {order.completed_at && <Fact label="Completed">{formatDateTime(order.completed_at)}</Fact>}
          </Facts>
        </Section>

        {order.seller_orders?.map((part) => <FarmPart key={part.id} part={part} currency={order.currency} />)}

        <Section title="Payments" wide>
          {order.payments?.length ? (
            <ul className="admin-mini-list">
              {order.payments.map((payment) => {
                const paymentStatus = statusOf(paymentStatuses, payment.status);
                return (
                  <li key={payment.id}>
                    <strong>{money(payment.amount)}</strong>
                    <small>
                      {[payment.provider, payment.payer_phone && formatPhone(payment.payer_phone), payment.created_at && formatDateTime(payment.created_at)]
                        .filter(Boolean)
                        .join(" · ")}
                      {payment.failure_reason && ` · ${payment.failure_reason}`}
                      {Number(payment.refunded) > 0 && ` · ${money(payment.refunded)} refunded`}
                    </small>
                    <Pill tone={paymentStatus.tone}>{paymentStatus.label}</Pill>
                  </li>
                );
              })}
            </ul>
          ) : (
            <p className="admin-hint">No payment attempts yet.</p>
          )}
          {!!order.refunds?.length && (
            <>
              <h3 className="admin-minor-heading">Refunds</h3>
              <ul className="admin-mini-list">
                {order.refunds.map((refund) => {
                  const refundStatus = statusOf(refundStatuses, refund.status);
                  return (
                    <li key={refund.id}>
                      <strong>{money(refund.amount)}</strong>
                      <small>
                        {[refund.seller_order_id && partNumber.get(refund.seller_order_id), refund.provider_reference, refund.failure_reason].filter(Boolean).join(" · ")}
                      </small>
                      <Pill tone={refundStatus.tone}>{refundStatus.label}</Pill>
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </Section>

        {!!order.history?.length && (
          <Section title="What happened" wide>
            <ol className="admin-timeline">
              {order.history.map((change, index) => (
                <li key={index}>
                  <strong>
                    {change.seller_order_id ? `${partNumber.get(change.seller_order_id) ?? "Farm order"}: ` : "Order: "}
                    {statusOf(change.seller_order_id ? sellerOrderStatuses : orderStatuses, change.to).label}
                  </strong>
                  <small>{[change.at && formatDateTime(change.at), change.actor, change.note].filter(Boolean).join(" · ")}</small>
                </li>
              ))}
            </ol>
          </Section>
        )}
      </div>
    </>
  );
}

function FarmPart({ part, currency }: { part: SellerOrder; currency: string }) {
  const money = (amount: string) => formatMoney(amount, currency);
  const status = statusOf(sellerOrderStatuses, part.status);
  const address = part.delivery_address;

  return (
    <Section title={`${part.farm.name} · ${part.number}`} wide actions={<Pill tone={status.tone}>{status.label}</Pill>}>
      <table className="admin-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Ordered</th>
            <th>Price</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          {part.items?.map((item) => (
            <tr key={item.id}>
              <td>{item.label ? `${item.title} (${item.label})` : item.title}</td>
              <td>
                {item.quantity} {Number(item.quantity) === 1 ? item.unit.name : item.unit.plural_name}
                {item.confirmed_quantity !== null && item.confirmed_quantity !== item.quantity && (
                  <small className="admin-warn"> · farm confirmed {item.confirmed_quantity}</small>
                )}
              </td>
              <td>{money(item.unit_price)}</td>
              <td>{money(item.line_total)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <Facts>
        <Fact label="Handover">
          {part.fulfilment_method === "delivery"
            ? `Delivery${address ? ` to ${[address.address_line, address.district].filter(Boolean).join(", ")}` : ""}`
            : "Collection at the farm"}
        </Fact>
        <Fact label="Farm total">
          {money(part.total)}
          {Number(part.delivery_fee) > 0 && <small> (incl. {money(part.delivery_fee)} delivery)</small>}
        </Fact>
        {part.farm.contact_phone && (
          <Fact label="Farm phone">
            <a href={`tel:${part.farm.contact_phone}`}>{formatPhone(part.farm.contact_phone)}</a>
          </Fact>
        )}
        {part.rejection_reason && <Fact label="Farm's reason">{part.rejection_reason}</Fact>}
        {part.dispute && (
          <Fact label="Problem">
            <Link to="/admin/disputes/$disputeId" params={{ disputeId: part.dispute.id }}>
              <MessageSquareWarning className="inline-icon" /> Open the dispute ({part.dispute.status})
            </Link>
          </Fact>
        )}
        {part.review && (
          <Fact label="Review">
            <Star className="inline-icon" /> {part.review.rating} of 5
          </Fact>
        )}
      </Facts>
    </Section>
  );
}
