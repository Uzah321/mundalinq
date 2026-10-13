import { createFileRoute, Link } from "@tanstack/react-router";
import { LoaderCircle, MessageCircle, TriangleAlert } from "lucide-react";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { errorMessage } from "@/lib/api";
import { initials, timeAgo } from "@/lib/format";
import { useConversations } from "@/lib/shop";

export const Route = createFileRoute("/messages/")({
  component: Messages,
});

function Messages() {
  return (
    <ShopPage title="Messages" signInReason="Sign in to see your messages with farms.">
      <Inbox />
    </ShopPage>
  );
}

function Inbox() {
  const conversations = useConversations();

  if (conversations.isPending) {
    return (
      <div className="shop-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }
  if (conversations.isError) {
    return (
      <div className="empty-state shop-card">
        <TriangleAlert />
        <h3>Your messages didn't load</h3>
        <p>{errorMessage(conversations.error)}</p>
        <Button onClick={() => conversations.refetch()}>Try again</Button>
      </div>
    );
  }
  if (!conversations.data.length) {
    return (
      <div className="empty-state shop-card">
        <MessageCircle />
        <h3>No messages yet</h3>
        <p>Ask a farm about a listing, or message them about an order. Their replies show up here.</p>
        <Button asChild>
          <Link to="/">Go to the market</Link>
        </Button>
      </div>
    );
  }

  return (
    <ul className="admin-list">
      {conversations.data.map((conversation) => {
        const unread = conversation.unread_count ?? 0;
        return (
          <li key={conversation.id}>
            <Link
              to="/messages/chat"
              search={{ conversation: conversation.id, farm: conversation.farm.id, name: conversation.farm.name }}
              className={unread ? "admin-row unread" : "admin-row"}
            >
              <span className="admin-row-icon admin-avatar">{initials(conversation.farm.name)}</span>
              <span className="admin-row-main">
                <strong>{conversation.farm.name}</strong>
                {conversation.last_message && (
                  <small className="admin-clip">
                    {conversation.last_message.side === "buyer" ? "You: " : ""}
                    {conversation.last_message.body}
                  </small>
                )}
                {conversation.last_message_at && <small className="admin-when">{timeAgo(conversation.last_message_at)}</small>}
              </span>
              {unread > 0 && <span className="admin-count">{unread}</span>}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
