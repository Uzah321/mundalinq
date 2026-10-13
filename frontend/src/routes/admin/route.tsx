import { useEffect, type ReactNode } from "react";
import { createFileRoute, Link, Outlet } from "@tanstack/react-router";
import {
  LayoutDashboard,
  LoaderCircle,
  LogOut,
  MessageSquareWarning,
  Package,
  Receipt,
  ScrollText,
  ShieldAlert,
  ShieldCheck,
  Sprout,
  Star,
  Tags,
  Users,
  Wallet,
  type LucideIcon,
} from "lucide-react";
import { SignInForm } from "@/components/store/account-dialog";
import { Button } from "@/components/ui/button";
import { isAdmin, isStaff, isSuperAdmin, roleName, useDashboard } from "@/lib/admin";
import { useSession, useSignOut } from "@/lib/auth";
import type { Dashboard, User } from "@/lib/types";

export const Route = createFileRoute("/admin")({
  component: AdminLayout,
});

type NavItem = {
  to:
    | "/admin"
    | "/admin/verifications"
    | "/admin/farms"
    | "/admin/listings"
    | "/admin/orders"
    | "/admin/disputes"
    | "/admin/users"
    | "/admin/money"
    | "/admin/reviews"
    | "/admin/categories"
    | "/admin/audit-log";
  label: string;
  icon: LucideIcon;
  /** Who sees it; mirrors the API's role checks. */
  allowed: (user: User) => boolean;
  count?: (queues: Dashboard["queues"]) => number;
};

const NAV: NavItem[] = [
  { to: "/admin", label: "Overview", icon: LayoutDashboard, allowed: isAdmin },
  { to: "/admin/verifications", label: "Verifications", icon: ShieldCheck, allowed: isAdmin, count: (queues) => queues.farm_verifications },
  { to: "/admin/farms", label: "Farms", icon: Sprout, allowed: isAdmin },
  { to: "/admin/listings", label: "Listings", icon: Package, allowed: isAdmin, count: (queues) => queues.listings_to_review },
  { to: "/admin/orders", label: "Orders", icon: Receipt, allowed: isStaff },
  { to: "/admin/disputes", label: "Disputes", icon: MessageSquareWarning, allowed: isStaff, count: (queues) => queues.open_disputes },
  { to: "/admin/users", label: "Users", icon: Users, allowed: isStaff },
  { to: "/admin/money", label: "Money", icon: Wallet, allowed: isAdmin, count: (queues) => queues.payments_to_refund + queues.failed_refunds },
  { to: "/admin/reviews", label: "Reviews", icon: Star, allowed: isAdmin },
  { to: "/admin/categories", label: "Categories", icon: Tags, allowed: isAdmin },
  { to: "/admin/audit-log", label: "Audit log", icon: ScrollText, allowed: isSuperAdmin },
];

/** Staff pages: only staff get past the gate; the API checks the role again on every request. */
function AdminLayout() {
  const { signedIn, user, loadingUser } = useSession();
  const staff = isStaff(user);
  // Queue sizes for the badges; support agents can't read the dashboard, so they go without.
  const dashboard = useDashboard(30, isAdmin(user));
  const signOut = useSignOut();

  useEffect(() => {
    const previous = document.title;
    document.title = "MundaLinq admin";
    return () => {
      document.title = previous;
    };
  }, []);

  if (!signedIn) {
    return (
      <StaffGate title="Staff sign in" text="Sign in with your MundaLinq staff account.">
        <SignInForm onSignedIn={() => undefined} />
      </StaffGate>
    );
  }

  if (!user) {
    return loadingUser ? (
      <main className="standalone-page" aria-busy>
        <LoaderCircle className="animate-spin" />
      </main>
    ) : (
      <StaffGate title="Your account didn't load" text="Check your connection and try again.">
        <div className="empty-actions">
          <Button onClick={() => window.location.reload()}>Try again</Button>
        </div>
      </StaffGate>
    );
  }

  if (!staff) {
    return (
      <StaffGate
        icon={<ShieldAlert />}
        title="Staff only"
        text={`You're signed in as ${user.name}, which isn't a staff account. Sign out and sign in with a staff account.`}
      >
        <div className="empty-actions">
          <Button onClick={() => signOut.mutate()} disabled={signOut.isPending}>
            <LogOut /> Sign out
          </Button>
          <Button variant="outline" asChild>
            <Link to="/">Back to the market</Link>
          </Button>
        </div>
      </StaffGate>
    );
  }

  const queues = dashboard.data?.queues;
  const topRole = (["super_admin", "admin", "support_agent"] as const).find((role) => user.roles?.includes(role));

  return (
    <div className="admin-shell">
      <header className="app-header admin-top">
        <div className="header-inner admin-header">
          <Link to="/" className="wordmark" aria-label="MundaLinq home">
            <span className="brand-icon">
              <Sprout />
            </span>
            mundalinq<span className="brand-dot">.</span>
          </Link>
          <span className="admin-tag">Admin</span>
          <div className="admin-user">
            <span>
              {user.name}
              {topRole && <small>{roleName(topRole)}</small>}
            </span>
            <Button variant="ghost" size="sm" onClick={() => signOut.mutate()} disabled={signOut.isPending}>
              <LogOut /> Sign out
            </Button>
          </div>
        </div>
        <nav className="admin-nav" aria-label="Admin">
          {NAV.filter((item) => item.allowed(user)).map(({ to, label, icon: Icon, count }) => {
            const waiting = queues && count ? count(queues) : 0;
            return (
              <Link key={to} to={to} activeProps={{ className: "active" }} activeOptions={{ exact: to === "/admin" }}>
                <Icon /> {label}
                {waiting > 0 && (
                  <span className="admin-count" aria-label={`${waiting} waiting`}>
                    {waiting}
                  </span>
                )}
              </Link>
            );
          })}
        </nav>
      </header>
      <main className="admin-main">
        <Outlet />
      </main>
    </div>
  );
}

function StaffGate({ icon, title, text, children }: { icon?: ReactNode; title: string; text: string; children: ReactNode }) {
  return (
    <main className="standalone-page">
      <div className="staff-gate">
        <Link to="/" className="wordmark" aria-label="MundaLinq home">
          <span className="brand-icon">
            <Sprout />
          </span>
          mundalinq<span className="brand-dot">.</span>
        </Link>
        {icon && <span className="staff-gate-icon">{icon}</span>}
        <h1>{title}</h1>
        <p>{text}</p>
        {children}
      </div>
    </main>
  );
}
