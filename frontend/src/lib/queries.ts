import {
  keepPreviousData,
  queryOptions,
  useInfiniteQuery,
  useIsMutating,
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { api } from "./api";
import { meKey, useSession } from "./auth";
import { toMinor } from "./format";
import type { Address, Cart, Category, Paginated, ProductCard, ProductDetail, UnitDefinition } from "./types";

export type Sort = "newest" | "nearest" | "price_asc" | "price_desc" | "freshest";
export type Fulfilment = "pickup" | "delivery";

export type ProductFilters = {
  q?: string;
  category?: string;
  sort?: Sort;
  fulfilment?: Fulfilment;
  province?: string;
  lat?: number;
  lng?: number;
  radius_km?: number;
};

export function useCategories() {
  return useQuery({
    queryKey: ["categories"],
    queryFn: ({ signal }) => api<{ data: Category[] }>("/categories", { signal }).then((response) => response.data),
    staleTime: 60 * 60_000,
  });
}

/** Unit rules by code: whether a unit can be ordered in fractions (kg) or only whole (crates). */
export function useUnits() {
  return useQuery({
    queryKey: ["units"],
    queryFn: ({ signal }) =>
      api<{ data: UnitDefinition[] }>("/units", { signal }).then(
        (response) => new Map(response.data.map((unit) => [unit.code, unit])),
      ),
    staleTime: Infinity,
  });
}

export function useProductSearch(filters: ProductFilters, enabled = true) {
  return useInfiniteQuery({
    queryKey: ["products", filters],
    queryFn: ({ pageParam, signal }) =>
      api<Paginated<ProductCard>>("/products", { query: { ...filters, page: pageParam, per_page: 20 }, signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export const productQuery = (id: string) =>
  queryOptions({
    queryKey: ["product", id],
    queryFn: ({ signal }) => api<{ data: ProductDetail }>(`/products/${id}`, { signal }).then((response) => response.data),
    staleTime: 30_000,
  });

export function useProducts(ids: string[]) {
  return useQueries({ queries: ids.map((id) => ({ ...productQuery(id), retry: false })) });
}

/** What a product card needs, from either a search result or a full listing. */
export type ProductSummary = {
  id: string;
  title: string;
  categorySlug: string;
  grade: ProductCard["quality_grade"];
  currency: string;
  price: string | null;
  unit: string | null;
  image: string | null;
  farmName: string;
  district: string;
  distanceKm?: number;
  harvestedOn: string | null;
};

export function summaryFromCard(card: ProductCard): ProductSummary {
  return {
    id: card.id,
    title: card.title,
    categorySlug: card.category.slug,
    grade: card.quality_grade,
    currency: card.currency,
    price: card.from_price?.price ?? null,
    unit: card.from_price?.unit ?? null,
    image: card.cover_image?.thumb_url ?? null,
    farmName: card.farm.name,
    district: card.location.district,
    distanceKm: card.distance_km,
    harvestedOn: card.last_harvested_on,
  };
}

export function summaryFromDetail(product: ProductDetail): ProductSummary {
  const cheapest = [...product.variants].sort((a, b) => (toMinor(a.price) ?? 0) - (toMinor(b.price) ?? 0))[0];

  return {
    id: product.id,
    title: product.title,
    categorySlug: product.category.slug,
    grade: product.quality_grade,
    currency: product.currency,
    price: cheapest?.price ?? null,
    unit: cheapest?.unit.code ?? null,
    image: product.images?.[0]?.thumb_url ?? null,
    farmName: product.farm.name,
    district: product.location.district,
    harvestedOn: product.last_harvested_on,
  };
}

const cartKey = [...meKey, "cart"] as const;

export function useCart() {
  const { signedIn } = useSession();

  return useQuery({
    queryKey: cartKey,
    queryFn: ({ signal }) => api<{ data: Cart }>("/cart", { signal }).then((response) => response.data),
    enabled: signedIn,
  });
}

/** True while any basket change is in flight; controls wait so quantities never race each other. */
export function useCartBusy() {
  return useIsMutating({ mutationKey: ["cart"] }) > 0;
}

export function useCartActions() {
  const queryClient = useQueryClient();
  // Every cart endpoint answers with the whole cart, so the cache is replaced rather than refetched.
  const store = (cart: Cart) => queryClient.setQueryData(cartKey, cart);
  const unwrap = (response: { data: Cart }) => response.data;

  return {
    add: useMutation({
      mutationKey: ["cart"],
      mutationFn: ({ variantId, quantity }: { variantId: string; quantity: string }) =>
        api<{ data: Cart }>("/cart/items", { method: "POST", body: { variant_id: variantId, quantity } }).then(unwrap),
      onSuccess: store,
    }),
    update: useMutation({
      mutationKey: ["cart"],
      mutationFn: ({ itemId, quantity }: { itemId: string; quantity: string }) =>
        api<{ data: Cart }>(`/cart/items/${itemId}`, { method: "PATCH", body: { quantity } }).then(unwrap),
      onSuccess: store,
    }),
    remove: useMutation({
      mutationKey: ["cart"],
      mutationFn: (itemId: string) => api<{ data: Cart }>(`/cart/items/${itemId}`, { method: "DELETE" }).then(unwrap),
      onSuccess: store,
    }),
    clear: useMutation({
      mutationKey: ["cart"],
      mutationFn: () => api<void>("/cart", { method: "DELETE" }),
      onSuccess: () => queryClient.invalidateQueries({ queryKey: cartKey }),
    }),
  };
}

export function useAddresses() {
  const { signedIn } = useSession();

  return useQuery({
    queryKey: [...meKey, "addresses"],
    queryFn: ({ signal }) => api<{ data: Address[] }>("/me/addresses", { signal }).then((response) => response.data),
    enabled: signedIn,
  });
}
