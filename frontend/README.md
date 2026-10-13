# MundaLinq web

Buyer storefront for the MundaLinq marketplace, built on the "farmly" design: React 19, TanStack Router and Query,
Tailwind v4 and shadcn/ui, plus staff pages under `/admin`. It talks to the Laravel API in `../backend` (`/api/v1`).

## Run it

```bash
# in ../backend
php artisan serve            # API on http://127.0.0.1:8000

# here
npm install
npm run dev                  # http://localhost:5173, proxies /api to the backend
```

Demo buyer (seeded locally): `buyer@mundalinq.test` or `0770000003`, password `password`.
SMS codes for phone verification are written to `backend/storage/logs/laravel.log`.

Staff pages: http://localhost:5173/admin, as `admin@mundalinq.test`, password `password`. Staff sign-ins also ask
for a code texted to the account's phone (`+263770000001`, so it lands in the same log).

`npm run build` typechecks and writes a static site to `dist/`. In production set `VITE_API_URL`
(see `.env.example`); the API already allows cross-origin requests on `api/*`.

## What's wired to the API

| Screen | Endpoints |
|---|---|
| Home: categories, search, sort/filter, "shopping near" | `GET /categories`, `GET /products` (`q, category, sort, fulfilment, province, lat/lng/radius_km`) |
| Product dialog: selling options, bulk tiers, quantity rules | `GET /products/{id}`, `GET /units` |
| Basket (cards and basket dialog) | `GET/DELETE /cart`, `POST/PATCH/DELETE /cart/items` |
| Account: sign in (with the staff code step), create account, verify phone, sign out, links to orders and messages | `/auth/*`, `GET /me` |
| Checkout (`/checkout`): collection or delivery per farm, delivery addresses, server-priced total, place order | `GET/POST /me/addresses`, `POST /checkout/quote`, `POST /orders` |
| Orders (`/orders`, `/orders/{id}`): follow, cancel, pay by mobile money, handover code, confirm receipt, report a problem, review | `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel`, `POST /payments/initiate`, `GET /payments/{id}/status`, `POST /seller-orders/{id}/confirm-receipt`, `…/dispute`, `…/review` |
| Messages (`/messages`, `/messages/chat`): inbox and chat with farms, about an order or a listing | `GET/POST /conversations`, `GET/POST /conversations/{id}/messages`, `POST /conversations/{id}/read` |
| Notifications (`/notifications`) and the header's unread counts | `GET /me/notifications`, `POST …/read-all`, `POST …/{id}/read` |
| Admin › Overview: work queues and the last 7/30/90 days' numbers | `GET /admin/dashboard?days=` |
| Admin › Verifications: queue, ID documents, approve or reject with a reason | `GET /admin/farm-verifications`, `GET …/{id}`, `GET …/{id}/documents/{document}`, `POST …/{id}/approve`, `POST …/{id}/reject` |
| Admin › Verifications › Not sent yet: farms without documents, owner's phone, WhatsApp reminder | `GET /admin/farms?verification_status=unverified` |
| Admin › Farms: every farm, search by name, owner or phone, filter by verification | `GET /admin/farms?q=&verification_status=` |
| Admin › Listings: review queue, approve, reject or take down with a reason | `GET /admin/products?status=`, `POST …/{id}/approve`, `POST …/{id}/reject` |
| Admin › Orders: search by number or phone, full detail with payments, refunds and history | `GET /admin/orders`, `GET /admin/orders/{id}` |
| Admin › Disputes: queue, both sides' messages and photos, reply, resolve with a refund | `GET /admin/disputes`, `GET /disputes/{id}`, `POST /disputes/{id}/messages`, `POST /admin/disputes/{id}/resolve` |
| Admin › Users: search, suspend and reactivate, roles (super admins), account history | `GET /admin/users`, `GET …/{id}`, `POST …/{id}/suspend`, `POST …/{id}/reactivate`, `POST/DELETE …/{id}/roles`, `GET /admin/audit-logs?subject_id=` |
| Admin › Money: payouts due and recording them, payout history, payments to refund, failed refunds | `GET /admin/payouts/due`, `POST /admin/farms/{id}/payouts`, `GET /admin/payouts`, `GET /admin/payments`, `POST …/{id}/refund`, `GET /admin/refunds`, `POST …/{id}/retry`, `POST …/{id}/complete` |
| Admin › Reviews: hide (with a reason) and show again | `GET /admin/reviews`, `POST …/{id}/hide`, `POST …/{id}/unhide` |
| Admin › Categories: add, rename, review-first, hide | `GET/POST /admin/categories`, `PATCH /admin/categories/{id}` |
| Admin › Audit log (super admins) | `GET /admin/audit-logs?action=` |

Staff roles decide what shows: support agents see Orders, Disputes and Users; admins everything except the audit log
and changing roles, which are for super admins. The API checks the same roles on every request.

Farmers run their farm (accepting orders, handover, listings) in the mobile app; the website is for buying and for staff.
Pages refresh themselves while something can change: an open chat every 5 seconds, an order in progress every 15,
the notification count every minute. With the sandbox payment provider, 0771111111 approves a payment.
Favourites and the chosen shopping area are stored in the browser; the API has no endpoint for them yet.

## Layout

- `src/routes/index.tsx`: the storefront page; filters live in the URL (`?q=&category=&sort=&product=`).
- `src/routes/admin/`: staff pages behind a staff-only gate (`route.tsx` holds the gate and the navigation).
  ID documents and dispute photos are fetched with the sign-in token and shown from memory; every view of an ID
  document is recorded in the audit log.
- `src/components/admin/ui.tsx`: lists, tabs, search, fact lists and the confirm and "give a reason" dialogs the
  staff pages share. `src/lib/admin.ts` has the staff queries, role checks and status names.
- `src/components/store/`: header, banner, cards and the dialogs.
- `src/components/ui/`: shadcn components, unchanged from the design export.
- `src/lib/`: API client (`api.ts`), API types, money and quantity helpers, query hooks.
- `src/styles.css`: the design's tokens and styles; MundaLinq additions are grouped at the end.

## Photos

- `src/assets/hero.jpg` (or .png/.webp) is the banner photo. Without it the banner shows an illustration. The stylesheet fades its left edge into the banner, so leave plain space on the left of the photo.
- `src/assets/categories/<slug>.jpg` replaces a category's icon. Vegetables, fruit and eggs & dairy have photos; the other categories still show icons. Use square crops with the subject filling the frame.

Product photos come from the API (farmers upload them). The demo listings' photos are seeded from `backend/database/seeders/photos`.
