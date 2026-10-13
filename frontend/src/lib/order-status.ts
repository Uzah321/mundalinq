import type { BuyerOrder, SellerOrder } from "./types";

// How an order and each farm's part of it read to the buyer: a short label and what happens next.

type Tone = "wait" | "good" | "bad" | "plain";

const time = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleString("en-GB", { weekday: "short", hour: "2-digit", minute: "2-digit" }) : null;

export function buyerOrderStatus(order: BuyerOrder): { label: string; tone: Tone; text: string } {
  const farms = order.seller_orders?.map((part) => part.farm.name).join(" and ") ?? "the farm";
  switch (order.status) {
    case "awaiting_confirmation":
      return {
        label: "Waiting for the farm",
        tone: "wait",
        text: `We've told ${farms}. They check they have everything and confirm, usually within a few hours. You pay once they do.`,
      };
    case "awaiting_payment":
      return {
        label: "Ready to pay",
        tone: "wait",
        text: `Confirmed by ${farms}. Pay by mobile money${order.payment_due_at ? ` by ${time(order.payment_due_at)}` : ""} to keep your order.`,
      };
    case "paid":
      return { label: "Paid", tone: "good", text: `Paid. ${farms} ${order.seller_orders && order.seller_orders.length > 1 ? "are" : "is"} getting it ready.` };
    case "completed":
      return { label: "Completed", tone: "good", text: "All done. Thank you for buying from local farms!" };
    case "cancelled":
      return { label: "Cancelled", tone: "bad", text: order.cancellation_reason ? `Cancelled: ${order.cancellation_reason}` : "This order was cancelled." };
  }
}

export function sellerOrderStatus(part: SellerOrder): { label: string; tone: Tone; text: string } {
  const pickup = part.fulfilment_method === "pickup";
  switch (part.status) {
    case "awaiting_confirmation":
      return { label: "Waiting for the farm", tone: "wait", text: `${part.farm.name} has until ${time(part.confirm_by) ?? "soon"} to confirm.` };
    case "accepted":
      return { label: "Confirmed", tone: "good", text: "Confirmed. Waiting for your payment." };
    case "rejected":
      return { label: "Declined", tone: "bad", text: part.rejection_reason ? `${part.farm.name} couldn't take it: ${part.rejection_reason}` : `${part.farm.name} couldn't take this order.` };
    case "expired":
      return { label: "Not answered", tone: "bad", text: `${part.farm.name} didn't answer in time, so this part was cancelled. You weren't charged.` };
    case "cancelled":
      return { label: "Cancelled", tone: "bad", text: "Cancelled." };
    case "preparing":
      return { label: "Being prepared", tone: "plain", text: pickup ? "The farm is getting it ready for you to collect." : "The farm is getting it ready to deliver." };
    case "ready":
      return { label: pickup ? "Ready to collect" : "Packed", tone: "good", text: pickup ? "Ready! Collect it from the farm and show your code." : "Packed and going out for delivery soon." };
    case "dispatched":
      return { label: "On the way", tone: "good", text: "On its way to you. Give your code to the person delivering it." };
    case "delivered":
      return {
        label: "Handed over",
        tone: "good",
        text: `You have it. Something not right? Report it${part.report_problem_until ? ` by ${time(part.report_problem_until)}` : " soon"}, otherwise the sale is final.`,
      };
    case "disputed":
      return { label: "Problem reported", tone: "bad", text: "You reported a problem. MundaLinq is looking into it and will message you." };
    case "completed":
      return { label: "Completed", tone: "good", text: "Completed." };
    default:
      return { label: part.status, tone: "plain", text: "" };
  }
}
