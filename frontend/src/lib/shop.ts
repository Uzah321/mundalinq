import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "./api";
import { meKey, useSession } from "./auth";
import type { AppNotification, BuyerOrder, Cart, ChatMessage, Conversation, Paginated, PaymentWithAction } from "./types";

// Buying after the basket: checkout, orders and payment, messages with farms and notifications.
// Everything is cached under "me", so signing out clears it.

export type Fulfilment = "pickup" | "delivery";

/** What the buyer chose at checkout; farms they haven't chosen for get the server's default. */
export type CheckoutChoice = { fulfilment: Record<string, Fulfilment>; addressId: string | null };

const checkoutBody = ({ fulfilment, addressId }: CheckoutChoice) => ({
  ...(Object.keys(fulfilment).length ? { fulfilment } : {}),
  ...(addressId ? { address_id: addressId } : {}),
});

const cartKey = [...meKey, "cart"] as const;
const ordersKey = [...meKey, "orders"] as const;
const conversationsKey = [...meKey, "conversations"] as const;
const notificationsKey = [...meKey, "notifications"] as const;

/** The server prices delivery and checks stock, so every change to the choice asks it again. */
export function useQuote(choice: CheckoutChoice, enabled = true) {
  return useQuery<Cart>({
    queryKey: [...meKey, "quote", choice],
    queryFn: ({ signal }) => api<{ data: Cart }>("/checkout/quote", { method: "POST", body: checkoutBody(choice), signal }).then((response) => response.data),
    placeholderData: keepPreviousData,
    staleTime: 0,
    enabled,
  });
}

export function usePlaceOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ choice, expectedTotal, note }: { choice: CheckoutChoice; expectedTotal: string; note: string }) =>
      api<{ data: BuyerOrder }>("/orders", {
        method: "POST",
        body: { ...checkoutBody(choice), expected_total: expectedTotal, ...(note.trim() ? { note: note.trim() } : {}) },
      }).then((response) => response.data),
    onSuccess: () => {
      // The ordered items left the basket on the server.
      queryClient.invalidateQueries({ queryKey: cartKey });
      queryClient.invalidateQueries({ queryKey: ordersKey });
    },
  });
}

const nextPage = (last: Paginated<unknown>) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined);

export function useOrders() {
  const { signedIn } = useSession();
  return useInfiniteQuery({
    queryKey: [...ordersKey, "list"],
    queryFn: ({ pageParam, signal }) => api<Paginated<BuyerOrder>>("/orders", { query: { page: pageParam }, signal }),
    initialPageParam: 1,
    getNextPageParam: nextPage,
    enabled: signedIn,
  });
}

const settled = (order: BuyerOrder | undefined) => order?.status === "completed" || order?.status === "cancelled";

/** An order, checked every 15 seconds while farms are still working on it so changes show without a reload. */
export function useOrder(id: string) {
  return useQuery({
    queryKey: [...ordersKey, "detail", id],
    queryFn: ({ signal }) => api<{ data: BuyerOrder }>(`/orders/${id}`, { signal }).then((response) => response.data),
    refetchInterval: (query) => (settled(query.state.data) ? false : 15_000),
  });
}

/** Anything the buyer does to an order (cancel, confirm, review…); the order and the inbox refresh after it. */
export function useOrderAction<TInput>(action: (input: TInput) => Promise<unknown>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: action,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ordersKey });
      queryClient.invalidateQueries({ queryKey: notificationsKey });
    },
  });
}

export const startPayment = (orderId: string, phone: string) =>
  api<{ data: PaymentWithAction }>("/payments/initiate", { method: "POST", body: { order_id: orderId, method: "mobile_money", phone } }).then((response) => response.data);

export const paymentStatus = (paymentId: string) => api<{ data: PaymentWithAction }>(`/payments/${paymentId}/status`).then((response) => response.data);

// Messages

export function useConversations() {
  const { signedIn } = useSession();
  return useQuery({
    queryKey: [...conversationsKey, "list"],
    queryFn: ({ signal }) => api<Paginated<Conversation>>("/conversations", { signal }).then((response) => response.data),
    enabled: signedIn,
    refetchInterval: 30_000,
  });
}

type MessagePage = { data: ChatMessage[]; meta: { next_cursor: string | null } };

/** A thread, newest first from the API; checked every 5 seconds while open. */
export function useMessages(conversationId: string | null) {
  return useInfiniteQuery({
    queryKey: [...conversationsKey, "messages", conversationId],
    queryFn: ({ pageParam, signal }) =>
      api<MessagePage>(`/conversations/${conversationId}/messages`, { query: { cursor: pageParam }, signal }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.next_cursor ?? undefined,
    enabled: conversationId !== null,
    refetchInterval: 5_000,
  });
}

export type NewMessage = { conversationId: string | null; farmId: string | null; sellerOrderId?: string; productId?: string; body: string };

export function useSendMessage() {
  const queryClient = useQueryClient();
  return useMutation({
    // Writing about an order or a listing (or to a farm for the first time) goes through POST /conversations,
    // which continues the existing thread; otherwise reply in the thread itself (works for either side).
    mutationFn: ({ conversationId, farmId, sellerOrderId, productId, body }: NewMessage) =>
      (farmId && (!conversationId || sellerOrderId || productId)
        ? api<{ data: ChatMessage }>("/conversations", {
            method: "POST",
            body: { farm_id: farmId, ...(sellerOrderId ? { seller_order_id: sellerOrderId } : {}), ...(productId ? { product_id: productId } : {}), body },
          })
        : api<{ data: ChatMessage }>(`/conversations/${conversationId}/messages`, { method: "POST", body: { body } })
      ).then((response) => response.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: conversationsKey }),
  });
}

export const markConversationRead = (conversationId: string) => api(`/conversations/${conversationId}/read`, { method: "POST" });

// Notifications

type NotificationPage = Paginated<AppNotification> & { meta: { unread_count: number } };

/** The bell's number; checked every minute. */
export function useUnreadNotifications() {
  const { signedIn } = useSession();
  return useQuery({
    queryKey: [...notificationsKey, "unread"],
    queryFn: ({ signal }) => api<NotificationPage>("/me/notifications", { query: { per_page: 1 }, signal }).then((response) => response.meta.unread_count),
    enabled: signedIn,
    refetchInterval: 60_000,
  });
}

export function useNotifications() {
  return useInfiniteQuery({
    queryKey: [...notificationsKey, "list"],
    queryFn: ({ pageParam, signal }) => api<NotificationPage>("/me/notifications", { query: { page: pageParam }, signal }),
    initialPageParam: 1,
    getNextPageParam: nextPage,
  });
}

export function useNotificationActions() {
  const queryClient = useQueryClient();
  const refresh = () => queryClient.invalidateQueries({ queryKey: notificationsKey });
  return {
    read: useMutation({ mutationFn: (id: string) => api(`/me/notifications/${id}/read`, { method: "POST" }), onSuccess: refresh }),
    readAll: useMutation({ mutationFn: () => api("/me/notifications/read-all", { method: "POST" }), onSuccess: refresh }),
  };
}
