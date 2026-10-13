import type { ReactNode } from "react";
import { Link } from "@tanstack/react-router";
import { ArrowLeft, LoaderCircle } from "lucide-react";
import { useSession } from "@/lib/auth";
import { InboxLinks, Wordmark } from "./app-header";
import { SignInForm } from "./account-dialog";

type Props = {
  title: string;
  /** Where "back" goes; the market by default. */
  back?: { to: "/" | "/orders" | "/messages" | "/quotes"; label: string };
  /** Why signing in is needed, shown to signed-out visitors instead of the page. */
  signInReason: string;
  children: ReactNode;
  actions?: ReactNode;
};

/** Pages beyond the storefront (checkout, orders, messages): the header, a way back, and a sign-in gate. */
export function ShopPage({ title, back = { to: "/", label: "Back to the market" }, signInReason, children, actions }: Props) {
  const { signedIn, user } = useSession();

  return (
    <div className="shop-shell">
      <header className="app-header">
        <div className="header-inner">
          <Wordmark />
          <div className="header-actions">
            <InboxLinks />
          </div>
        </div>
      </header>
      <main className="shop-main">
        <Link to={back.to} className="page-back">
          <ArrowLeft /> {back.label}
        </Link>
        <div className="shop-heading">
          <h1>{title}</h1>
          {actions}
        </div>
        {!signedIn ? (
          <div className="shop-card shop-sign-in">
            <p>{signInReason}</p>
            <SignInForm onSignedIn={() => undefined} />
            <p className="field-hint">
              New to MundaLinq? <Link to="/">Create an account from the market</Link> (Account › Create account).
            </p>
          </div>
        ) : !user ? (
          <div className="shop-loading" aria-busy>
            <LoaderCircle className="animate-spin" />
          </div>
        ) : (
          children
        )}
      </main>
    </div>
  );
}
