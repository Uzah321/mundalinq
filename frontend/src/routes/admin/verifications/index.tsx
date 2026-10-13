import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, MessageCircle, Sprout } from "lucide-react";
import { FilterTabs, PageHeading, QueueList, rowsOf } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { farmerTypes, useDashboard, useFarmsWithoutDocuments, useVerifications, type ReviewStatus } from "@/lib/admin";
import { formatPhone, timeAgo } from "@/lib/format";
import type { AdminFarm, FarmVerification } from "@/lib/types";

/** Submissions by status, plus farms that haven't sent anything yet. */
type Tab = ReviewStatus | "unverified";

const TABS: readonly Tab[] = ["pending", "approved", "rejected", "unverified"];

const LABELS: Record<Tab, string> = { pending: "Waiting", approved: "Approved", rejected: "Rejected", unverified: "Not sent yet" };

const EMPTY: Record<Tab, { title: string; text: string }> = {
  pending: { title: "Nothing waiting", text: "New farms show up here when a farmer sends their ID from the app." },
  approved: { title: "No approved farms yet", text: "Farms you approve are listed here." },
  rejected: { title: "Nothing rejected", text: "Submissions you reject are listed here with the reason you gave." },
  unverified: { title: "Every farm has sent its ID", text: "Farms that sign up but haven't sent their ID yet show up here, so you can remind them." },
};

export const Route = createFileRoute("/admin/verifications/")({
  // Waiting is the default, so it stays out of the URL.
  validateSearch: (search: Record<string, unknown>): { status?: Tab } => ({
    status: TABS.find((tab) => tab !== "pending" && tab === search.status),
  }),
  component: VerificationQueue,
});

function VerificationQueue() {
  const { status = "pending" } = Route.useSearch();
  const navigate = Route.useNavigate();
  const waiting = useDashboard(30).data?.queues.farm_verifications;

  return (
    <>
      <PageHeading title="Farm verifications" text="A farm's listings only go on sale once you've checked its ID here. The farmer is told your decision straight away." />
      <FilterTabs
        options={TABS.map((tab) => ({ value: tab, label: LABELS[tab], count: tab === "pending" ? waiting : undefined }))}
        value={status}
        onSelect={(tab) => navigate({ search: tab === "pending" ? {} : { status: tab } })}
      />

      {status === "unverified" ? <FarmsWithoutDocuments /> : <Submissions status={status} />}
    </>
  );
}

function Submissions({ status }: { status: ReviewStatus }) {
  const list = useVerifications(status);
  const rows = rowsOf(list.data);

  return (
    <QueueList query={list} empty={EMPTY[status]} count={rows.length}>
      {rows.map((verification) => (
        <li key={verification.id}>
          <SubmissionRow verification={verification} />
        </li>
      ))}
    </QueueList>
  );
}

function SubmissionRow({ verification }: { verification: FarmVerification }) {
  const { farm, submitted_by: submitter } = verification;
  const place = farm?.primary_location;
  const when =
    verification.status === "pending"
      ? verification.submitted_at && `Sent ${timeAgo(verification.submitted_at)}`
      : verification.reviewed_at && `${verification.status === "approved" ? "Approved" : "Rejected"} ${timeAgo(verification.reviewed_at)}`;

  return (
    <Link to="/admin/verifications/$verificationId" params={{ verificationId: verification.id }} className="admin-row">
      <span className="admin-row-icon">
        <Sprout />
      </span>
      <span className="admin-row-main">
        <strong>{farm?.name ?? "Farm"}</strong>
        <small>{[farm && farmerTypes[farm.farmer_type], place && `${place.district}, ${place.province}`].filter(Boolean).join(" · ")}</small>
        {submitter && (
          <small>
            {submitter.name} · {formatPhone(submitter.phone)}
          </small>
        )}
        {verification.status === "rejected" && verification.rejection_reason && (
          <small className="admin-reason">“{verification.rejection_reason}”</small>
        )}
        {when && <small className="admin-when">{when}</small>}
      </span>
      <ChevronRight className="admin-row-arrow" />
    </Link>
  );
}

function FarmsWithoutDocuments() {
  const list = useFarmsWithoutDocuments();
  const farms = rowsOf(list.data);

  return (
    <>
      {farms.length > 0 && (
        <p className="admin-hint admin-list-note">
          These farms signed up but haven't sent their ID, so their listings can't go on sale. Remind them to open{" "}
          <strong>Farm › Verify your farm</strong> in the app.
        </p>
      )}
      <QueueList query={list} empty={EMPTY.unverified} count={farms.length}>
        {farms.map((farm) => (
          <li key={farm.id}>
            <FarmRow farm={farm} />
          </li>
        ))}
      </QueueList>
    </>
  );
}

function FarmRow({ farm }: { farm: AdminFarm }) {
  const owner = farm.owners?.[0];
  const place = farm.primary_location;
  const listings = farm.listings_count ? `${farm.listings_count} listing${farm.listings_count === 1 ? "" : "s"} waiting to go on sale` : "No listings yet";
  const reminder = owner
    ? `Hi ${owner.name}, this is MundaLinq. To start selling, please verify ${farm.name}: in the MundaLinq app open Farm, tap Verify your farm and send a photo of your ID.`
    : "";

  return (
    <div className="admin-row">
      <span className="admin-row-icon">
        <Sprout />
      </span>
      <span className="admin-row-main">
        <strong>{farm.name}</strong>
        <small>{[farmerTypes[farm.farmer_type], place && `${place.district}, ${place.province}`].filter(Boolean).join(" · ")}</small>
        {owner && (
          <small>
            {owner.name} · <a href={`tel:${owner.phone}`}>{formatPhone(owner.phone)}</a>
          </small>
        )}
        <small className="admin-when">{[farm.created_at && `Signed up ${timeAgo(farm.created_at)}`, listings].filter(Boolean).join(" · ")}</small>
      </span>
      {owner && (
        <Button variant="outline" size="sm" asChild className="admin-row-action">
          <a href={`https://wa.me/${owner.phone.replace(/\D/g, "")}?text=${encodeURIComponent(reminder)}`} target="_blank" rel="noreferrer">
            <MessageCircle /> Remind on WhatsApp
          </a>
        </Button>
      )}
    </div>
  );
}
