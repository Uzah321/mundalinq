import { useEffect, useRef, useState, type ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowRight, BadgeCheck, ChevronDown, ClipboardList, Heart, Leaf, MapPin, Search, SlidersHorizontal, Sprout, TriangleAlert } from "lucide-react";
import { toast } from "sonner";
import { AccountDialog } from "@/components/store/account-dialog";
import { AppHeader } from "@/components/store/app-header";
import { BasketDialog } from "@/components/store/basket-dialog";
import { BottomNav, type NavKey } from "@/components/store/bottom-nav";
import { CategoryStrip } from "@/components/store/category-strip";
import { FilterDialog } from "@/components/store/filter-dialog";
import { HarvestBanner } from "@/components/store/harvest-banner";
import { LocationDialog } from "@/components/store/location-dialog";
import { ProductCard, ProductCardSkeleton } from "@/components/store/product-card";
import { ProductDialog } from "@/components/store/product-dialog";
import { Button } from "@/components/ui/button";
import { errorMessage, session } from "@/lib/api";
import { useSession } from "@/lib/auth";
import { useBasket } from "@/lib/basket";
import { initialQuantity, maxOrderMilli, toMilli } from "@/lib/format";
import { areaFilters, areaLabel, useFavorites, useShoppingArea } from "@/lib/preferences";
import {
  productQuery,
  summaryFromCard,
  summaryFromDetail,
  useCategories,
  useProducts,
  useUnits,
  useProductSearch,
  type Fulfilment,
  type ProductSummary,
  type Sort,
} from "@/lib/queries";
import { cn } from "@/lib/utils";

const SORTS: readonly Sort[] = ["newest", "nearest", "price_asc", "price_desc", "freshest"];

type StoreSearch = {
  q?: string;
  category?: string;
  sort?: Sort;
  fulfilment?: Fulfilment;
  view?: "favorites";
  /** Open listing; kept in the URL so a product can be shared. */
  product?: string;
};

const text = (value: unknown) => (typeof value === "string" && value.trim() ? value : undefined);

export const Route = createFileRoute("/")({
  validateSearch: (search: Record<string, unknown>): StoreSearch => ({
    q: text(search.q),
    category: text(search.category),
    sort: SORTS.find((sort) => sort === search.sort),
    fulfilment: search.fulfilment === "pickup" || search.fulfilment === "delivery" ? search.fulfilment : undefined,
    view: search.view === "favorites" ? "favorites" : undefined,
    product: text(search.product),
  }),
  component: Storefront,
});

type Panel = "basket" | "account" | "location" | "filters";

function Storefront() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();
  const queryClient = useQueryClient();
  const { user } = useSession();
  const basket = useBasket();
  const favorites = useFavorites();
  const [area] = useShoppingArea();
  // Warm the unit rules (whole crates, fractional kg) the product dialog checks quantities against.
  useUnits();

  const [panel, setPanel] = useState<Panel | null>(null);
  const [accountReason, setAccountReason] = useState<string | null>(null);
  const afterSignIn = useRef<(() => void) | null>(null);
  const [addingId, setAddingId] = useState<string | null>(null);
  const catalogRef = useRef<HTMLElement>(null);

  const nearby = area?.kind === "nearby";
  const sort = search.sort === "nearest" && !nearby ? undefined : search.sort;
  const showingFavorites = search.view === "favorites";
  const filtered = Boolean(search.q || search.category || showingFavorites);

  function setSearch(change: Partial<StoreSearch>, { push = false } = {}) {
    return navigate({ search: (previous) => ({ ...previous, ...change }), replace: !push, resetScroll: false });
  }

  function scrollToCatalog() {
    catalogRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function requireAccount(reason: string | null, then?: () => void) {
    setAccountReason(reason);
    afterSignIn.current = then ?? null;
    setPanel("account");
  }

  function closePanel() {
    setPanel(null);
    setAccountReason(null);
    afterSignIn.current = null;
  }

  async function quickAdd(product: ProductSummary) {
    if (!session.token()) {
      requireAccount(`Sign in to add ${product.title} to your basket.`, () => quickAdd(product));
      return;
    }

    setAddingId(product.id);
    try {
      const detail = await queryClient.fetchQuery(productQuery(product.id));
      // Several ways to buy (per kg, per crate...): let the buyer choose.
      if (detail.variants.length !== 1) {
        setSearch({ product: product.id });
        return;
      }

      const [variant] = detail.variants;
      if (maxOrderMilli(variant) < (toMilli(variant.min_order_quantity) ?? 0)) {
        toast.error(`${detail.title} is sold out for now.`);
        return;
      }

      await basket.actions.add.mutateAsync({ variantId: variant.id, quantity: initialQuantity(variant) });
      toast.success(`${detail.title} is in your basket`, {
        action: { label: "View basket", onClick: () => setPanel("basket") },
      });
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setAddingId(null);
    }
  }

  function selectNav(key: NavKey) {
    switch (key) {
      case "home":
        navigate({ search: {}, resetScroll: false });
        window.scrollTo({ top: 0, behavior: "smooth" });
        break;
      case "browse":
        setSearch({ view: undefined }, { push: showingFavorites }).then(scrollToCatalog);
        break;
      case "favorites":
        setSearch({ view: "favorites" }, { push: true }).then(scrollToCatalog);
        break;
      case "basket":
        setPanel("basket");
        break;
      case "account":
        requireAccount(null);
        break;
    }
  }

  const activeNav: NavKey =
    panel === "basket" ? "basket" : panel === "account" ? "account" : showingFavorites ? "favorites" : search.q || search.category ? "browse" : "home";

  const renderCard = (product: ProductSummary) => {
    const lines = basket.linesByProduct.get(product.id);
    return (
      <ProductCard
        key={product.id}
        product={product}
        favorite={favorites.has(product.id)}
        basketLine={lines?.length === 1 ? lines[0] : undefined}
        adding={addingId === product.id}
        basketBusy={basket.busy}
        onOpen={() => setSearch({ product: product.id })}
        onToggleFavorite={() => favorites.toggle(product.id)}
        onAdd={() => quickAdd(product)}
        onStep={(direction) => lines?.[0] && basket.step(lines[0], direction)}
      />
    );
  };

  return (
    <>
      <AppHeader
        areaLabel={area ? areaLabel(area) : "Choose your area"}
        basketCount={basket.count}
        onChooseArea={() => setPanel("location")}
        onOpenBasket={() => setPanel("basket")}
      />

      <main className="main-content">
        <section className="greeting">
          <div>
            <p className="eyebrow">{user ? `WELCOME BACK, ${user.name.split(" ")[0]!.toUpperCase()}` : "YOUR LOCAL FARM MARKET"}</p>
            <h1>
              Good food. Fresh beginnings<span>.</span>
            </h1>
            <p>From our farmers' hands to your home.</p>
          </div>
          <span className="fresh-note">
            <Leaf />
            Locally grown. Lovingly picked.
          </span>
        </section>

        <Button variant="ghost" className="mobile-location" onClick={() => setPanel("location")}>
          <MapPin />
          Shopping near <strong>{areaLabel(area)}</strong>
          <ChevronDown />
        </Button>

        <SearchRow
          value={search.q}
          onChange={(q) => setSearch({ q, view: undefined })}
          filtersActive={Boolean(sort || search.fulfilment)}
          onOpenFilters={() => setPanel("filters")}
        />

        {!search.q && !showingFavorites && (
          <>
            <HarvestBanner onShop={scrollToCatalog} />
            <div className="promise-strip">
              <span>
                <Sprout /> Farm fresh
              </span>
              <span>
                <BadgeCheck /> Verified farms
              </span>
              <span>
                <Heart /> Support local
              </span>
            </div>
          </>
        )}

        <CategoryStrip
          selected={search.category}
          onSelect={(category) => setSearch({ category, view: undefined }, { push: true })}
        />

        <section className="catalog" ref={catalogRef} aria-labelledby="catalog-heading">
          {showingFavorites ? (
            <Favorites
              ids={favorites.ids}
              renderCard={renderCard}
              onBrowse={() => setSearch({ view: undefined }, { push: true })}
            />
          ) : (
            <Results
              search={search}
              filters={{ q: search.q, category: search.category, sort, fulfilment: search.fulfilment, ...areaFilters(area) }}
              filtered={filtered}
              renderCard={renderCard}
              onClear={() => setSearch({ q: undefined, category: undefined, fulfilment: undefined }, { push: true })}
              onChangeArea={() => setPanel("location")}
            />
          )}
        </section>

        <section className="local-band bulk-band">
          <span className="local-icon">
            <ClipboardList />
          </span>
          <div>
            <h3>Buying for a restaurant, school or shop?</h3>
            <p>Tell farms what you need and how often. They send their prices, and you choose the best offer.</p>
          </div>
          <Button asChild className="bulk-band-action">
            <Link to="/quotes/new">
              Ask farms for a price <ArrowRight />
            </Link>
          </Button>
        </section>

        <section className="local-band">
          <span className="local-icon">
            <Sprout />
          </span>
          <div>
            <h3>Small farms. Big heart.</h3>
            <p>Every basket brings you closer to the people who grow your food.</p>
          </div>
          <Leaf className="local-leaf" aria-hidden />
        </section>

        <footer className="page-footer">
          <span>Fresh food. Real farmers. A happier everyday.</span>
          <span>
            Made for a little more goodness <Heart />
          </span>
        </footer>
      </main>

      <BottomNav active={activeNav} basketCount={basket.count} onSelect={selectNav} />

      <ProductDialog
        productId={search.product}
        onClose={() => setSearch({ product: undefined })}
        onRequireAccount={requireAccount}
        onOpenBasket={() => setPanel("basket")}
      />
      <BasketDialog
        open={panel === "basket"}
        onOpenChange={(open) => (open ? setPanel("basket") : closePanel())}
        onSignIn={() => requireAccount("Sign in to see your basket.")}
        onOpenProduct={(id) => {
          closePanel();
          setSearch({ product: id });
        }}
      />
      <AccountDialog
        open={panel === "account"}
        onOpenChange={(open) => (open ? setPanel("account") : closePanel())}
        reason={accountReason}
        onSignedIn={() => {
          const action = afterSignIn.current;
          afterSignIn.current = null;
          action?.();
        }}
      />
      <LocationDialog open={panel === "location"} onOpenChange={(open) => (open ? setPanel("location") : closePanel())} />
      <FilterDialog
        open={panel === "filters"}
        onOpenChange={(open) => (open ? setPanel("filters") : closePanel())}
        sort={sort ?? (nearby ? "nearest" : "newest")}
        fulfilment={search.fulfilment}
        canSortByDistance={nearby}
        onChange={(change) => {
          // The API's default order needs no parameter: nearest when shopping near a place, else newest.
          const defaultSort = nearby ? "nearest" : "newest";
          const next = "sort" in change ? { sort: change.sort === defaultSort ? undefined : change.sort } : { fulfilment: change.fulfilment };
          setSearch(next);
        }}
      />
    </>
  );
}

function SearchRow({
  value,
  onChange,
  filtersActive,
  onOpenFilters,
}: {
  value: string | undefined;
  onChange: (q: string | undefined) => void;
  filtersActive: boolean;
  onOpenFilters: () => void;
}) {
  const [draft, setDraft] = useState(value ?? "");

  // Follow the URL when it changes elsewhere ("View all", back button).
  useEffect(() => {
    if ((value ?? "") !== draft.trim()) setDraft(value ?? "");
  }, [value]);

  // Search as the buyer types, once they pause.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      const next = draft.trim() || undefined;
      if (next !== value) onChange(next);
    }, 300);
    return () => window.clearTimeout(timer);
  }, [draft]);

  return (
    <div className="search-row" role="search">
      <label className="search-field">
        <Search aria-hidden />
        <span className="sr-only">Search produce</span>
        <input
          type="search"
          placeholder="Search tomatoes, maize, eggs…"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          maxLength={100}
        />
      </label>
      <Button variant="outline" size="icon" className="filter-button" onClick={onOpenFilters} aria-label="Sort and filter">
        <SlidersHorizontal />
        {filtersActive && <span className="filter-dot" />}
      </Button>
    </div>
  );
}

function Results({
  search,
  filters,
  filtered,
  renderCard,
  onClear,
  onChangeArea,
}: {
  search: StoreSearch;
  filters: Parameters<typeof useProductSearch>[0];
  filtered: boolean;
  renderCard: (product: ProductSummary) => ReactNode;
  onClear: () => void;
  onChangeArea: () => void;
}) {
  const results = useProductSearch(filters);
  const { data: categories } = useCategories();
  const products = results.data?.pages.flatMap((page) => page.data.map(summaryFromCard)) ?? [];
  const total = results.data?.pages[0]?.meta.total ?? 0;
  const category = categories?.find((option) => option.slug === search.category);

  const [eyebrow, title] = search.q
    ? ["SEARCH RESULTS", `Results for “${search.q}”`]
    : category
      ? ["SHOP BY CATEGORY", category.name]
      : ["FRESH IN THE MARKET", "Fresh picks of the day"];

  return (
    <>
      <p className="eyebrow">{eyebrow}</p>
      <div className="section-heading">
        <h2 id="catalog-heading">{title}</h2>
        {filtered ? (
          <Button variant="ghost" onClick={onClear}>
            View all <ArrowRight />
          </Button>
        ) : (
          results.data && <span className="section-note">{total === 1 ? "1 listing" : `${total} listings`}</span>
        )}
      </div>

      {results.isPending ? (
        <div className="product-grid" aria-busy>
          {Array.from({ length: 8 }, (_, index) => (
            <ProductCardSkeleton key={index} />
          ))}
        </div>
      ) : results.isError && !results.data ? (
        <div className="empty-state">
          <TriangleAlert />
          <h3>We couldn't load the market</h3>
          <p>{errorMessage(results.error)}</p>
          <Button onClick={() => results.refetch()}>Try again</Button>
        </div>
      ) : products.length === 0 ? (
        <div className="empty-state">
          <Sprout />
          <h3>Nothing matches just yet</h3>
          <p>Try another category or search, or look further afield. New harvests are listed every day.</p>
          <div className="empty-actions">
            {filtered && <Button onClick={onClear}>Clear filters</Button>}
            <Button variant="outline" onClick={onChangeArea}>
              Change area
            </Button>
          </div>
        </div>
      ) : (
        <>
          <div className={cn("product-grid", results.isPlaceholderData && "is-updating")}>{products.map(renderCard)}</div>
          {results.hasNextPage && (
            <div className="load-more">
              <Button variant="outline" onClick={() => results.fetchNextPage()} disabled={results.isFetchingNextPage}>
                {results.isFetchingNextPage ? "Loading…" : "Show more produce"}
              </Button>
            </div>
          )}
        </>
      )}
    </>
  );
}

function Favorites({
  ids,
  renderCard,
  onBrowse,
}: {
  ids: string[];
  renderCard: (product: ProductSummary) => ReactNode;
  onBrowse: () => void;
}) {
  const saved = useProducts(ids);
  const loaded = saved.flatMap((query) => (query.data ? [summaryFromDetail(query.data)] : []));
  const pending = saved.filter((query) => query.isPending).length;
  const missing = saved.filter((query) => query.isError).length;

  return (
    <>
      <p className="eyebrow">SAVED FOR LATER</p>
      <div className="section-heading">
        <h2 id="catalog-heading">Your favourites</h2>
        <Button variant="ghost" onClick={onBrowse}>
          View all <ArrowRight />
        </Button>
      </div>

      {ids.length === 0 ? (
        <div className="empty-state">
          <Heart />
          <h3>No favourites yet</h3>
          <p>Tap the heart on anything you'd like to find again.</p>
          <Button onClick={onBrowse}>Browse produce</Button>
        </div>
      ) : (
        <>
          <div className="product-grid">
            {loaded.map(renderCard)}
            {Array.from({ length: pending }, (_, index) => (
              <ProductCardSkeleton key={`pending-${index}`} />
            ))}
          </div>
          {missing > 0 && (
            <p className="prototype-note favorites-note">
              {missing === 1 ? "One saved listing isn't" : `${missing} saved listings aren't`} available right now. Farms
              pause listings between harvests, so check back soon.
            </p>
          )}
        </>
      )}
    </>
  );
}
