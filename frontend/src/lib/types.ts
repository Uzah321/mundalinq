// Shapes of the MundaLinq API (backend/app/Http/Resources). Prices and quantities are decimal strings.

export type QualityGrade = "A" | "B" | "C";

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

export type Category = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  parent_id: string | null;
  requires_approval: boolean;
  is_active: boolean;
  children?: Category[];
};

export type Unit = {
  code: string;
  name: string;
  plural_name: string;
};

export type UnitDefinition = Unit & {
  measure: string;
  allows_fraction: boolean;
};

export type ProductImage = {
  id: string;
  url: string;
  thumb_url: string;
  width: number | null;
  height: number | null;
  position: number;
};

/** ProductCardResource: a listing in search results. */
export type ProductCard = {
  id: string;
  title: string;
  category: { name: string; slug: string };
  quality_grade: QualityGrade | null;
  currency: string;
  from_price: { price: string; unit: string; unit_name: string } | null;
  available_quantity: string;
  stock_unit: string;
  cover_image?: ProductImage | null;
  farm: { id: string; name: string; verified: boolean };
  location: { province: string; district: string };
  distance_km?: number;
  offers_pickup: boolean;
  offers_delivery: boolean;
  last_harvested_on: string | null;
};

export type PriceTier = { min_quantity: string; price: string };

export type Variant = {
  id: string;
  unit: Unit;
  label: string | null;
  base_quantity: string;
  price: string;
  price_tiers: PriceTier[];
  min_order_quantity: string;
  max_order_quantity: string | null;
  available_quantity: string;
  is_active: boolean;
};

/** ProductResource: the full listing as buyers see it. */
export type ProductDetail = {
  id: string;
  title: string;
  description: string | null;
  category: { id: string; name: string; slug: string };
  quality_grade: QualityGrade | null;
  currency: string;
  stock_unit: UnitDefinition;
  available_quantity: string;
  variants: Variant[];
  images?: ProductImage[];
  farm: { id: string; name: string; verified: boolean; contact_phone: string | null };
  location: { province: string; district: string; ward: string | null };
  offers_pickup: boolean;
  offers_delivery: boolean;
  delivery_radius_km: number | null;
  last_harvested_on: string | null;
  published_at: string | null;
};

export type Problem = {
  code: string;
  message: string;
  farm_id?: string | null;
  cart_item_id?: string | null;
};

export type CartLine = {
  cart_item_id: string;
  variant_id: string;
  product_id: string;
  title: string;
  label: string | null;
  unit: Unit;
  thumb_url: string | null;
  quantity: string;
  unit_price: string;
  line_total: string;
  available_quantity: string;
  problem: Problem | null;
};

export type CartSeller = {
  farm: { id: string; name: string };
  fulfilment_method: "pickup" | "delivery" | null;
  available_methods: ("pickup" | "delivery")[];
  items: CartLine[];
  subtotal: string;
  delivery_fee: string;
  total: string;
  problems: Problem[];
};

/** QuoteResource: the cart, grouped by farm and priced by the server. */
export type Cart = {
  currency: string;
  sellers: CartSeller[];
  subtotal: string;
  delivery_fee: string;
  total: string;
  problems: Problem[];
  can_place_order: boolean;
};

export type User = {
  id: string;
  name: string;
  phone: string;
  phone_verified: boolean;
  email: string | null;
  email_verified: boolean;
  status: string;
  locale: string | null;
  roles?: string[];
  created_at: string | null;
};

export type AuthResponse = {
  token: string;
  token_type: "Bearer";
  expires_at: string | null;
  user: User;
};

export type Address = {
  id: string;
  label: string | null;
  recipient_name: string;
  phone: string;
  province: string;
  district: string;
  ward: string | null;
  address_line: string;
  directions: string | null;
  latitude: string;
  longitude: string;
  is_default: boolean;
};
