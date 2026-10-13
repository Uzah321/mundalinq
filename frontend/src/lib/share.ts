import { formatMoney } from "./format";
import type { ProductDetail } from "./types";

// Sharing to WhatsApp: a wa.me link opens WhatsApp (app or web) with the message ready to send to anyone.

const whatsApp = (text: string) => `https://wa.me/?text=${encodeURIComponent(text)}`;

/** Links in shared messages open this site; set VITE_PUBLIC_URL once it has a public address. */
const siteUrl = () => (import.meta.env.VITE_PUBLIC_URL || window.location.origin).replace(/\/$/, "");

export function shareListing(product: ProductDetail): string {
  const cheapest = product.variants[0];
  const price = cheapest ? ` from ${formatMoney(cheapest.price, product.currency)} per ${cheapest.unit.name.toLowerCase()}` : "";
  return whatsApp(`${product.title}${price} at ${product.farm.name} on MundaLinq: ${siteUrl()}/?product=${product.id}`);
}

export function shareFarm(farm: { name: string }, district: string): string {
  return whatsApp(`Fresh produce from ${farm.name} in ${district} on MundaLinq: ${siteUrl()}/?q=${encodeURIComponent(farm.name)}`);
}
