import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { BadgeCheck, ChevronRight, ImageOff, Sparkles, X } from "lucide-react";
import { toast } from "sonner";
import { ConfirmDialog, Fact, Facts, FilterTabs, PageHeading, Pill, QueueList, ReasonDialog, rowsOf } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { useAdminAction, useAdminList, useDashboard } from "@/lib/admin";
import { api, errorMessage } from "@/lib/api";
import { formatDateTime, formatMoney, formatQuantity, timeAgo } from "@/lib/format";
import type { ListingStatus, ManagedListing } from "@/lib/types";

const TABS: readonly { value: ListingStatus; label: string }[] = [
  { value: "pending_review", label: "To review" },
  { value: "active", label: "Live" },
  { value: "rejected", label: "Rejected" },
  { value: "paused", label: "Paused" },
  { value: "draft", label: "Drafts" },
];

const EMPTY: Record<ListingStatus, { title: string; text: string }> = {
  pending_review: { title: "Nothing to review", text: "Listings in categories that need checking, or all listings if approval is switched on, wait here." },
  active: { title: "Nothing live", text: "Published listings that buyers can see show up here." },
  rejected: { title: "Nothing rejected", text: "Listings you reject or take down are listed here with the reason." },
  paused: { title: "Nothing paused", text: "Listings their farms have paused show up here." },
  draft: { title: "No drafts", text: "Listings farms haven't published yet show up here." },
};

const REJECT_REASONS = [
  "The photos don't show the actual produce. Please add your own photos.",
  "The price or unit looks wrong. Please check it and publish again.",
  "The title or description is misleading.",
  "This item isn't allowed on MundaLinq.",
];

export const Route = createFileRoute("/admin/listings")({
  validateSearch: (search: Record<string, unknown>): { status?: ListingStatus } => ({
    status: TABS.find((tab) => tab.value !== "pending_review" && tab.value === search.status)?.value,
  }),
  component: Listings,
});

const priceOf = (listing: ManagedListing) => {
  const variant = listing.variants[0];
  return variant ? `${formatMoney(variant.price, listing.currency)} / ${variant.unit.name.toLowerCase()}` : "No price";
};

function Listings() {
  const { status = "pending_review" } = Route.useSearch();
  const navigate = Route.useNavigate();
  const list = useAdminList<ManagedListing>("/admin/products", { status });
  const waiting = useDashboard(30).data?.queues.listings_to_review;
  const listings = rowsOf(list.data);
  const [open, setOpen] = useState<ManagedListing | null>(null);

  return (
    <>
      <PageHeading
        title="Listings"
        text="Approve listings that need checking before buyers see them, and take down anything that shouldn't be on sale. The farmer gets your reason by SMS."
      />
      <FilterTabs
        options={TABS.map((tab) => ({ ...tab, count: tab.value === "pending_review" ? waiting : undefined }))}
        value={status}
        onSelect={(value) => navigate({ search: value === "pending_review" ? {} : { status: value } })}
      />

      <QueueList query={list} count={listings.length} empty={EMPTY[status]}>
        {listings.map((listing) => {
          const photo = listing.cover_image ?? listing.images?.[0];
          return (
            <li key={listing.id}>
              <button type="button" className="admin-row" onClick={() => setOpen(listing)}>
                <span className="admin-thumb">{photo ? <img src={photo.thumb_url} alt="" /> : <ImageOff />}</span>
                <span className="admin-row-main">
                  <strong>{listing.title}</strong>
                  <small>
                    {listing.farm.name} · {listing.category.name} · {listing.location.district}
                  </small>
                  <small>{priceOf(listing)}</small>
                  {listing.rejection_reason && status === "rejected" && <small className="admin-reason">“{listing.rejection_reason}”</small>}
                  {listing.created_at && <small className="admin-when">Added {timeAgo(listing.created_at)}</small>}
                </span>
                <ChevronRight className="admin-row-arrow" />
              </button>
            </li>
          );
        })}
      </QueueList>

      {open && <ListingDialog listing={open} onClose={() => setOpen(null)} />}
    </>
  );
}

function ListingDialog({ listing: initial, onClose }: { listing: ManagedListing; onClose: () => void }) {
  // Kept here so featuring changes show at once; the list behind refreshes too.
  const [listing, setListing] = useState(initial);
  const [decision, setDecision] = useState<null | "approve" | "reject">(null);
  const [weeks, setWeeks] = useState(1);
  const approve = useAdminAction(() => api(`/admin/products/${listing.id}/approve`, { method: "POST" }));
  const reject = useAdminAction((reason: string) => api(`/admin/products/${listing.id}/reject`, { method: "POST", body: { reason } }));
  const feature = useAdminAction((change: { weeks: number } | null) =>
    api<{ data: ManagedListing }>(`/admin/products/${listing.id}/feature`, change ? { method: "POST", body: change } : { method: "DELETE" }),
  );

  async function changeFeaturing(change: { weeks: number } | null) {
    try {
      const response = await feature.mutateAsync(change);
      setListing(response.data);
      toast.success(change ? `Featured for ${change.weeks} week${change.weeks === 1 ? "" : "s"}, free` : "No longer featured");
    } catch (error) {
      toast.error(errorMessage(error));
    }
  }
  const images = listing.images ?? [];
  const live = listing.status === "active";

  return (
    <>
      <Dialog open={decision === null} onOpenChange={(open) => !open && onClose()}>
        <DialogContent className="store-dialog admin-wide-dialog">
          <DialogHeader>
            <DialogTitle>{listing.title}</DialogTitle>
            <DialogDescription>
              {listing.farm.name}
              {listing.farm.verified && " (verified farm)"} · {listing.location.district}, {listing.location.province}
            </DialogDescription>
          </DialogHeader>
          {images.length > 0 ? (
            <div className="admin-photo-strip">
              {images.map((image) => (
                <a key={image.id} href={image.url} target="_blank" rel="noreferrer">
                  <img src={image.thumb_url} alt="" />
                </a>
              ))}
            </div>
          ) : (
            <p className="admin-hint">No photos yet.</p>
          )}
          {listing.description && <p className="admin-description">{listing.description}</p>}
          <Facts>
            <Fact label="Category">{listing.category.name}</Fact>
            {listing.quality_grade && <Fact label="Grade">{listing.quality_grade}</Fact>}
            <Fact label="Ways to buy">
              {listing.variants.map((variant) => (
                <span key={variant.id} className="admin-line">
                  {variant.label ?? variant.unit.name}: {formatMoney(variant.price, listing.currency)}
                </span>
              ))}
            </Fact>
            <Fact label="In stock">{formatQuantity(listing.stock.available, listing.stock_unit)}</Fact>
            <Fact label="Status">
              <Pill tone={live ? "good" : listing.status === "rejected" ? "bad" : listing.status === "pending_review" ? "wait" : "plain"}>
                {TABS.find((tab) => tab.value === listing.status)?.label ?? listing.status}
              </Pill>
            </Fact>
            {listing.expires_at && live && <Fact label="Live until">{formatDateTime(listing.expires_at)}</Fact>}
            {live && <Fact label="Featured">{listing.featured_until ? `Until ${formatDateTime(listing.featured_until)}` : "No"}</Fact>}
            {listing.rejection_reason && <Fact label="Reason given">{listing.rejection_reason}</Fact>}
          </Facts>
          {live && (
            <div className="admin-inline-form" aria-label="Featuring">
              <select className="admin-select" value={weeks} onChange={(event) => setWeeks(Number(event.target.value))} aria-label="How long">
                {[1, 2, 4].map((option) => (
                  <option key={option} value={option}>
                    {option} week{option === 1 ? "" : "s"}
                  </option>
                ))}
              </select>
              <Button variant="outline" disabled={feature.isPending} onClick={() => changeFeaturing({ weeks })}>
                <Sparkles /> {listing.featured_until ? "Add free weeks" : "Feature for free"}
              </Button>
              {listing.featured_until && (
                <Button variant="ghost" disabled={feature.isPending} onClick={() => changeFeaturing(null)}>
                  Stop featuring
                </Button>
              )}
            </div>
          )}
          {!listing.farm.verified && listing.status === "pending_review" && (
            <div className="admin-outcome rejected">
              <p>{listing.farm.name} isn't verified yet, so this listing can't go live until it is. Check Verifications first.</p>
            </div>
          )}
          {(listing.status === "pending_review" || live) && (
            <div className="dialog-actions">
              <Button variant={live ? "destructive" : "outline"} onClick={() => setDecision("reject")}>
                <X /> {live ? "Take down" : "Reject"}
              </Button>
              {listing.status === "pending_review" && (
                <Button onClick={() => setDecision("approve")}>
                  <BadgeCheck /> Approve
                </Button>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={decision === "approve"}
        onOpenChange={(open) => !open && setDecision(null)}
        title={`Approve ${listing.title}?`}
        description="It goes on sale straight away and the farmer is told."
        confirmLabel="Approve"
        onConfirm={async () => {
          await approve.mutateAsync(undefined);
          toast.success(`${listing.title} is live`);
          onClose();
        }}
      />
      <ReasonDialog
        open={decision === "reject"}
        onOpenChange={(open) => !open && setDecision(null)}
        title={live ? `Take down ${listing.title}?` : `Reject ${listing.title}?`}
        description={live ? "Buyers stop seeing it at once. The farmer gets your reason and can fix it and publish again." : "The farmer gets your reason and can fix it and publish again."}
        label="Reason sent to the farmer"
        submitLabel={live ? "Take down" : "Reject"}
        destructive
        quickReasons={REJECT_REASONS}
        onSubmit={async (reason) => {
          await reject.mutateAsync(reason);
          toast.success(live ? "Listing taken down" : "Listing rejected");
          onClose();
        }}
      />
    </>
  );
}
