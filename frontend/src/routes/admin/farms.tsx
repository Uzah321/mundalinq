import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, Sprout } from "lucide-react";
import { FilterTabs, PageHeading, Pill, QueueList, rowsOf, SearchBox } from "@/components/admin/ui";
import { farmerTypes, farmStatuses, useAdminList } from "@/lib/admin";
import { formatPhone, timeAgo } from "@/lib/format";
import type { AdminFarm, VerificationStatus } from "@/lib/types";

type Show = VerificationStatus | "all";

const TABS: readonly { value: Show; label: string }[] = [
  { value: "all", label: "All farms" },
  { value: "approved", label: "Verified" },
  { value: "pending", label: "Waiting for review" },
  { value: "unverified", label: "ID not sent" },
  { value: "rejected", label: "Rejected" },
];

type FarmSearch = { q?: string; status?: VerificationStatus };

export const Route = createFileRoute("/admin/farms")({
  validateSearch: (search: Record<string, unknown>): FarmSearch => ({
    q: typeof search.q === "string" && search.q.trim() ? search.q.trim() : undefined,
    status: TABS.find((tab) => tab.value !== "all" && tab.value === search.status)?.value as VerificationStatus | undefined,
  }),
  component: Farms,
});

function Farms() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();
  const list = useAdminList<AdminFarm>("/admin/farms", { q: search.q, verification_status: search.status });
  const farms = rowsOf(list.data);

  return (
    <>
      <PageHeading
        title="Farms"
        text="Every farm and company on MundaLinq, verified or not. Buyers only see a farm's produce once the farm is verified and its listings are approved."
      />
      <div className="admin-filters">
        <SearchBox
          value={search.q ?? ""}
          onChange={(q) => navigate({ search: (current) => ({ ...current, q: q || undefined }), replace: true })}
          placeholder="Farm name, owner or phone number"
        />
      </div>
      <FilterTabs
        options={TABS}
        value={search.status ?? "all"}
        onSelect={(value) => navigate({ search: (current) => ({ ...current, status: value === "all" ? undefined : value }) })}
      />

      <QueueList query={list} count={farms.length} empty={{ title: "No farms found", text: "Try another name or number, or clear the search and filters." }}>
        {farms.map((farm) => {
          const owner = farm.owners?.[0];
          const status = farmStatuses[farm.verification_status];
          const place = farm.primary_location;
          const row = (
            <>
              <span className="admin-row-icon">
                <Sprout />
              </span>
              <span className="admin-row-main">
                <strong>{farm.name}</strong>
                <small>{[farmerTypes[farm.farmer_type], place && `${place.district}, ${place.province}`].filter(Boolean).join(" · ")}</small>
                {owner && (
                  <small>
                    Owner: {owner.name} · {formatPhone(owner.phone)}
                  </small>
                )}
                <span className="admin-pills">
                  <Pill tone={status.tone}>{status.label}</Pill>
                  <Pill>
                    {farm.listings_count ?? 0} listing{farm.listings_count === 1 ? "" : "s"}
                  </Pill>
                  {farm.created_at && <small className="admin-when">Added {timeAgo(farm.created_at)}</small>}
                </span>
              </span>
              <ChevronRight className="admin-row-arrow" />
            </>
          );
          return (
            <li key={farm.id}>
              {/* The owner's page lists all their farms with roles; staff manage the account from there. */}
              {owner ? (
                <Link to="/admin/users/$userId" params={{ userId: owner.id }} className="admin-row">
                  {row}
                </Link>
              ) : (
                <div className="admin-row">{row}</div>
              )}
            </li>
          );
        })}
      </QueueList>
    </>
  );
}
