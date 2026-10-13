import { createStoredValue, useStoredValue } from "./stored";

// Favourites are kept in the browser for now: the API has no saved-items endpoint yet.
const favorites = createStoredValue<string[]>("mundalinq.favorites", []);

export function useFavorites() {
  const ids = useStoredValue(favorites);

  return {
    ids,
    has: (id: string) => ids.includes(id),
    toggle(id: string) {
      const current = favorites.get();
      favorites.set(current.includes(id) ? current.filter((other) => other !== id) : [id, ...current]);
    },
  };
}

/** Mirrors config('mundalinq.countries.ZW.provinces'); the API rejects any other value. */
export const PROVINCES = [
  "Bulawayo",
  "Harare",
  "Manicaland",
  "Mashonaland Central",
  "Mashonaland East",
  "Mashonaland West",
  "Masvingo",
  "Matabeleland North",
  "Matabeleland South",
  "Midlands",
] as const;

export const RADIUS_OPTIONS = [25, 50, 100] as const;

/** Where the buyer is shopping from: narrows listings to a province or to farms near a point. */
export type ShoppingArea =
  | { kind: "province"; province: string }
  | { kind: "nearby"; label: string; lat: number; lng: number; radiusKm: number };

const shoppingArea = createStoredValue<ShoppingArea | null>("mundalinq.area", null);

export function useShoppingArea(): [ShoppingArea | null, (area: ShoppingArea | null) => void] {
  return [useStoredValue(shoppingArea), shoppingArea.set];
}

export function areaLabel(area: ShoppingArea | null): string {
  if (!area) return "All of Zimbabwe";
  return area.kind === "province" ? area.province : `${area.label} · ${area.radiusKm} km`;
}

export function areaFilters(area: ShoppingArea | null) {
  if (!area) return {};
  return area.kind === "province" ? { province: area.province } : { lat: area.lat, lng: area.lng, radius_km: area.radiusKm };
}
