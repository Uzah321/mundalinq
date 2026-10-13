import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Eye, EyeOff, Star } from "lucide-react";
import { toast } from "sonner";
import { ConfirmDialog, FilterTabs, PageHeading, Pill, QueueList, ReasonDialog, rowsOf } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { useAdminAction, useAdminList } from "@/lib/admin";
import { api } from "@/lib/api";
import { formatDateTime, timeAgo } from "@/lib/format";
import type { AdminReview } from "@/lib/types";

type Show = "visible" | "low" | "hidden";

const HIDE_REASONS = [
  "Abusive or offensive language.",
  "Shares personal details such as phone numbers.",
  "Not about this order or farm.",
  "Spam or advertising.",
];

export const Route = createFileRoute("/admin/reviews")({
  validateSearch: (search: Record<string, unknown>): { show?: Exclude<Show, "visible"> } => ({
    show: search.show === "low" || search.show === "hidden" ? search.show : undefined,
  }),
  component: Reviews,
});

function Reviews() {
  const { show = "visible" } = Route.useSearch();
  const navigate = Route.useNavigate();
  const query = show === "hidden" ? { hidden: true } : show === "low" ? { hidden: false, max_rating: 2 } : { hidden: false };
  const list = useAdminList<AdminReview>("/admin/reviews", query);
  const reviews = rowsOf(list.data);
  const [action, setAction] = useState<null | { hide: AdminReview } | { unhide: AdminReview }>(null);
  const hide = useAdminAction(({ id, reason }: { id: string; reason: string }) => api(`/admin/reviews/${id}/hide`, { method: "POST", body: { reason } }));
  const unhide = useAdminAction((id: string) => api(`/admin/reviews/${id}/unhide`, { method: "POST" }));
  const hiding = action && "hide" in action ? action.hide : null;
  const unhiding = action && "unhide" in action ? action.unhide : null;

  return (
    <>
      <PageHeading title="Reviews" text="What buyers say about farms. Hide reviews that break the rules; hidden reviews don't count towards a farm's rating and can be shown again." />
      <FilterTabs
        options={[
          { value: "visible", label: "Showing" },
          { value: "low", label: "1 or 2 stars" },
          { value: "hidden", label: "Hidden" },
        ]}
        value={show}
        onSelect={(value) => navigate({ search: value === "visible" ? {} : { show: value } })}
      />
      <QueueList
        query={list}
        count={reviews.length}
        empty={show === "hidden" ? { title: "Nothing hidden", text: "Reviews you hide are listed here." } : { title: "No reviews here", text: "Buyers review farms after an order is completed." }}
      >
        {reviews.map((review) => (
          <li key={review.id} className="admin-row admin-review">
            <span className="admin-row-main">
              <span className="admin-stars" aria-label={`${review.rating} of 5 stars`}>
                {Array.from({ length: 5 }, (_, index) => (
                  <Star key={index} className={index < review.rating ? "on" : undefined} />
                ))}
              </span>
              {review.comment ? <p className="admin-quote">“{review.comment}”</p> : <small>No comment, just a rating.</small>}
              <small>
                {review.buyer.name} about <strong>{review.farm.name}</strong>
                {!!review.products?.length && ` · ${review.products.map((product) => product.title).join(", ")}`}
                {review.created_at && ` · ${timeAgo(review.created_at)}`}
              </small>
              {review.farm_response && <small className="admin-reply-note">Farm replied: “{review.farm_response}”</small>}
              {review.hidden_at && (
                <span className="admin-pills">
                  <Pill tone="bad">Hidden {formatDateTime(review.hidden_at)}</Pill>
                  {review.hidden_reason && <small className="admin-reason">{review.hidden_reason}</small>}
                </span>
              )}
            </span>
            {review.hidden_at ? (
              <Button variant="outline" size="sm" className="admin-row-action" onClick={() => setAction({ unhide: review })}>
                <Eye /> Show again
              </Button>
            ) : (
              <Button variant="outline" size="sm" className="admin-row-action" onClick={() => setAction({ hide: review })}>
                <EyeOff /> Hide
              </Button>
            )}
          </li>
        ))}
      </QueueList>

      <ReasonDialog
        open={hiding !== null}
        onOpenChange={(open) => !open && setAction(null)}
        title="Hide this review?"
        description={hiding ? `Buyers stop seeing it and it no longer counts towards ${hiding.farm.name}'s rating.` : ""}
        label="Why (kept for staff)"
        submitLabel="Hide review"
        destructive
        quickReasons={HIDE_REASONS}
        onSubmit={async (reason) => {
          await hide.mutateAsync({ id: hiding!.id, reason });
          toast.success("Review hidden");
        }}
      />
      <ConfirmDialog
        open={unhiding !== null}
        onOpenChange={(open) => !open && setAction(null)}
        title="Show this review again?"
        description={unhiding ? `Buyers see it again and it counts towards ${unhiding.farm.name}'s rating.` : ""}
        confirmLabel="Show again"
        onConfirm={async () => {
          await unhide.mutateAsync(unhiding!.id);
          toast.success("Review showing again");
        }}
      />
    </>
  );
}
