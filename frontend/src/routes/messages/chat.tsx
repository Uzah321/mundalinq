import { useEffect, useMemo, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";
import { LoaderCircle, Receipt, Send, ShoppingBasket } from "lucide-react";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { errorMessage } from "@/lib/api";
import { meKey } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";
import { markConversationRead, useConversations, useMessages, useSendMessage } from "@/lib/shop";
import { cn } from "@/lib/utils";

type ChatSearch = {
  /** An existing thread. */
  conversation?: string;
  /** The farm to write to; finds the existing thread with it, or starts one. */
  farm?: string;
  /** What the buyer is writing about: their part of an order, or a listing. */
  order?: string;
  product?: string;
  /** The farm's name, so the page has a title before anything loads. */
  name?: string;
};

const text = (value: unknown) => (typeof value === "string" && value ? value : undefined);

export const Route = createFileRoute("/messages/chat")({
  validateSearch: (search: Record<string, unknown>): ChatSearch => ({
    conversation: text(search.conversation),
    farm: text(search.farm),
    order: text(search.order),
    product: text(search.product),
    name: text(search.name),
  }),
  component: Chat,
});

function Chat() {
  const search = Route.useSearch();
  return (
    <ShopPage title={search.name ?? "Messages"} back={{ to: "/messages", label: "All messages" }} signInReason="Sign in to message farms.">
      <Thread />
    </ShopPage>
  );
}

function Thread() {
  const search = Route.useSearch();
  const queryClient = useQueryClient();
  const conversations = useConversations();
  const existing = conversations.data?.find((conversation) => conversation.id === search.conversation || (search.farm && conversation.farm.id === search.farm));
  const [startedId, setStartedId] = useState<string | null>(null);
  const conversationId = search.conversation ?? existing?.id ?? startedId;
  const farmId = search.farm ?? existing?.farm.id ?? null;
  const messages = useMessages(conversationId);
  const send = useSendMessage();
  const [body, setBody] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const bottom = useRef<HTMLDivElement>(null);

  // Oldest at the top, like any chat.
  const thread = useMemo(() => (messages.data?.pages.flatMap((page) => page.data) ?? []).slice().reverse(), [messages.data]);
  const latest = thread.at(-1);

  useEffect(() => {
    bottom.current?.scrollIntoView({ block: "end" });
  }, [latest?.id]);

  // Reading the thread clears its unread count and the header badge.
  const latestFromThem = latest && !latest.from_me ? latest.id : null;
  useEffect(() => {
    if (!conversationId || !latestFromThem) return;
    markConversationRead(conversationId)
      .then(() => queryClient.invalidateQueries({ queryKey: [...meKey, "conversations", "list"] }))
      .catch(() => undefined);
  }, [conversationId, latestFromThem, queryClient]);

  async function submit(event?: FormEvent) {
    event?.preventDefault();
    const message = body.trim();
    if (!message) return;
    if (!conversationId && !farmId) return setProblem("Open this chat from a farm, a listing or an order.");
    setProblem(null);
    try {
      const sent = await send.mutateAsync({ conversationId, farmId, sellerOrderId: search.order, productId: search.product, body: message });
      setBody("");
      if (!conversationId) setStartedId(sent.conversation_id);
      await messages.refetch();
    } catch (error) {
      setProblem(errorMessage(error));
    }
  }

  function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      submit();
    }
  }

  const waitingForThread = conversations.isPending || (conversationId !== null && messages.isPending);

  return (
    <section className="shop-card chat">
      {(search.order || search.product) && (
        <p className="chat-context">
          {search.order ? <Receipt /> : <ShoppingBasket />}
          {search.order ? "About your order. The farm sees which one." : "About the listing you were looking at."}
          {search.order && (
            <Link to="/orders" className="admin-link">
              My orders
            </Link>
          )}
        </p>
      )}

      <div className="chat-thread" aria-live="polite">
        {waitingForThread ? (
          <div className="shop-loading" aria-busy>
            <LoaderCircle className="animate-spin" />
          </div>
        ) : thread.length === 0 ? (
          <p className="chat-empty">Say hello. The farm gets a notification and an SMS, and their reply shows up here.</p>
        ) : (
          <>
            {messages.hasNextPage && (
              <Button variant="ghost" size="sm" className="chat-older" onClick={() => messages.fetchNextPage()} disabled={messages.isFetchingNextPage}>
                Show earlier messages
              </Button>
            )}
            {thread.map((message) => (
              <div key={message.id} className={cn("chat-bubble", message.from_me ? "mine" : "theirs")}>
                {!message.from_me && <small className="chat-sender">{message.sender.name}</small>}
                {(message.seller_order?.number || message.product?.title) && (
                  <small className="chat-tag">About {message.seller_order?.number ? `order ${message.seller_order.number}` : message.product?.title}</small>
                )}
                <p>{message.body}</p>
                {message.created_at && <small className="chat-time">{formatDateTime(message.created_at)}</small>}
              </div>
            ))}
          </>
        )}
        <div ref={bottom} />
      </div>

      <form className="chat-composer" onSubmit={submit}>
        <textarea
          className="admin-textarea"
          rows={2}
          maxLength={2000}
          value={body}
          onChange={(event) => setBody(event.target.value)}
          onKeyDown={onKeyDown}
          placeholder="Write a message…"
          aria-label="Message"
        />
        <Button type="submit" disabled={send.isPending} aria-label="Send">
          {send.isPending ? <LoaderCircle className="animate-spin" /> : <Send />}
        </Button>
      </form>
      {problem && <p className="field-error">{problem}</p>}
    </section>
  );
}
