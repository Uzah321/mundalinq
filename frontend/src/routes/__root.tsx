import type { QueryClient } from "@tanstack/react-query";
import { createRootRouteWithContext, Link, Outlet } from "@tanstack/react-router";
import { Sprout, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Toaster } from "@/components/ui/sonner";
import { useSessionCleanup } from "@/lib/auth";

export const Route = createRootRouteWithContext<{ queryClient: QueryClient }>()({
  component: RootLayout,
  notFoundComponent: NotFound,
  errorComponent: PageError,
});

function RootLayout() {
  useSessionCleanup();

  return (
    <>
      <Outlet />
      <Toaster position="top-center" />
    </>
  );
}

function NotFound() {
  return (
    <main className="standalone-page">
      <div className="empty-state">
        <Sprout />
        <h3>Nothing growing here</h3>
        <p>The page you were looking for has moved or never existed.</p>
        <Button asChild>
          <Link to="/">Back to the market</Link>
        </Button>
      </div>
    </main>
  );
}

function PageError() {
  return (
    <main className="standalone-page">
      <div className="empty-state">
        <TriangleAlert />
        <h3>This page didn't load</h3>
        <p>Something went wrong on our end. You can try refreshing or head back home.</p>
        <div className="empty-actions">
          <Button onClick={() => window.location.reload()}>Try again</Button>
          <Button variant="outline" asChild>
            <a href="/">Go home</a>
          </Button>
        </div>
      </div>
    </main>
  );
}
