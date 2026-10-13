import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight } from "lucide-react";
import { Pill, PageHeading, QueueList, rowsOf, SearchBox } from "@/components/admin/ui";
import { roleName, roleNames, useAdminList } from "@/lib/admin";
import { formatPhone, initials, timeAgo } from "@/lib/format";
import type { AdminUser } from "@/lib/types";

const ROLES = Object.keys(roleNames);
const STATUSES = ["active", "suspended"] as const;

type UserSearch = { q?: string; role?: string; status?: (typeof STATUSES)[number] };

const text = (value: unknown) => (typeof value === "string" && value.trim() ? value.trim() : undefined);

export const Route = createFileRoute("/admin/users/")({
  validateSearch: (search: Record<string, unknown>): UserSearch => ({
    q: text(search.q),
    role: ROLES.find((role) => role === search.role),
    status: STATUSES.find((status) => status === search.status),
  }),
  component: UserList,
});

function UserList() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();
  const list = useAdminList<AdminUser>("/admin/users", { q: search.q, role: search.role, status: search.status });
  const users = rowsOf(list.data);
  const set = (next: Partial<UserSearch>) => navigate({ search: (current) => ({ ...current, ...next }), replace: true });

  return (
    <>
      <PageHeading title="Users" text="Everyone with a MundaLinq account. Open someone to see their farms and orders, suspend them, or change their roles." />

      <div className="admin-filters">
        <SearchBox value={search.q ?? ""} onChange={(q) => set({ q: q || undefined })} placeholder="Search by name, phone or email" />
        <select className="admin-select" value={search.role ?? ""} onChange={(event) => set({ role: event.target.value || undefined })} aria-label="Role">
          <option value="">All roles</option>
          {ROLES.map((role) => (
            <option key={role} value={role}>
              {roleName(role)}
            </option>
          ))}
        </select>
        <select
          className="admin-select"
          value={search.status ?? ""}
          onChange={(event) => set({ status: (event.target.value || undefined) as UserSearch["status"] })}
          aria-label="Status"
        >
          <option value="">Active and suspended</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>

      <QueueList query={list} count={users.length} empty={{ title: "No one found", text: "Try a different name or number, or clear the filters." }}>
        {users.map((user) => (
          <li key={user.id}>
            <Link to="/admin/users/$userId" params={{ userId: user.id }} className="admin-row">
              <span className="admin-row-icon admin-avatar">{initials(user.name)}</span>
              <span className="admin-row-main">
                <strong>{user.name}</strong>
                <small>{[formatPhone(user.phone), user.email].filter(Boolean).join(" · ")}</small>
                <span className="admin-pills">
                  {user.status === "suspended" && <Pill tone="bad">Suspended</Pill>}
                  {user.roles?.map((role) => (
                    <Pill key={role} tone={role.includes("admin") || role === "support_agent" ? "wait" : "plain"}>
                      {roleName(role)}
                    </Pill>
                  ))}
                </span>
                <small className="admin-when">
                  {[user.created_at && `Joined ${timeAgo(user.created_at)}`, user.last_login_at && `last signed in ${timeAgo(user.last_login_at)}`]
                    .filter(Boolean)
                    .join(" · ")}
                </small>
              </span>
              <ChevronRight className="admin-row-arrow" />
            </Link>
          </li>
        ))}
      </QueueList>
    </>
  );
}
