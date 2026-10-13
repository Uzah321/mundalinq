import { createFileRoute, Link } from "@tanstack/react-router";
import { ScrollText } from "lucide-react";
import { PageHeading, QueueList, rowsOf } from "@/components/admin/ui";
import { auditActions, roleName, useAdminList } from "@/lib/admin";
import { formatDateTime, formatMoney } from "@/lib/format";
import type { AuditEntry } from "@/lib/types";

export const Route = createFileRoute("/admin/audit-log")({
  validateSearch: (search: Record<string, unknown>): { action?: string } => ({
    action: typeof search.action === "string" && search.action in auditActions ? search.action : undefined,
  }),
  component: AuditLog,
});

/** "App\Models\FarmVerification" -> "Farm verification". */
function subjectName(type: string | null): string | null {
  if (!type) return null;
  const words = type.split("\\").pop()!.replace(/([a-z])([A-Z])/g, "$1 $2");
  return words.charAt(0) + words.slice(1).toLowerCase();
}

function details(entry: AuditEntry): string | null {
  const data = entry.metadata ?? {};
  const parts = [
    typeof data.role === "string" && roleName(data.role),
    typeof data.reason === "string" && `“${data.reason}”`,
    typeof data.amount_minor === "number" && formatMoney(data.amount_minor / 100),
    typeof data.reference === "string" && `ref ${data.reference}`,
    typeof data.resolution === "string" && data.resolution.replaceAll("_", " "),
  ];
  return parts.filter(Boolean).join(" · ") || null;
}

function AuditLog() {
  const { action } = Route.useSearch();
  const navigate = Route.useNavigate();
  const list = useAdminList<AuditEntry>("/admin/audit-logs", { action, per_page: 50 });
  const entries = rowsOf(list.data);

  return (
    <>
      <PageHeading title="Audit log" text="Every sensitive action staff and farmers take: approvals, suspensions, role changes, money and who viewed ID documents. Nothing here can be changed or deleted." />
      <div className="admin-filters">
        <select className="admin-select" value={action ?? ""} onChange={(event) => navigate({ search: { action: event.target.value || undefined } })} aria-label="Action">
          <option value="">All actions</option>
          {Object.entries(auditActions).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <QueueList query={list} count={entries.length} empty={{ title: "Nothing recorded", text: action ? "No entries for this action yet." : "Actions show up here as they happen." }}>
        {entries.map((entry) => (
          <li key={entry.id} className="admin-row">
            <span className="admin-row-icon">
              <ScrollText />
            </span>
            <span className="admin-row-main">
              <strong>{auditActions[entry.action] ?? entry.action}</strong>
              <small>
                {entry.actor ? (
                  <Link to="/admin/users/$userId" params={{ userId: entry.actor.id }}>
                    {entry.actor.name}
                  </Link>
                ) : (
                  "System"
                )}
                {entry.subject_type && " · "}
                {entry.subject_type?.endsWith("\\User") && entry.subject_id ? (
                  <Link to="/admin/users/$userId" params={{ userId: entry.subject_id }}>
                    the account
                  </Link>
                ) : (
                  subjectName(entry.subject_type)
                )}
                {details(entry) && ` · ${details(entry)}`}
              </small>
              <small className="admin-when">
                {entry.created_at && formatDateTime(entry.created_at)}
                {entry.ip_address && ` · ${entry.ip_address}`}
              </small>
            </span>
          </li>
        ))}
      </QueueList>
    </>
  );
}
