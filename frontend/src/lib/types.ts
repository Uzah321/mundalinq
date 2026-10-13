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

export type FarmLocation = {
  id: string;
  name: string | null;
  province: string;
  district: string;
  ward: string | null;
  /** Only in staff and farm-member responses. */
  address_line: string | null;
  latitude: number | string;
  longitude: number | string;
  is_primary: boolean;
};

export type FarmerType = "smallholder" | "commercial" | "cooperative" | "supply_agent";
export type VerificationStatus = "unverified" | "pending" | "approved" | "rejected";

/** FarmResource. */
export type Farm = {
  id: string;
  name: string;
  description: string | null;
  farmer_type: FarmerType;
  contact_phone: string | null;
  contact_email: string | null;
  offers_pickup: boolean;
  offers_delivery: boolean;
  delivery_radius_km: number | null;
  verification_status: VerificationStatus;
  verified: boolean;
  verified_at: string | null;
  primary_location?: FarmLocation | null;
  locations?: FarmLocation[];
  created_at: string | null;
};

export type VerificationDocument = {
  id: string;
  type: "identity" | "business_registration" | "land_proof" | "other";
  original_name: string;
  mime_type: string;
  size_bytes: number;
};

/** Admin\FarmVerificationResource: a reviewer's view of a submission. */
export type FarmVerification = {
  id: string;
  status: Exclude<VerificationStatus, "unverified">;
  id_document_type: "national_id" | "passport";
  id_number: string;
  business_registration_number: string | null;
  notes: string | null;
  rejection_reason: string | null;
  farm?: Farm;
  submitted_by?: User;
  reviewed_by?: { id: string; name: string } | null;
  documents?: VerificationDocument[];
  submitted_at: string | null;
  reviewed_at: string | null;
};

/** Admin\FarmResource: a farm with its owners' contact details, for staff. */
export type AdminFarm = Farm & {
  owners?: { id: string; name: string; phone: string; phone_verified: boolean; email: string | null }[];
  listings_count?: number;
};

// Staff views (backend/app/Http/Resources/Admin and the shared resources staff read).

/** Admin\UserResource. */
export type AdminUser = {
  id: string;
  name: string;
  phone: string;
  phone_verified: boolean;
  email: string | null;
  status: "active" | "suspended";
  suspended_at: string | null;
  suspension_reason: string | null;
  roles?: string[];
  farms?: { id: string; name: string; role: string; verification_status: VerificationStatus }[];
  orders_count?: number;
  last_login_at: string | null;
  created_at: string | null;
};

export type ListingStatus = "draft" | "pending_review" | "active" | "paused" | "rejected";

/** ManagedProductResource: a listing with its status and stock, as its farm and staff see it. */
export type ManagedListing = ProductDetail & {
  status: ListingStatus;
  rejection_reason: string | null;
  reviewed_at: string | null;
  expires_at: string | null;
  is_expired: boolean;
  stock: { unit: string; on_hand: string; reserved: string; available: string; low_stock_threshold: string; is_low: boolean };
  cover_image?: ProductImage | null;
  created_at: string | null;
};

export type OrderItem = {
  id: string;
  product_id: string | null;
  title: string;
  label: string | null;
  unit: Unit;
  quantity: string;
  confirmed_quantity: string | null;
  unit_price: string;
  line_total: string;
};

/** BuyerSellerOrderResource: one farm's part of an order. */
export type SellerOrder = {
  id: string;
  number: string;
  status: string;
  farm: { id: string; name: string; contact_phone: string | null };
  fulfilment_method: "pickup" | "delivery";
  items?: OrderItem[];
  subtotal: string;
  delivery_fee: string;
  total: string;
  rejection_reason: string | null;
  delivery_address: { recipient_name?: string; phone?: string; address_line?: string; district?: string; province?: string } | null;
  accepted_at: string | null;
  completed_at: string | null;
  dispute?: { id: string; status: string } | null;
  review?: { id: string; rating: number } | null;
  /** Buyer view only: the code the buyer gives at handover, once there is something to hand over. */
  handover_code?: string;
  /** Buyer view only, once paid: where to collect. */
  pickup_locations?: { name: string | null; province: string; district: string; ward: string | null; address_line: string | null; latitude: string; longitude: string }[];
  confirm_by?: string | null;
  ready_at?: string | null;
  dispatched_at?: string | null;
  delivered_at?: string | null;
  /** Until then the buyer can report a problem with a delivered order. */
  report_problem_until?: string;
};

export type Payment = {
  id: string;
  order_id: string;
  status: "pending" | "succeeded" | "failed" | "expired";
  method: string;
  provider: string;
  amount: string;
  currency: string;
  payer_phone: string | null;
  failure_reason: string | null;
  paid_at: string | null;
  refunded: string;
  requires_refund?: boolean;
  created_at: string | null;
};

export type Refund = {
  id: string;
  payment_id: string;
  order_id: string;
  seller_order_id: string | null;
  dispute_id: string | null;
  amount: string;
  currency: string;
  status: "pending" | "succeeded" | "failed";
  provider_reference: string | null;
  failure_reason: string | null;
  completed_at: string | null;
  created_at: string | null;
};

/** Admin\OrderResource. */
export type AdminOrder = {
  id: string;
  number: string;
  status: "awaiting_confirmation" | "awaiting_payment" | "paid" | "completed" | "cancelled";
  cancellation_reason: string | null;
  buyer: { id: string; name: string; phone: string };
  currency: string;
  subtotal: string;
  delivery_fee: string;
  total: string;
  note: string | null;
  seller_orders?: SellerOrder[];
  payments?: Payment[];
  refunds?: Refund[];
  history?: { seller_order_id: string | null; from: string | null; to: string; actor: string | null; note: string | null; at: string | null }[];
  paid_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  created_at: string | null;
};

/** DisputeResource: a reported problem with a delivered order. */
export type Dispute = {
  id: string;
  status: "open" | "resolved" | "withdrawn";
  reason: string;
  description: string | null;
  currency: string;
  requested_refund: string | null;
  resolution: "refund_full" | "refund_partial" | "no_refund" | null;
  refund_amount: string | null;
  resolution_note: string | null;
  seller_order: { id: string; number: string; total: string };
  farm: { id: string; name: string };
  buyer: { name: string };
  messages?: { id: string; party: "buyer" | "farm" | "staff"; author: { name: string }; body: string; created_at: string | null }[];
  evidence?: { id: string; party: "buyer" | "farm" | "staff"; url: string; created_at: string | null }[];
  refunds?: Refund[];
  resolved_at: string | null;
  withdrawn_at: string | null;
  created_at: string | null;
};

export type PayoutAccount = {
  method: string;
  provider: string | null;
  account_name: string;
  account_number_last4: string;
  on_hold_until: string | null;
};

export type PayoutDue = { farm: { id: string; name: string }; available: string; currency: string; payout_account: PayoutAccount | null };

export type Payout = {
  id: string;
  farm?: { id: string; name: string };
  amount: string;
  currency: string;
  method: string;
  provider: string | null;
  account_name: string | null;
  account_number_last4: string | null;
  reference: string;
  note: string | null;
  paid_at: string | null;
};

/** ReviewResource with the staff-only fields. */
export type AdminReview = {
  id: string;
  rating: number;
  comment: string | null;
  buyer: { name: string };
  farm: { id: string; name: string };
  products?: { id: string; title: string }[];
  farm_response: string | null;
  created_at: string | null;
  hidden_at?: string | null;
  hidden_reason?: string | null;
};

export type AuditEntry = {
  id: string;
  action: string;
  actor: { id: string; name: string } | null;
  subject_type: string | null;
  subject_id: string | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string | null;
};

/** The pilot numbers over a period and the size of every staff work queue. */
export type Dashboard = {
  period: { days: number; from: string; to: string };
  currency: string;
  gmv: string;
  orders: { placed: number; paid: number; completed: number; cancelled: number; fulfilment_rate: string | null; cancellation_rate: string | null };
  revenue: { platform_fees: string; refunds: string };
  buyers: { active: number; repeat_rate: string | null; new_accounts: number };
  sellers: { active_verified: number; new_farms: number };
  queues: {
    farm_verifications: number;
    listings_to_review: number;
    open_disputes: number;
    payments_to_refund: number;
    failed_refunds: number;
    farms_awaiting_payout: number;
  };
};

/** OrderResource: an order as its buyer sees it. */
export type BuyerOrder = {
  id: string;
  number: string;
  status: "awaiting_confirmation" | "awaiting_payment" | "paid" | "completed" | "cancelled";
  cancellation_reason: string | null;
  currency: string;
  subtotal: string;
  delivery_fee: string;
  total: string;
  note: string | null;
  payment_due_at: string | null;
  seller_orders?: SellerOrder[];
  latest_payment?: Payment | null;
  paid_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  created_at: string | null;
};

/** PaymentResource while it waits for the buyer: what to tell them. */
export type PaymentWithAction = Payment & { next_action: { type: string; message?: string } | null };

export type Conversation = {
  id: string;
  farm: { id: string; name: string };
  buyer: { name: string };
  last_message?: { body: string; side: "buyer" | "farm" };
  unread_count?: number;
  last_message_at: string | null;
};

export type ChatMessage = {
  id: string;
  conversation_id: string;
  body: string;
  side: "buyer" | "farm";
  sender: { name: string };
  from_me: boolean;
  product: { id: string; title: string | null } | null;
  seller_order: { id: string; number: string | null } | null;
  created_at: string | null;
};

export type AppNotification = {
  id: string;
  category: string;
  title: string | null;
  body: string | null;
  context: { order_id?: string; seller_order_id?: string; farm_id?: string; conversation_id?: string; dispute_id?: string; product_id?: string };
  read_at: string | null;
  created_at: string | null;
};
