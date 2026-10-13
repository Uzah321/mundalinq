import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "./api";
import { meKey, useSession } from "./auth";
import type { BuyerOrder, Paginated, Unit } from "./types";

// Requests for quotes: a buyer asks farms for a price ("200 kg of tomatoes every Friday"), compares the
// offers and accepts one, which places an ordinary order for the farm to confirm.

export type QuoteFrequency = "one_off" | "weekly" | "fortnightly" | "monthly";
export type QuoteFulfilment = "pickup" | "delivery" | "either";

export type QuoteOffer = {
  id: string;
  status: "pending" | "accepted" | "declined" | "withdrawn";
  farm?: { id: string; name: string; verified: boolean; rating: { average: string | null; count: number } };
  listing: { product_id: string; title: string; variant_id: string; label: string | null; unit: Unit };
  currency: string;
  unit_price: string;
  quantity: string;
  fulfilment: "pickup" | "delivery";
  delivery_fee: string;
  total: string;
  message: string | null;
  valid_until: string | null;
  can_order_again: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type QuoteRequest = {
  id: string;
  title: string;
  category: { id: string; name: string };
  quantity: string;
  unit: Unit;
  frequency: QuoteFrequency;
  frequency_label: string;
  needed_by: string;
  fulfilment: QuoteFulfilment;
  province: string;
  district: string;
  notes: string | null;
  status: "open" | "awarded" | "closed" | "expired";
  closes_at: string;
  buyer?: { name: string };
  offers_count?: number;
  offers?: QuoteOffer[];
  created_at: string | null;
};

export const FREQUENCIES: { value: QuoteFrequency; label: string }[] = [
  { value: "one_off", label: "Just once" },
  { value: "weekly", label: "Every week" },
  { value: "fortnightly", label: "Every two weeks" },
  { value: "monthly", label: "Every month" },
];

export const quoteStatus: Record<QuoteRequest["status"], { label: string; tone: "wait" | "good" | "plain" }> = {
  open: { label: "Taking offers", tone: "wait" },
  awarded: { label: "Offer accepted", tone: "good" },
  closed: { label: "Closed", tone: "plain" },
  expired: { label: "Closed for offers", tone: "plain" },
};

/** "Every week, from Fri 16 Oct", or "Needed by Sun 18 Oct" for a one-off. */
export const scheduleText = (request: QuoteRequest, date: string) =>
  request.frequency === "one_off" ? `Needed by ${date}` : `${request.frequency_label.charAt(0).toUpperCase()}${request.frequency_label.slice(1)}, from ${date}`;

const quotesKey = [...meKey, "quote-requests"] as const;

export function useQuoteRequests() {
  const { signedIn } = useSession();
  return useQuery({
    queryKey: [...quotesKey, "list"],
    queryFn: ({ signal }) => api<Paginated<QuoteRequest>>("/quote-requests", { signal }).then((response) => response.data),
    enabled: signedIn,
  });
}

/** A request with its offers; checked every 30 seconds while it is open, so new offers appear. */
export function useQuoteRequest(id: string) {
  return useQuery({
    queryKey: [...quotesKey, "detail", id],
    queryFn: ({ signal }) => api<{ data: QuoteRequest }>(`/quote-requests/${id}`, { signal }).then((response) => response.data),
    refetchInterval: (query) => (query.state.data?.status === "open" ? 30_000 : false),
  });
}

export type NewQuoteRequest = {
  category_id: string;
  title: string;
  quantity: string;
  unit: string;
  frequency: QuoteFrequency;
  needed_by: string;
  fulfilment: QuoteFulfilment;
  province: string;
  district: string;
  notes?: string;
  open_days: number;
};

export function useQuoteActions() {
  const queryClient = useQueryClient();
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: quotesKey });
    queryClient.invalidateQueries({ queryKey: [...meKey, "orders"] });
  };
  const order = (offerId: string, action: "accept" | "order", body: { address_id?: string | null; note?: string }) =>
    api<{ data: BuyerOrder }>(`/quote-offers/${offerId}/${action}`, { method: "POST", body }).then((response) => response.data);

  return {
    /** Resolves to the request and how many farms were told about it. */
    create: useMutation({
      mutationFn: (request: NewQuoteRequest) =>
        api<{ data: QuoteRequest; meta: { farms_told: number } }>("/quote-requests", { method: "POST", body: request }).then((response) => ({
          request: response.data,
          farmsTold: response.meta.farms_told,
        })),
      onSuccess: refresh,
    }),
    close: useMutation({
      mutationFn: (id: string) => api(`/quote-requests/${id}/close`, { method: "POST" }),
      onSuccess: refresh,
    }),
    decline: useMutation({
      mutationFn: (offerId: string) => api(`/quote-offers/${offerId}/decline`, { method: "POST" }),
      onSuccess: refresh,
    }),
    accept: useMutation({
      mutationFn: ({ offerId, ...body }: { offerId: string; address_id?: string | null; note?: string }) => order(offerId, "accept", body),
      onSuccess: refresh,
    }),
    orderAgain: useMutation({
      mutationFn: ({ offerId, ...body }: { offerId: string; address_id?: string | null; note?: string }) => order(offerId, "order", body),
      onSuccess: refresh,
    }),
  };
}
