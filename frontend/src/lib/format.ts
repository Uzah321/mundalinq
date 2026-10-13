import type { Unit, Variant } from "./types";

// The API exchanges money and quantities as decimal strings. Arithmetic here works on whole cents and
// thousandths (mirroring App\Support\Money and Quantity) so estimates never drift through floats.
// The server always recomputes the real totals.

function parseScaled(value: string, scale: number): number | null {
  const match = /^(\d+)(?:\.(\d+))?$/.exec(value.trim());
  if (!match) return null;

  const fraction = match[2] ?? "";
  if (fraction.length > scale) return null;

  return Number(match[1]) * 10 ** scale + Number(fraction.padEnd(scale, "0"));
}

export const toMinor = (amount: string) => parseScaled(amount, 2);
export const toMilli = (quantity: string) => parseScaled(quantity, 3);

export function fromMinor(minor: number): string {
  const digits = String(Math.round(minor)).padStart(3, "0");
  return `${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

/** 1500 -> "1.5", 28000 -> "28": the API's unpadded quantity form. */
export function fromMilli(milli: number): string {
  const whole = Math.floor(milli / 1000);
  const fraction = String(milli % 1000).padStart(3, "0").replace(/0+$/, "");
  return fraction ? `${whole}.${fraction}` : String(whole);
}

const formatters = new Map<string, Intl.NumberFormat>();

export function formatMoney(amount: string | number, currency = "USD"): string {
  let formatter = formatters.get(currency);
  if (!formatter) {
    formatter = new Intl.NumberFormat("en", { style: "currency", currency, currencyDisplay: "narrowSymbol" });
    formatters.set(currency, formatter);
  }
  return formatter.format(typeof amount === "number" ? amount : Number(amount));
}

export function unitName(unit: Unit, quantity: string): string {
  return toMilli(quantity) === 1000 ? unit.name : unit.plural_name;
}

/** "2 crates", "0.5 kilograms". */
export function formatQuantity(quantity: string, unit: Unit): string {
  return `${quantity} ${unitName(unit, quantity)}`;
}

/** Price per unit at a quantity, using the best bulk tier reached (as ProductVariant::unitPriceMinorFor). */
export function unitPriceMinor(variant: Variant, quantity: string): number {
  const milli = toMilli(quantity) ?? 0;
  let best: { min: number; price: string } | null = null;

  for (const tier of variant.price_tiers) {
    const min = toMilli(tier.min_quantity) ?? Infinity;
    if (milli >= min && (best === null || min > best.min)) best = { min, price: tier.price };
  }

  return toMinor(best?.price ?? variant.price) ?? 0;
}

export function lineTotalMinor(variant: Variant, quantity: string): number {
  return Math.round((unitPriceMinor(variant, quantity) * (toMilli(quantity) ?? 0)) / 1000);
}

/** Largest quantity a buyer can order now: the variant's cap or what is in stock, whichever is lower. */
export function maxOrderMilli(variant: Variant): number {
  const available = toMilli(variant.available_quantity) ?? 0;
  const cap = variant.max_order_quantity ? toMilli(variant.max_order_quantity) : null;
  return cap === null ? available : Math.min(cap, available);
}

/** One unit when allowed, otherwise the minimum order. */
export function initialQuantity(variant: Variant): string {
  const min = toMilli(variant.min_order_quantity) ?? 1000;
  return fromMilli(Math.max(min, Math.min(1000, maxOrderMilli(variant))));
}

export function harvestedLabel(date: string | null): string | null {
  if (!date) return null;
  const days = daysSince(date);
  if (days <= 0) return "Harvested today";
  if (days === 1) return "Harvested yesterday";
  return `Harvested ${new Date(`${date}T00:00:00`).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}`;
}

export function daysSince(date: string): number {
  const then = new Date(`${date}T00:00:00`);
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  return Math.round((now.getTime() - then.getTime()) / 86_400_000);
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join("");
}

/** +263770000003 -> +263 77 000 0003 */
export function formatPhone(phone: string): string {
  const match = /^\+263(\d{2})(\d{3})(\d{4})$/.exec(phone);
  return match ? `+263 ${match[1]} ${match[2]} ${match[3]}` : phone;
}
