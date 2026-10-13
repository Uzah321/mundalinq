import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, ClipboardList, LoaderCircle, Plus, TriangleAlert } from "lucide-react";
import { Pill } from "@/components/admin/ui";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { errorMessage } from "@/lib/api";
import { formatQuantity, timeAgo } from "@/lib/format";
import { quoteStatus, scheduleText, useQuoteRequests } from "@/lib/quotes";

export const Route = createFileRoute("/quotes/")({
  component: Quotes,
});

function Quotes() {
  return (
    <ShopPage
      title="Requests for quotes"
      signInReason="Sign in to ask farms for a price."
      actions={
        <Button asChild>
          <Link to="/quotes/new">
            <Plus /> New request
          </Link>
        </Button>
      }
    >
      <p className="shop-intro">
        Buying a lot, or every week? Tell farms near you what you need. They send their prices, you compare them and accept the one you like:
        it becomes an order, and recurring ones can be ordered again in one tap.
      </p>
      <RequestList />
    </ShopPage>
  );
}

function RequestList() {
  const requests = useQuoteRequests();

  if (requests.isPending) {
    return (
      <div className="shop-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }
  if (requests.isError) {
    return (
      <div className="empty-state shop-card">
        <TriangleAlert />
        <h3>Your requests didn't load</h3>
        <p>{errorMessage(requests.error)}</p>
        <Button onClick={() => requests.refetch()}>Try again</Button>
      </div>
    );
  }
  if (!requests.data.length) {
    return (
      <div className="empty-state shop-card">
        <ClipboardList />
        <h3>No requests yet</h3>
        <p>For example: 200 kg of tomatoes every Friday, delivered to your restaurant.</p>
        <Button asChild>
          <Link to="/quotes/new">Ask farms for a price</Link>
        </Button>
      </div>
    );
  }

  return (
    <ul className="admin-list">
      {requests.data.map((request) => {
        const status = quoteStatus[request.status];
        return (
          <li key={request.id}>
            <Link to="/quotes/$requestId" params={{ requestId: request.id }} className="admin-row">
              <span className="admin-row-icon">
                <ClipboardList />
              </span>
              <span className="admin-row-main">
                <strong>
                  {formatQuantity(request.quantity, request.unit)} of {request.title}
                </strong>
                <small>
                  {scheduleText(request, new Date(`${request.needed_by}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" }))} · {request.district}
                </small>
                <span className="admin-pills">
                  <Pill tone={status.tone}>{status.label}</Pill>
                  <small className="admin-when">
                    {request.offers_count ?? 0} offer{request.offers_count === 1 ? "" : "s"}
                    {request.created_at && ` · asked ${timeAgo(request.created_at)}`}
                  </small>
                </span>
              </span>
              <ChevronRight className="admin-row-arrow" />
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
