import { useState } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, Ban, BadgeCheck, Plus, UserCheck, X } from "lucide-react";
import { toast } from "sonner";
import { ConfirmDialog, Fact, Facts, ItemState, Pill, QueueList, ReasonDialog, rowsOf, Section } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { auditActions, humanize, isSuperAdmin, roleName, roleNames, useAdminAction, useAdminItem, useAdminList } from "@/lib/admin";
import { api } from "@/lib/api";
import { useSession } from "@/lib/auth";
import { formatDateTime, formatPhone, initials, timeAgo } from "@/lib/format";
import type { AdminUser, AuditEntry, VerificationStatus } from "@/lib/types";

export const Route = createFileRoute("/admin/users/$userId")({
  component: UserPage,
});

const SUSPEND_REASONS = [
  "Repeated fake or unpaid orders.",
  "Abusive messages to farmers or buyers.",
  "Selling items that are not allowed on MundaLinq.",
  "Suspected payment fraud.",
];

/** What a staff role lets someone do, shown before it is given. */
const ROLE_POWERS: Record<string, string> = {
  super_admin: "everything, including giving and removing roles and reading the audit log",
  admin: "verify farms, approve listings, handle money, disputes, reviews and categories, and suspend accounts",
  support_agent: "see orders and accounts and handle disputes",
};

const FARM_STATUS: Record<VerificationStatus, { label: string; tone: "wait" | "good" | "bad" | "plain" }> = {
  unverified: { label: "ID not sent", tone: "plain" },
  pending: { label: "Waiting for review", tone: "wait" },
  approved: { label: "Verified", tone: "good" },
  rejected: { label: "Rejected", tone: "bad" },
};

function UserPage() {
  const { userId } = Route.useParams();
  const { user: me } = useSession();
  const query = useAdminItem<AdminUser>(`/admin/users/${userId}`);
  const superAdmin = isSuperAdmin(me);
  const history = useAdminList<AuditEntry>("/admin/audit-logs", { subject_id: userId }, superAdmin);
  const [dialog, setDialog] = useState<null | "suspend" | "reactivate" | { grant: string } | { revoke: string }>(null);
  const [newRole, setNewRole] = useState("");

  const suspend = useAdminAction((reason: string) => api(`/admin/users/${userId}/suspend`, { method: "POST", body: { reason } }));
  const reactivate = useAdminAction(() => api(`/admin/users/${userId}/reactivate`, { method: "POST" }));
  const grant = useAdminAction((role: string) => api(`/admin/users/${userId}/roles`, { method: "POST", body: { role } }));
  const revoke = useAdminAction((role: string) => api(`/admin/users/${userId}/roles/${role}`, { method: "DELETE" }));

  const user = query.data;
  if (!user) return <ItemState query={query} missing="This account doesn't exist" />;

  const roles = user.roles ?? [];
  const isMe = user.id === me?.id;
  const close = (open: boolean) => !open && setDialog(null);
  const grantRole = dialog && typeof dialog === "object" && "grant" in dialog ? dialog.grant : null;
  const revokeRole = dialog && typeof dialog === "object" && "revoke" in dialog ? dialog.revoke : null;

  return (
    <>
      <Link to="/admin/users" className="admin-back">
        <ArrowLeft /> All users
      </Link>

      <div className="admin-person">
        <span className="admin-avatar large">{initials(user.name)}</span>
        <div className="admin-heading">
          <h1>{user.name}</h1>
          <span className="admin-pills">
            {user.status === "suspended" ? <Pill tone="bad">Suspended</Pill> : <Pill tone="good">Active</Pill>}
            {roles.map((role) => (
              <Pill key={role}>{roleName(role)}</Pill>
            ))}
          </span>
        </div>
        {!isMe && (
          <div className="admin-person-actions">
            {user.status === "suspended" ? (
              <Button onClick={() => setDialog("reactivate")}>
                <UserCheck /> Reactivate
              </Button>
            ) : (
              <Button variant="outline" onClick={() => setDialog("suspend")}>
                <Ban /> Suspend
              </Button>
            )}
          </div>
        )}
      </div>

      {user.status === "suspended" && (
        <div className="admin-outcome rejected">
          <Ban />
          <p>
            Suspended{user.suspended_at && ` on ${formatDateTime(user.suspended_at)}`}. They can't sign in or order.
            {user.suspension_reason && (
              <>
                <br />
                Reason sent to them: “{user.suspension_reason}”
              </>
            )}
          </p>
        </div>
      )}

      <div className="admin-grid">
        <Section title="Account">
          <Facts>
            <Fact label="Phone">
              <a href={`tel:${user.phone}`}>{formatPhone(user.phone)}</a>{" "}
              {user.phone_verified ? (
                <span className="verified">
                  <BadgeCheck /> confirmed
                </span>
              ) : (
                <span className="admin-warn">not confirmed</span>
              )}
            </Fact>
            {user.email && <Fact label="Email">{user.email}</Fact>}
            {user.created_at && <Fact label="Joined">{formatDateTime(user.created_at)}</Fact>}
            <Fact label="Last signed in">{user.last_login_at ? formatDateTime(user.last_login_at) : "Never"}</Fact>
            <Fact label="Orders">
              {user.orders_count ? (
                <Link to="/admin/orders" search={{ q: user.phone }}>
                  {user.orders_count} order{user.orders_count === 1 ? "" : "s"}
                </Link>
              ) : (
                "None yet"
              )}
            </Fact>
          </Facts>
        </Section>

        <Section title="Roles">
          <div className="admin-role-list">
            {roles.map((role) => (
              <span key={role} className="admin-role">
                {roleName(role)}
                {superAdmin && (
                  <button type="button" aria-label={`Remove ${roleName(role)}`} onClick={() => setDialog({ revoke: role })}>
                    <X />
                  </button>
                )}
              </span>
            ))}
          </div>
          {superAdmin ? (
            <form
              className="admin-inline-form"
              onSubmit={(event) => {
                event.preventDefault();
                if (newRole) setDialog({ grant: newRole });
                else toast.error("Choose a role from the list first.");
              }}
            >
              <select className="admin-select" value={newRole} onChange={(event) => setNewRole(event.target.value)} aria-label="Role to add">
                <option value="">Choose a role to add…</option>
                {Object.keys(roleNames)
                  .filter((role) => !roles.includes(role))
                  .map((role) => (
                    <option key={role} value={role}>
                      {roleName(role)}
                    </option>
                  ))}
              </select>
              <Button type="submit" variant="outline">
                <Plus /> Add role
              </Button>
            </form>
          ) : (
            <p className="admin-hint">Only a super admin can add or remove roles.</p>
          )}
        </Section>

        <Section title="Farms" wide>
          {user.farms?.length ? (
            <ul className="admin-mini-list">
              {user.farms.map((farm) => (
                <li key={farm.id}>
                  <strong>{farm.name}</strong>
                  <small>{humanize(farm.role)}</small>
                  <Pill tone={FARM_STATUS[farm.verification_status].tone}>{FARM_STATUS[farm.verification_status].label}</Pill>
                </li>
              ))}
            </ul>
          ) : (
            <p className="admin-hint">Not part of any farm.</p>
          )}
        </Section>

        {superAdmin && (
          <Section title="History" wide>
            <QueueList query={history} count={rowsOf(history.data).length} empty={{ title: "Nothing recorded yet", text: "Staff actions on this account show up here." }}>
              {rowsOf(history.data).map((entry) => (
                <li key={entry.id} className="admin-log-row">
                  <strong>{auditActions[entry.action] ?? entry.action}</strong>
                  <small>
                    {[entry.actor?.name ?? "System", entry.created_at && timeAgo(entry.created_at), describeMetadata(entry.metadata)].filter(Boolean).join(" · ")}
                  </small>
                </li>
              ))}
            </QueueList>
          </Section>
        )}
      </div>

      <ReasonDialog
        open={dialog === "suspend"}
        onOpenChange={close}
        title={`Suspend ${user.name}?`}
        description="They're signed out everywhere and can't sign in or order until you reactivate them. They get your reason by SMS."
        label="Reason sent to them"
        submitLabel="Suspend account"
        destructive
        quickReasons={SUSPEND_REASONS}
        onSubmit={async (reason) => {
          await suspend.mutateAsync(reason);
          toast.success(`${user.name} is suspended`);
        }}
      />
      <ConfirmDialog
        open={dialog === "reactivate"}
        onOpenChange={close}
        title={`Reactivate ${user.name}?`}
        description="They can sign in and use MundaLinq again straight away."
        confirmLabel="Reactivate"
        onConfirm={async () => {
          await reactivate.mutateAsync(undefined);
          toast.success(`${user.name} can use MundaLinq again`);
        }}
      />
      <ConfirmDialog
        open={grantRole !== null}
        onOpenChange={close}
        title={`Make ${user.name} ${grantRole ? roleName(grantRole).toLowerCase() : ""}?`}
        description={
          grantRole && ROLE_POWERS[grantRole]
            ? `As ${roleName(grantRole).toLowerCase()} they can ${ROLE_POWERS[grantRole]}. Staff sign in with a code texted to ${formatPhone(user.phone)}.`
            : "They get the features of this role straight away."
        }
        confirmLabel="Add role"
        onConfirm={async () => {
          await grant.mutateAsync(grantRole!);
          setNewRole("");
          toast.success(`${user.name} is now ${roleName(grantRole!).toLowerCase()}`);
        }}
      />
      <ConfirmDialog
        open={revokeRole !== null}
        onOpenChange={close}
        title={`Remove ${revokeRole ? roleName(revokeRole).toLowerCase() : ""} from ${user.name}?`}
        description={
          revokeRole && ROLE_POWERS[revokeRole]
            ? "They lose staff access at once and are signed out of every device."
            : "They lose the features of this role straight away."
        }
        confirmLabel="Remove role"
        destructive
        onConfirm={async () => {
          await revoke.mutateAsync(revokeRole!);
          toast.success("Role removed");
        }}
      />
    </>
  );
}

/** The useful part of an audit entry's details, e.g. the role or the reason. */
function describeMetadata(metadata: Record<string, unknown> | null): string | null {
  if (!metadata) return null;
  if (typeof metadata.role === "string") return roleName(metadata.role);
  if (typeof metadata.reason === "string") return `“${metadata.reason}”`;
  return null;
}
