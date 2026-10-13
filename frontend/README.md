# MundaLinq web

Buyer storefront for the MundaLinq marketplace, built on the "farmly" design: React 19, TanStack Router and Query,
Tailwind v4 and shadcn/ui. It talks to the Laravel API in `../backend` (`/api/v1`).

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

`npm run build` typechecks and writes a static site to `dist/`. In production set `VITE_API_URL`
(see `.env.example`); the API already allows cross-origin requests on `api/*`.

## What's wired to the API

| Screen | Endpoints |
|---|---|
| Home: categories, search, sort/filter, "shopping near" | `GET /categories`, `GET /products` (`q, category, sort, fulfilment, province, lat/lng/radius_km`) |
| Product dialog: selling options, bulk tiers, quantity rules | `GET /products/{id}`, `GET /units` |
| Basket (cards and basket dialog) | `GET/DELETE /cart`, `POST/PATCH/DELETE /cart/items` |
| Account: sign in, create account, verify phone, sign out | `/auth/*`, `GET /me`, `GET /me/addresses` |

Not built yet: checkout, orders and payments (the basket says so), and the farmer side.
Favourites and the chosen shopping area are stored in the browser; the API has no endpoint for them yet.

## Layout

- `src/routes/index.tsx`: the storefront page; filters live in the URL (`?q=&category=&sort=&product=`).
- `src/components/store/`: header, banner, cards and the dialogs.
- `src/components/ui/`: shadcn components, unchanged from the design export.
- `src/lib/`: API client (`api.ts`), API types, money and quantity helpers, query hooks.
- `src/styles.css`: the design's tokens and styles; MundaLinq additions are grouped at the end.

## Photos

- `src/assets/hero.jpg` (or .png/.webp) is the banner photo. Without it the banner shows an illustration. The stylesheet fades its left edge into the banner, so leave plain space on the left of the photo.
- `src/assets/categories/<slug>.jpg` replaces a category's icon. Vegetables, fruit and eggs & dairy have photos; the other categories still show icons. Use square crops with the subject filling the frame.

Product photos come from the API (farmers upload them). The demo listings' photos are seeded from `backend/database/seeders/photos`.
