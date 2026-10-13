# MundaLinq API

Backend for the MundaLinq farmers marketplace. It is a versioned JSON API (`/api/v1`) used by the
Flutter mobile app (Android and iOS) and, later, the React admin dashboard.

Stack: Laravel 13, PHP 8.3, PostgreSQL 17, Sanctum bearer tokens.
PHP extensions: `pdo_pgsql`, `gd` (with WebP) and `exif` for photo processing.

## Local setup

```bash
composer install
cp .env.example .env        # then set DB_PASSWORD
php artisan key:generate
php artisan storage:link    # serves product photos from storage/app/public
php artisan migrate --seed  # categories everywhere; demo accounts and listings locally (password: "password")
php artisan serve
```

Photo URLs are built from `APP_URL`, so set it to an address the phone can reach
(for example `http://10.0.2.2:8000` from the Android emulator, or your computer's LAN IP).

Databases: `mundalinq` (development) and `mundalinq_test` (used by `php artisan test`).

Demo data: seven farms around Zimbabwe, all owned by `farmer@mundalinq.test`, with about 40 listings across every
category. `php artisan db:seed --class=DemoListingSeeder` adds any that are missing to an existing database.

SMS: with `SMS_DRIVER=log`, verification codes are written to `storage/logs/laravel.log` instead of being sent.
That includes the sign-in code for the demo super admin (`admin@mundalinq.test`), since staff sign in in two steps.

## Authentication (mobile)

Every request sends `Authorization: Bearer <token>`. Each sign-in creates one token per device, so users can
see and sign out their devices. Tokens expire after `MUNDALINQ_TOKEN_TTL_DAYS` (default 90).

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/api/v1/auth/register` | `name, phone, email?, password, password_confirmation, role (buyer\|farmer\|business_buyer), device_name`. Sends an SMS code. |
| POST | `/api/v1/auth/login` | `login` (phone or email), `password`, `device_name`. Staff get `202` with a `challenge` instead of a token (below) |
| POST | `/api/v1/auth/two-factor` | Staff only: `challenge, code` (the SMS code) → token |
| POST | `/api/v1/auth/phone/send-code` | Resend verification code (1/min, 5/hour) |
| POST | `/api/v1/auth/phone/verify` | `code` |
| POST | `/api/v1/auth/password/forgot` | `phone` |
| POST | `/api/v1/auth/password/reset` | `phone, code, password, password_confirmation`; signs out all devices |
| POST | `/api/v1/auth/logout` | Signs out this device |
| GET | `/api/v1/auth/sessions` | Signed-in devices |
| DELETE | `/api/v1/auth/sessions/{id}` | Sign out another device |
| GET / PATCH | `/api/v1/me` | Profile (`name, email, locale`) |

Phone numbers are stored in E.164; local numbers like `0771234567` are read as Zimbabwean (`+263`).

**Staff sign-in.** Super admins, admins and support agents confirm every sign-in with an SMS code: login returns
`202 {"two_factor_required": true, "challenge": "..."}` and texts a code; post both to `/auth/two-factor`. The
challenge is encrypted, lasts 10 minutes and pairs with a code that works once. `MUNDALINQ_STAFF_TWO_FACTOR=false`
turns this off (for local tooling only).

## Notifications

Everything that used to be an SMS also lands in an in-app inbox, and a few more things do (new messages, reviews,
low stock). Each notification has a `category`: `orders, messages, reviews, disputes, listings, account, payouts`.
Users can turn off the SMS copy per category; the inbox always gets everything, and security codes are always sent.
SMS for messages go out only for the first unread message in a conversation.

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/api/v1/me/notifications` | Newest first; `unread=1` for unread only. `meta.unread_count` is the badge. `context` holds ids such as `order_id` |
| POST | `/api/v1/me/notifications/{id}/read` | |
| POST | `/api/v1/me/notifications/read-all` | |
| GET / PATCH | `/api/v1/me/notification-preferences` | `{"sms": {"messages": false}}`; categories left out keep their setting |

## Farms and verification

A farmer creates a farm (they become its owner), submits ID documents, and an admin approves or rejects
it. Only approved farms are visible to buyers; unapproved farms return 404 to anyone but members and staff.

| Method | Endpoint | Who |
|---|---|---|
| GET | `/api/v1/farms` | Public directory of approved farms. Filters: `q, province, district, farmer_type, per_page` |
| GET | `/api/v1/farms/{id}` | Public if approved; members/staff otherwise |
| POST | `/api/v1/me/roles` | Add `farmer`, `buyer` or `business_buyer` to your account |
| GET | `/api/v1/me/farms` | Farms you own or manage, with `my_role` |
| POST | `/api/v1/farms` | Farmer with verified phone. Includes `location {province, district, ward?, latitude, longitude}` |
| PATCH | `/api/v1/farms/{id}` | Owner or manager |
| POST / PATCH / DELETE | `/api/v1/farms/{id}/locations[/{locationId}]` | Owner or manager (max 10; primary can't be deleted) |
| GET | `/api/v1/farms/{id}/verification` | Latest submission (ID number masked, rejection reason) |
| POST | `/api/v1/farms/{id}/verification` | Owner. Multipart: `id_document_type, id_number, business_registration_number?, notes?, documents[n][type], documents[n][file]` (jpg/png/pdf, 5 MB) |
| GET | `/api/v1/admin/farm-verifications` | Admin review queue (`status=pending` by default) |
| GET | `/api/v1/admin/farm-verifications/{id}` | Full details incl. ID number and document links |
| GET | `/api/v1/admin/farm-verifications/{id}/documents/{docId}` | Decrypted document (each view is audit-logged) |
| POST | `/api/v1/admin/farm-verifications/{id}/approve` | Approves and SMSes the farmer |
| POST | `/api/v1/admin/farm-verifications/{id}/reject` | `reason` (sent to the farmer by SMS) |

Commercial farms and cooperatives must also supply a business registration number and certificate.
Verification documents are encrypted before they are written to disk, and ID numbers are encrypted in the database.

The public sees farm coordinates rounded to about 1 km and no street address; members and staff see them exactly.

## Catalog and stock

**Conventions.** Prices and quantities are decimal strings in both directions (`"30.00"`, `"0.5"`), never floats.
Money is stored in cents; quantities have three decimals. Count units (head, crate, tray, animal...) only accept
whole numbers.

**How a listing is modelled.** A product counts its stock in one *stock unit* (e.g. kg). Buyers order through
*variants* (selling options): a unit, a price and how much stock one unit uses. Selling per tonne from kg stock is
converted automatically (1000); a crate needs its contents (`base_quantity: "28"`). Variants can have bulk
`price_tiers`, each starting at a larger quantity and cheaper than the one before.

**Lifecycle.** New listings are drafts. `publish` needs a verified farm, at least one photo and one active variant.
It goes to admin review when `MUNDALINQ_REQUIRE_PRODUCT_APPROVAL=true` (default) or the category requires it
(livestock, dairy); otherwise straight live. Live listings expire after `MUNDALINQ_LISTING_TTL_DAYS` (30) unless
renewed. Renaming or recategorising a live listing sends it back to review; other edits do not.

| Method | Endpoint | Who |
|---|---|---|
| GET | `/api/v1/categories`, `/api/v1/units` | Public |
| GET | `/api/v1/products` | Public search. `q` (title, description, category or farm name), `category (slug), farm_id, province, district, grade, min_price, max_price, fulfilment (pickup\|delivery), lat, lng, radius_km, sort (newest\|nearest\|price_asc\|price_desc\|freshest), per_page`. Signed-in buyers also get `is_favourite` |
| GET | `/api/v1/products/{id}` | Public if live; farm members and staff also see status and stock |
| GET | `/api/v1/farms/{id}/products` | Farm members: every status. Filters `status, low_stock` |
| POST | `/api/v1/products` | Farm member. `farm_id, category_id, title, stock_unit, variants[], initial_stock?` and optional details |
| PATCH / DELETE | `/api/v1/products/{id}` | Farm member (delete archives) |
| POST | `/api/v1/products/{id}/publish` \| `pause` \| `resume` \| `renew` | Farm member |
| POST / PATCH / DELETE | `/api/v1/products/{id}/variants[/{variantId}]` | Farm member |
| POST | `/api/v1/products/{id}/images` | Multipart `image` (jpg/png/webp, max 10 MB / 16 MP). Stored as WebP 1600 px + 480 px thumbnail, metadata stripped |
| PUT | `/api/v1/products/{id}/images/order` | `image_ids` (cover first) |
| DELETE | `/api/v1/products/{id}/images/{imageId}` | Farm member |
| GET / POST | `/api/v1/products/{id}/batches` | Stock received: `quantity, harvested_on?, best_before?, notes?` |
| POST | `/api/v1/inventory/adjustments` | `batch_id, direction (increase\|decrease), quantity, reason, note?` |
| GET | `/api/v1/products/{id}/stock-movements` | Stock ledger, newest first |
| GET | `/api/v1/admin/products` | Admin review queue (`status=pending_review` by default) |
| POST | `/api/v1/admin/products/{id}/approve` \| `reject` | `reason` on reject (SMS to the farmer); reject also takes down live listings |
| GET / POST / PATCH | `/api/v1/admin/categories[/{id}]` | Admin. Two levels; deactivate instead of delete |

Every stock change locks the product row, updates the batch and the product total together and appends to
`inventory_movements`; the database also refuses negative stock. When an order or a write-off takes a listing down
to its `low_stock_threshold`, the farm gets one alert (in the app and by SMS), not one per order.

Listings and farms carry `rating: {average, count}`, kept up to date from visible reviews.

## Favourites, reviews and messages

**Favourites** are saved per account, so they follow the buyer between the app and the web. Paused or expired
listings stay saved but are left out of the list until they are live again.

**Reviews** are verified purchases: one per farm order, by its buyer, once the order is complete and within
`MUNDALINQ_REVIEW_WINDOW_DAYS` (30). A review counts for the farm and for every listing in that order. Buyers are
shown by first name and initial. The farm can reply once. Moderators can hide a review, which takes it out of the
listings and the ratings without deleting it.

**Messages.** One conversation per buyer and farm; every member of the farm can read and reply. A buyer starts
one with `farm_id`; either side can start one about an order with `seller_order_id`. Phone numbers are never
shared here. Writing is limited to 20 messages a minute.

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/api/v1/me/favourites` | Product cards, most recently saved first |
| PUT / DELETE | `/api/v1/me/favourites/{productId}` | Idempotent |
| POST | `/api/v1/seller-orders/{id}/review` | Buyer: `rating` (1-5), `comment?` |
| GET | `/api/v1/farms/{id}/reviews`, `/api/v1/products/{id}/reviews` | Public. `meta.summary`: average, count, and count per star |
| POST | `/api/v1/reviews/{id}/response` | Farm owner or manager: `response` |
| GET | `/api/v1/conversations` | The buyer's threads, or the farm's inbox with `farm_id`; each with `unread_count` |
| POST | `/api/v1/conversations` | `body` and `farm_id` (buyer) or `seller_order_id` (either side); `product_id?`. Reuses the existing thread |
| GET | `/api/v1/conversations/{id}/messages` | Newest first, cursor-paginated (`meta.next_cursor`) |
| POST | `/api/v1/conversations/{id}/messages` | `body` |
| POST | `/api/v1/conversations/{id}/read` | Marks everything read for the caller's side |

## Buying: cart, orders, payments

**Flow.** Cart → checkout quote → place order (stock is reserved; one *seller order* per farm) → each farm
accepts (optionally supplying less) or rejects within `MUNDALINQ_ORDER_CONFIRM_MINUTES` (240) → the buyer pays
for what was accepted within `MUNDALINQ_ORDER_PAY_MINUTES` (180) → farm marks ready → (delivery: dispatch) →
handover, proven by a 6-digit code from the buyer's app → final after the buyer confirms or
`MUNDALINQ_DISPUTE_WINDOW_HOURS` (48) pass without a reported problem, when the farm's earnings become available.
Unanswered orders, unpaid orders and abandoned payment prompts are handled by the scheduler (below). Notifications
go out at each step.

**Never trust the client.** Prices, fees and totals are recomputed on the server; placing an order needs the
`expected_total` from the quote and returns 409 with the new total if anything changed. Stock is reserved under
row locks, so simultaneous checkouts cannot oversell (`ConcurrentCheckoutTest` proves it with parallel processes).
An order is only marked paid after the payment provider confirms it.

| Method | Endpoint | Notes |
|---|---|---|
| GET / POST / PATCH / DELETE | `/api/v1/me/addresses[/{id}]` | `label, recipient_name, phone, province, district, ward?, address_line, directions?, latitude, longitude, is_default?` |
| GET / DELETE | `/api/v1/cart` | Grouped by farm; items needing attention carry a `problem` |
| POST | `/api/v1/cart/items` | `variant_id, quantity` (sets the quantity if already in the cart). Returns the cart |
| PATCH / DELETE | `/api/v1/cart/items/{id}` | Returns the cart |
| POST | `/api/v1/checkout/quote` | `address_id?, fulfilment: {farm_id: pickup\|delivery}` → totals, fees, `problems`, `can_place_order` |
| POST | `/api/v1/orders` | Quote body + `expected_total`, `note?`. Verified phone required |
| GET | `/api/v1/orders`, `/api/v1/orders/{id}` | The buyer's orders; includes handover codes once paid |
| POST | `/api/v1/orders/{id}/cancel` | Before payment, and not while a payment prompt is open |
| GET | `/api/v1/farms/{id}/orders` | The farm's incoming orders (`status` filter) |
| GET | `/api/v1/seller-orders/{id}` | Packing list for the farm, or the buyer's view |
| POST | `/api/v1/seller-orders/{id}/accept` | Optional `items: [{id, quantity}]` to supply less (0 = none) |
| POST | `/api/v1/seller-orders/{id}/reject` | `reason` |
| POST | `/api/v1/seller-orders/{id}/ready` \| `dispatch` | Dispatch is for delivery orders; stock leaves the farm then |
| POST | `/api/v1/seller-orders/{id}/handover` | `code` from the buyer (pickup: from ready; delivery: from dispatched) |
| POST | `/api/v1/seller-orders/{id}/confirm-receipt` | Buyer; makes the sale final at once |
| GET | `/api/v1/farms/{id}/earnings` | Owner only: pending and available earnings, recent ledger entries |
| POST | `/api/v1/payments/initiate` | `order_id, method: mobile_money, phone`. A pending prompt is reused, never duplicated |
| GET | `/api/v1/payments/{id}/status` | Poll while the buyer approves; each call asks the provider |
| POST | `/api/v1/webhooks/payments/{provider}` | For providers; signature-checked, replay-safe |

**Sandbox payments (development).** `PAYMENT_PROVIDER=sandbox` simulates mobile money and is disabled in
production. The payer's number decides the outcome, like Paynow's test mode:

| Number | Outcome |
|---|---|
| `0771111111` | Approved |
| `0772222222` | Approved after 30 seconds (exercise polling) |
| `0773333333` | Cancelled by the buyer |
| `0774444444` | Insufficient funds |
| any other | Never answered; expires after 15 minutes |

Sandbox webhooks are signed with `SANDBOX_WEBHOOK_SECRET`: header `X-Sandbox-Signature: t=<unix time>,v1=<hex
HMAC-SHA256 of "<t>.<raw body>">`, body `{"event_id": "...", "reference": "SBX-..."}`. A webhook only prompts a
status check with the provider; its body cannot mark anything paid.

**Paynow.** `PAYMENT_PROVIDER=paynow` sends a payment prompt to the buyer's phone through Paynow Express Checkout:
EcoCash for 077/078 numbers, OneMoney for 071 (other numbers are refused with a clear message). Settings, from a
**USD** integration in the Paynow dashboard (Receive Payments › New Integration):

```dotenv
PAYMENT_PROVIDER=paynow
PAYNOW_INTEGRATION_ID=12345
PAYNOW_INTEGRATION_KEY=...           # secret: keep it out of Git and chat
PAYNOW_AUTH_EMAIL=you@yourbusiness   # your Paynow account's email
PAYNOW_TEST_MODE=true                # until Paynow switches the integration to live
```

In test mode Paynow takes the same test numbers as the sandbox above, and the auth email must be your Paynow account's;
live, the buyer's email is sent when they have one. Every message to and from Paynow is checked against the
integration key's hash. Paynow posts updates to `/api/v1/webhooks/payments/paynow` (the `resulturl`), which must be
reachable from the internet; those only prompt a check of the payment's poll URL, and the app's status polling
settles payments even when the result URL is unreachable (e.g. on a development PC). Paynow takes no refunds through
its API: refunds show as failed under Admin › Money › Refunds for staff to send by hand and record.

**Commission.** `MUNDALINQ_COMMISSION_RATE` (default `0.05`) of each farm's goods value, not of its delivery fee.
Farms set delivery terms on their profile: `delivery_fee`, `free_delivery_over`, `delivery_radius_km`.

## Problems and refunds

After handover the buyer has `MUNDALINQ_DISPUTE_WINDOW_HOURS` (48) to report a problem; the buyer's order view
shows `report_problem_until`. Reporting moves the farm order to `disputed`, which keeps its earnings pending (the
scheduler only finalises undisputed orders). Buyer, farm and support write in the dispute and can add photos;
photos are re-encoded on upload, which strips location data, and are only served to the people in the dispute.

Support decides: `refund_full` (the whole farm order, delivery included), `refund_partial` (an amount below the
total) or `no_refund`. A refund comes out of the farm's earnings for that order, and the platform returns its fee
on the goods refunded (e.g. $5.00 of a $12.00 order returns 5/12 of the fee). The sale then completes and what is
left becomes available. The buyer can also withdraw the report, which completes the sale as normal.

Refunds go back through the payment provider once the decision is saved. One that fails stays owed: retry it, or
pay it by hand and record the reference. The same refund path clears payments that arrived for an order that
could not take them (`requires_refund`). A payment can never be refunded beyond what was paid.

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/api/v1/seller-orders/{id}/dispute` | Buyer: `reason (missing_items\|damaged\|poor_quality\|wrong_items\|other), description, requested_refund?` |
| GET | `/api/v1/disputes` | The buyer's reports, or the farm's with `farm_id` |
| GET | `/api/v1/disputes/{id}` | Buyer, farm members and staff: messages, photos, and (staff) refunds |
| POST | `/api/v1/disputes/{id}/messages` | `body` |
| POST | `/api/v1/disputes/{id}/evidence` | Multipart `photo` (jpg/png/webp), at most 6 per report |
| GET | `/api/v1/disputes/{id}/evidence/{photoId}` | The photo |
| POST | `/api/v1/disputes/{id}/withdraw` | Buyer |
| GET | `/api/v1/admin/disputes` | Support queue: open reports, oldest first (`status` filter) |
| POST | `/api/v1/admin/disputes/{id}/resolve` | Support or admin: `resolution, refund_amount` (partial only), `note` (sent to both sides) |
| GET | `/api/v1/admin/refunds` | `status=failed` lists refunds still owed |
| POST | `/api/v1/admin/refunds/{id}/retry` | Failed refunds only |
| POST | `/api/v1/admin/refunds/{id}/complete` | Paid by hand: `reference` |
| GET | `/api/v1/admin/payments` | Filters `status, requires_refund` |
| POST | `/api/v1/admin/payments/{id}/refund` | Gives back a payment the order could not use |

In development, `SANDBOX_REFUNDS_FAIL=true` makes the sandbox refuse refunds.

## Payouts and reports

Earnings become available when a sale is final. The farm owner sets where they are paid (a mobile money number,
encrypted at rest and only ever shown as its last four digits). Changing it requires a verified phone, alerts every
owner by SMS, is audited, and pauses payouts for `MUNDALINQ_PAYOUT_HOLD_HOURS` (24), so a hijacked account cannot
be emptied at once. Administrators send the money through the provider, then record the payout here; it can never
exceed the available balance.

| Method | Endpoint | Notes |
|---|---|---|
| GET / PUT | `/api/v1/farms/{id}/payout-account` | Owner. `provider` (e.g. EcoCash), `account_name`, `account_number` |
| GET | `/api/v1/farms/{id}/payouts` | Owner: payout history |
| GET | `/api/v1/farms/{id}/analytics` | Owner. `from, to` (local dates, default the last 30 days, at most a year): sales, platform fees, refunds, net; orders accepted, rejected and missed; daily series; best sellers; revenue by category; live, low and unsold stock; rating |
| GET | `/api/v1/admin/payouts/due` | Farms with an available balance, largest first, with their payout account |
| POST | `/api/v1/admin/farms/{id}/payouts` | `amount, reference, note?` |
| GET | `/api/v1/admin/payouts` | History (`farm_id` filter) |

## Administration

Three groups of staff: **support agents** work the dispute queue and can look up orders and accounts;
**admins** also review farms and listings, moderate reviews, handle payments, refunds and payouts, see the
dashboard and suspend accounts; **super admins** also grant and revoke roles and read the audit log. Suspending an
account signs it out of every device; only a super admin can suspend staff, and nobody can suspend themselves.

| Method | Endpoint | Who |
|---|---|---|
| GET | `/api/v1/admin/dashboard` | Admin. `days` (default 30): GMV, orders placed, paid, completed and cancelled, fulfilment and cancellation rates, platform fees, refunds, active and repeat buyers, active verified sellers, and every work queue |
| GET | `/api/v1/admin/orders`, `/api/v1/admin/orders/{id}` | Support. Filters `status, q` (order number or buyer phone), `farm_id, from, to`. Detail adds payments, refunds and status history |
| GET | `/api/v1/admin/users`, `/api/v1/admin/users/{id}` | Support. `q` (name, phone, email), `role, status` |
| POST | `/api/v1/admin/users/{id}/suspend` \| `reactivate` | Admin. `reason` on suspend (sent to the user) |
| POST | `/api/v1/admin/users/{id}/roles` | Super admin: `role` |
| DELETE | `/api/v1/admin/users/{id}/roles/{role}` | Super admin |
| GET | `/api/v1/admin/reviews` | Admin. Filters `hidden, farm_id, max_rating` |
| POST | `/api/v1/admin/reviews/{id}/hide` \| `unhide` | Admin. `reason` on hide |
| GET | `/api/v1/admin/audit-logs` | Super admin. Filters `action, actor_id, subject_id` |

## Scheduler

Order deadlines run every minute through Laravel's scheduler (`orders:process-deadlines`). Locally run
`php artisan schedule:work`; in production add the usual cron entry for `php artisan schedule:run`.

Errors use Laravel's standard JSON shape: `{"message": "...", "errors": {"field": ["..."]}}` with 401, 403,
404, 409, 422 or 429.
