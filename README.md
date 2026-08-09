# CafeTrack

Gaming cafe management SaaS — **Laravel 12 + MySQL 8**, rebuilt from the
FastAPI + PostgreSQL reference implementation.

A cafe rents PS5s, PCs and consoles by the hour. Players scan a QR sticker on
the booth and start their own session from their phone; staff watch a live
dashboard, end sessions, take payment and reconcile the drawer; the owner gets
analytics. **One deployment hosts many cafes, and each sees only its own data.**

All money is Bangladeshi Taka (৳).

---

## The two decisions

**Frontend: a React SPA served by Laravel, consuming the JSON API.**

The backend was built first as option (A) API-only, on the specification's
assurance that an existing Next.js frontend would consume it unchanged. That
frontend is not in this repository and was never available, so the app had no
usable interface — all 75 routes and no way for staff to reach them. The UI
here closes that gap.

It is option (B)-shaped — everything under one Laravel roof, React rendering
the screens — but without Inertia. Inertia wants page props from Laravel
controllers, which would mean rebuilding the query logic behind all 75 routes a
second time. The SPA instead calls the same API any other client would, so the
tested backend is reused whole and a Next.js app can still be swapped back in
later. Option (C) Blade + Livewire would have meant the same duplication with
every screen rewritten server-side.

**Seventeen screens**: the sixteen admin screens behind a fixed sidebar, plus
the public check-in page a player opens by scanning a booth's QR sticker.
**Dark by default** — a gaming cafe runs its screens in a dim room and the
product should look like it belongs on the same counter as the consoles — with
a light toggle remembered in the browser, and the guided tour.

**Auth: JWT** (HS256, via `firebase/php-jwt`) rather than Sanctum. Option (A)
is what makes this the right call: the token claims are identical to the
FastAPI reference's (`sub`, `email`, `role`, `cafe`, `tv`), so pointing
`JWT_SECRET` at the old backend's `SECRET_KEY` lets both mint tokens the other
accepts and the two can run side by side during a migration. It also keeps
`admin_users.token_version` as the real sign-out-everywhere mechanism the
schema calls for, with no extra token table.

---

## Setup

### Docker (recommended)

```bash
cp .env.example .env
php artisan key:generate            # or: docker compose run --rm app php artisan key:generate --show
docker compose up -d
```

The API is on <http://localhost:8000/api>. The stack migrates and seeds on
boot, giving you three accounts (password `password` for all three):

| Email | Role |
| --- | --- |
| `owner@cafetrack.test` | superadmin (no cafe) |
| `admin@cafetrack.test` | admin of "My Gaming Cafe" |
| `staff@cafetrack.test` | staff of "My Gaming Cafe" |

For production, `docker-compose.prod.yml` runs Caddy in front, which obtains
and renews TLS certificates automatically:

```bash
CAFETRACK_DOMAIN=cafetrack.example.com \
FRONTEND_BASE_URL=https://app.example.com \
APP_KEY=base64:… DB_PASSWORD=… \
docker compose -f docker-compose.prod.yml up -d
```

### Without Docker

Needs PHP 8.2+ and MySQL 8.

```bash
composer install
npm install && npm run build      # or `npm run dev` for hot reload
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve
```

### The screens

| | |
| --- | --- |
| **Dashboard** | A welcome header with search, then every device on the floor in a wrapping grid — no sideways scrolling, because a device hidden off the edge of a track is a device nobody notices is free. It refreshes every 5 s and pauses while the tab is hidden. A free tile carries a **Start session** button, a live one the rate *that player* is paying, a ticking timer, running cost, a meter against `planned_minutes` and **End session**. Ending shows the itemised bill — billed time, the block it rounded to, the amount rounding and any tier discount — with **To collect** in full, then offers **Print receipt** and **Download PDF** without leaving the screen. Below: a takings area chart with 7/14/30-day ranges, today's till card, busiest-devices bars, an in-play table and a **Needs attention** list of overdue play, unpaid bills and devices down. |
| **Stations** | A price per controller count — the boxes follow the controller limit, so raising it asks for the new prices — plus QR preview and PNG download. |
| **Sessions** | History, filterable by station, status and date. |
| **Invoices** | Click the status pill to settle or re-open. Expand a row for the money breakdown, item add/remove, wallet settlement and — admins only — discount and void. CSV export, and per-row **Print** and **PDF**. |
| **Products / Loyalty** | The catalogue, top-up packages and membership tiers. |
| **Customers** | Tier, visits, lifetime spend and balance; top up from a package or a custom amount, browse the wallet ledger, and (admins) correct a balance by hand. |
| **Bookings** | Upcoming reservations; Arrived turns one into a live session. |
| **Shifts** | Live totals split by method, a Drawer panel showing the expected-cash arithmetic line by line, cash in/out and close. |
| **Expenses** | The ledger of what the cafe spends, filterable by window and category, with the running total and the top categories above it. Recording in cash takes the money straight out of the open drawer; the form says so before you try. |
| **Analytics** | Income and hours charts, gross-profit tiles, utilization bars, a 7 × 24 peak-hours heatmap, and the top station and customer rankings. |
| **Summary** *(admin)* | The two summary sheets. **Daily**: takings by device type, the day's expenses and where they went by category, the drawer's own movements alongside, how the money arrived (cash / phone / wallet) and what is still unpaid. **Monthly**: every day of the month with sessions, hours, income, expenses and net, plus the device and category breakdowns. Tables, not charts — a sheet you settle up against. |
| **Logs / Staff / Settings** *(admin)* | The activity log, accounts, and billing rules with a worked example under each control. |
| **Cafes** *(owner)* | Every cafe on the platform, plus the branding. Open one, suspend one, switch between them. **Edit** opens a cafe's details and its accounts together — rename it, change its contact email, change an account's email or password, switch a role, add an account or remove one. Hidden from cafe admins and staff: there is nothing on it they can act on. |
| **Check-in** *(public)* | Phone-shaped. Controller picker showing the effective rate as it changes. If a session is already running it shows the clock and cost — and deliberately **no stop button**. |

The layout follows a trading-desk pattern. The left sidebar shows icons **and**
labels, and collapses to icons alone from the control at its foot; the choice is
remembered across reloads, and hover labels appear while it is collapsed. Below
1024px it becomes an off-canvas drawer.

The signed-in account, the cafe being worked in, what has been taken this shift
and a donut of the cash/phone/wallet split live in a card on the **Dashboard**,
beside the floor they describe — not pinned to every screen, where takings mean
nothing next to Settings or Logs.

Two notes on how the SPA treats data. **Money never becomes a JavaScript
number**: it arrives as a string and is only turned into digits for display,
because a float cannot hold every 2dp value. **Timestamps get a `Z` appended
before parsing**: the API sends naive UTC, and without it the browser would
read every time as local and be wrong by the viewer's offset.

**Printing is not the PDF.** The PDF is a file you keep or email; the receipt
goes straight to the printer beside the till while the customer is standing
there, laid out for an 80mm roll and falling back sanely on A4. It prints from a
hidden iframe rather than a popup, because a popup is the thing browsers block
and being blocked at the counter is worse than useless.

There is no routing library. Every published version of the obvious one
currently carries open advisories — none of which apply to a client-only SPA,
but all of which surface in `npm audit` — so a ~50-line History API router
stands in. `npm audit` reports **0 vulnerabilities**.

### Optional modules

The platform owner decides which optional modules a cafe gets. Each cafe card
on the **Cafes** screen carries a switch per module:

| Module | Covers |
| --- | --- |
| **Products** | Selling snacks and drinks, and adding them to a bill. |
| **Loyalty** | Top-up packages and membership tiers. |
| **Bookings** | Reserving a station for a future slot. |

Everything else is core and always on — a cafe without stations, sessions,
invoices or a cash drawer is not a cafe.

Three things worth knowing about how this is enforced:

- **Hiding the sidebar item is not the guard.** `EnsureFeature` middleware sits
  on the routes and answers **403**, so a disabled module cannot be reached by
  calling the API directly. The nav is only the presentation half — and the SPA
  guards the *route* by the same rules, because a path survives a sign-out and
  can be bookmarked or typed. Without that, a staff member landing on an admin
  path renders the screen and gets a wall of 403s instead of a plain answer.

  One function, `navAllows`, answers "may this account see this item" for both
  the sidebar and the router, so the two can never disagree about who is
  allowed where. It reads three flags on each nav entry: `superadminOnly`,
  `adminOnly` and `feature`. The first two are not interchangeable — `isAdmin`
  is true for the platform owner as well.
- **A cafe's own admin cannot grant themselves a module.** The switch is
  superadmin-only, and the grant lives in its own `cafe_features` table rather
  than in `app_settings`, which a cafe admin can write to. Otherwise an admin
  would simply switch on whatever they had not been given.
- **An absent row means enabled.** Existing cafes keep everything they already
  had, and the owner turns things *off* rather than having to grant each module
  to every cafe. Turning one back on restores it — nothing is deleted when a
  module is switched off.

It answers 403 rather than the 404 the tenancy rules use. Those exist so a
lookup cannot confirm that *another tenant's* record exists; here the caller is
asking about their own cafe, and "your plan does not include this" is the
honest, actionable answer.

### Editing a cafe

Everything about a cafe stays changeable after it is opened. **Edit** on its
card opens one dialog holding both halves: the details (name, contact email)
and the accounts that run it — change an email or password, switch a role
between admin and staff, add an account, remove one.

Two notes on how it works:

- **The slug follows the name.** It is printed under the name on every cafe
  card, so leaving it behind after a rename reads as a stale record. Nothing in
  the app looks a cafe up by it. The uniqueness check ignores the row being
  saved, or re-saving a cafe under a name it already has would collide with
  itself and walk the slug on to `-2`.
- **The accounts half uses the ordinary `/staff` routes**, with the cafe named
  on the request via `X-Cafe-Id` rather than the platform owner switching the
  whole app into it. That reuses the guards already there: the last admin
  cannot be demoted or deleted, so a cafe is never left with nobody able to
  administer it, and any email, password or role change bumps `token_version`
  and signs that person out everywhere. The header is ignored for a cafe-bound
  account, so an admin cannot reach another cafe's accounts by sending it.

### The day, and what it cost

**Opening and closing the day** is the **Shifts** screen. Open a shift with the
float in the drawer; every invoice settled while it is open belongs to it. Close
it by counting the cash, and the app shows expected against counted with the
variance between them, frozen at that moment — a void the next day cannot
rewrite a signed-off reconciliation.

```
expected_cash = opening_float + cash_sales + cash_topups + paid_in − paid_out
```

Only cash counts towards it. Phone payments and wallet spends are deliberately
excluded: that money never entered the till (wallet credit was paid for back at
top-up time, and counted in the drawer then).

**Expenses** are their own ledger, and the distinction from the drawer is the
point of it:

| | |
| --- | --- |
| `cash_movements` | reconciles the **till** — the float being topped up, the takings being banked |
| `expenses` | records what the **business spends**, including what the till never sees |

Banking the takings empties the drawer and costs the cafe nothing. Rent paid by
bank transfer costs the cafe a great deal and never touches the drawer. Neither
is expressible if the two are the same table.

The two meet at exactly one point: **an expense paid in cash writes the matching
cash movement and points at it**, so the drawer maths is unchanged and there is
still one source of truth for the till. That gives one rule a member of staff
can hold in their head:

> Paying in **cash** comes out of the open drawer — so a shift has to be open,
> and it is dated today. **Any other method** never touches the till and can be
> dated any past day, so a bill can be entered late.

Ten categories, fixed rather than free text: free text becomes "Electricity",
"electric bill" and "ELEC" inside a month, and a per-category total that adds
up to nothing. Staff record expenses — they are the ones sent out for change
and batteries — and only an admin deletes one. Deleting takes the cash movement
with it, and is **refused once the shift it came out of has been counted**: the
same rule that freezes a closed shift's `expected_cash`. Every report groups on
`spent_on`, never on `created_at`.

### Branding

The platform owner uploads a **login background** and a **logo** from the
Branding card on the **Cafes** screen, and removes either to fall back to the
built-in look — an aurora behind the sign-in form and the `CT` tile.

These are the one thing in the app that is *not* per cafe. They are what
somebody sees before they have signed in, when the app does not yet know which
cafe they belong to, so they live in their own `platform_settings` table and
only a superadmin can change them. That table also records which account last
changed each one: `audit_events` is scoped to a cafe, and a superadmin is inside
none, so there is nowhere else for the trail to go.

Four things about handling an uploaded image:

- **It never lands anywhere the web server can execute.** Files are written to
  `storage/app/private/branding` and streamed back by a controller with a
  content type we choose, `X-Content-Type-Options: nosniff` and
  `Content-Disposition: inline`. There is no writable directory inside
  `public/`, and `storage:link` is not required.
- **SVG is refused.** JPEG, PNG and WebP only, up to 5 MB. An SVG is a document
  that can carry `<script>`, and it would run under our own origin. The stored
  extension is derived from the sniffed MIME type, not the filename, so
  `logo.php.png` cannot smuggle anything past.
- **The URL changes when the image does.** The stored filename carries a random
  token. Without that, the owner uploads a new background, the browser serves
  the old one from cache, and they conclude the upload failed.
- **The login page is dark whatever theme you picked.** The background is a
  photograph we have never seen and it could be anything, so the sign-in panel
  carries its own contrast rather than borrowing the page's. A light-mode
  variant would only give a bright card a coin-flip chance against a bright
  photo.

The darkening over that photograph is **local, not a wash**. A full-width scrim
protects the text but flattens the picture, and whatever the owner uploaded
almost certainly has its subject in the middle — exactly the part a
left-to-right gradient throws away. So two soft, heavily feathered pools of
shadow sit under the two things that need one, the pitch column and the panel,
and the centre of the frame is left alone. White text sitting straight on the
image carries a tight dark halo instead of a backing slab, which survives a
bright band running through the middle of a picture without covering any of it
up. Checked against a deliberately harsh near-white upload as well as a dark
one.

The page also drifts: a 48-second, 6% pan across the background, a staggered
rise on the content, a light travelling along the panel's top edge, and a fine
CSS-generated grain that both stops a large JPEG banding across a wide screen
and ties an image nobody here chose to the rest of the product. All of it is
off under `prefers-reduced-motion`.

### Tests

```bash
php artisan test
```

**296 feature tests, all green.** They hit real HTTP routes, each against a
fresh throwaway database (SQLite in memory, so the suite runs in ~5 seconds
without a MySQL server). The migrations are written to compile identically on
both; the MySQL DDL is what the schema section below describes.

| Group | Tests |
| --- | --- |
| Billing | 46 |
| Tenancy | 27 |
| Security | 20 |
| Cafe management, staff, settings | 28 |
| Permissions | 18 |
| Analytics and the summary sheets | 33 |
| Expenses | 24 |
| Bookings | 17 |
| Shifts | 15 |
| POS | 15 |
| Wallet & loyalty | 14 |
| Optional modules | 16 |
| Branding | 20 |
| Auth | 3 |

---

## Billing

Money is never a float. It is `DECIMAL(10,2)` in the database, exact decimal
arithmetic in PHP (`brick/math`), and a **string** on the wire — `"250.00"`,
never `250.0`. `App\Support\Money` is the only place that rounds, and it always
rounds half **up**, the way a cash drawer does.

`App\Services\BillingService` implements the rules in order.

**Step 0 — the effective hourly rate, fixed at check-in.** A station carries
one hourly rate **per controller count**, in `station_rates`, and the rate is
looked up rather than computed:

```
effective_rate = station_rates[controllers]
```

```
1 pad ৳100   2 pads ৳120   3 pads ৳160   4 pads ৳200
```

This is a lookup and not `base + extra × (n − 1)` because **real price lists do
not step evenly**. The row above — taken from an actual cafe's price list —
adds ৳20 for the second pad and ৳40 for the third and fourth, and no single
"extra controller" figure reproduces it. The flat model that used to be here
could express one of that cafe's four device types.

Saving a station writes a row for every count from 1 to its `max_controllers`,
so a lookup can never miss; the API refuses a price list with a hole in it, or
one carrying a count above the maximum. `stations.hourly_rate` is the
1-controller price, kept in step with the table because it is the headline
figure on the floor and on the QR page. Asking for more controllers than the
station takes is a **400** that names the limit.

The rate is **snapshotted onto the session** (`hourly_rate_snapshot`,
`base_rate_snapshot`, `controllers`), so a later price change never alters a
session already running or an invoice already issued. That snapshot — not the
station's headline rate — is what an occupied tile on the floor shows, because
the two differ the moment somebody picks up a second pad.

`GET /sessions/{id}/quote` runs this whole pipeline **without writing
anything**, which is what the End-session dialog reads. Quote and checkout go
through one private `price()` method, so the figure the operator reads out at
the counter is the figure that gets charged — agreeing by construction rather
than by two code paths happening to do the same arithmetic. The quote is
itemised (billed time and the block it was rounded to, gross, the rounding
adjustment and the step it used, any tier discount) so the operator can say
*why*, not just assert a total.
`extra_controller_rate_snapshot` is still written — what each pad past the first
worked out at — but nothing bills from it; it is there so rows written under the
old flat model stay comparable.

**Step 1 — time rounds up to a whole block.**

```
actual_minutes = floor((end − start) / 60)
billed_minutes = max(1, ceil(actual_minutes / block)) × block
```

A 3-minute session on a 15-minute block bills as 15. A block of `1` means
per-minute billing.

**Step 2 — money rounds to the nearest step.**

```
gross = round_half_up(billed_minutes / 60 × effective_rate, 2)
total = round_half_up(gross / step, 0) × step
if gross > 0 and total <= 0: total = step
```

৳202 → ৳200, ৳400.56 → ৳400, ৳102.50 → ৳105. That last line is the one that
matters: **a nonzero bill never rounds away to free.**

**Step 3 — the loyalty discount** comes off that total.

**The live "cost so far"** on the dashboard uses exact minutes with **neither**
rounding step. Both apply only when the session ends.

`block` and `step` come from `app_settings`, keyed on `(cafe_id, key)`, so two
cafes genuinely hold different billing rules.

---

## Roles

| | superadmin | admin | staff |
| --- | :-: | :-: | :-: |
| Belongs to a cafe | no (`cafe_id` NULL) | one | one |
| Create / suspend cafes | ✅ | — | — |
| Start, end and cancel sessions | ✅ | ✅ | ✅ |
| Add and remove invoice items | ✅ | ✅ | ✅ |
| Mark an **unpaid** invoice **paid** | ✅ | ✅ | ✅ |
| Wallet top-ups | ✅ | ✅ | ✅ |
| Shifts, cash in/out | ✅ | ✅ | ✅ |
| Bookings | ✅ | ✅ | ✅ |
| Change an invoice's payment method | ✅ | ✅ | ❌ |
| Revert **paid → unpaid** | ✅ | ✅ | ❌ |
| Discounts, voids, manual balance adjustments | ✅ | ✅ | ❌ |
| Stations, products, packages, tiers | ✅ | ✅ | ❌ |
| Settings, staff accounts, activity log | ✅ | ✅ | ❌ |

Two details worth stating plainly:

- **A superadmin passes every admin check once they have selected a cafe.**
  Otherwise the platform owner could open a tenant but not fix anything in it.
- **Staff may choose cash or phone when settling an invoice** — picking the
  method *is* part of marking it paid. What they cannot do is re-open the
  question afterwards on an invoice that is already paid.

The last admin in a cafe cannot be demoted or deleted.

---

## Multi-tenancy

Every operational table carries `cafe_id`, and every query filters on it.
`App\Support\Tenancy\CafeContext` is the single place that knows which cafe a
request is acting on:

- `scope(Model::class)` starts a query already narrowed to the active cafe.
- `find(Model::class, $id)` is **"find by id within this cafe, else 404"** —
  the helper every by-id route goes through.

The rules it enforces:

1. **An admin/staff token is bound to its own cafe.** Any `X-Cafe-Id` the
   client sends is ignored outright; a tenant cannot widen its own reach.
2. **A superadmin names a cafe per request** with `X-Cafe-Id`. Missing header →
   **400**. Unknown id → **404**. Never a silent default to cafe 1.
3. **Another tenant's record answers 404, not 403.** A 403 would confirm the
   record exists, which is itself a leak.
4. **`admin_users.cafe_id` is nullable with no default.** This is a real bug
   from the reference implementation — the ORM applied a column default over an
   explicit NULL and the platform owner appeared in a cafe's staff list. There
   is a test named after it.
5. Login emails are unique **platform-wide**, so a sign-in is never ambiguous.
6. Logging in to a suspended cafe is refused.
7. `app_settings` is keyed on `(cafe_id, key)`.
8. The same phone number in two cafes is two separate customers, and two cafes
   can book the same slot.

One more, which the specification does not ask for but the guarantee needs:
`ResetRequestState` clears the memoised auth guard and the scoped cafe at the
start of every API request. Laravel's `RequestGuard` caches its resolved user
and never clears it when the request is swapped — invisible under PHP-FPM,
where each request gets a fresh application, but under Octane, a queue worker
or the test suite a second request can otherwise inherit the first one's
tenant. For an app whose central promise is tenant isolation, that is not worth
leaving to the process model.

---

## Money-adjacent invariants

- `total_amount = session_amount + items_amount − discount_amount`, re-established
  after every mutation.
- **Voiding keeps the invoice** and excludes it from all revenue, analytics and
  lifetime spend. A wallet-paid invoice refunds the balance and writes a
  `refund` row.
- **Every** change to `customers.balance` writes a `wallet_transactions` row
  with the signed amount and the resulting `balance_after`. The ledger is the
  audit trail; the balance is a cache of it.
- **Lifetime spend is computed**, never stored, from paid non-void invoices, so
  it cannot drift when an invoice is voided.
- Line items snapshot **both** `unit_price` and `unit_cost`, so profit reports
  stay correct after supplier prices change.
- A product that has been sold, or a station with history, is **retired**
  (`is_active = false`), not deleted.

### The cash drawer

An invoice records `paid_at` and the `shift_id` that **collected** it — takings
land in the shift that took the money, not the one that started the session.

```
expected_cash = opening_float + cash_sales + cash_topups + paid_in − paid_out
variance      = counted_cash − expected_cash
```

**Only cash touches the drawer.** Phone payments and wallet spends are excluded
on purpose: that money never entered the till (wallet credit was paid for, and
counted, back at top-up time).

Closing **freezes** `expected_cash` and `variance` onto the row, so a void the
next day cannot rewrite a reconciliation someone has already signed off.

---

## Security

**Tokens** carry `sub`, `email`, `role`, `cafe` (nullable), `tv` and `exp`
(12 hours). `admin_users.token_version` is bumped on a password change, a role
change or a forced sign-out, and **every request compares the `tv` claim
against the stored value** — that is how one click signs a user out of every
device. Passwords are bcrypt, so hashes from the reference implementation
import unchanged and nobody has to reset a password.

**Signed QR codes.** Station ids are sequential, so a bare `/checkin/{id}` link
is guessable. Every QR encodes
`{FRONTEND_BASE_URL}/checkin/{id}?t={token}` where the token is the first 16
hex characters of `HMAC-SHA256(APP_KEY, "station:{id}")`, compared in constant
time. With `REQUIRE_QR_TOKEN=true` an anonymous check-in without a valid token
is **403**. Signed-in staff bypass it — they start sessions from the dashboard,
where there is no code to scan.

**Rate limits** are per client IP over a one-minute window, honouring
`X-Forwarded-For`: check-in 10/min, login 10/min, public station lookup
120/min. `0` disables a limit. Exceeding one returns **429** with `Retry-After`.

**The public station endpoint never leaks a phone number** — only the
customer's name.

---

## Schema

15 tables plus `app_settings`, `cafe_features`, `platform_settings`,
`station_rates` and `expenses`. All
money `DECIMAL(10,2)`, `discount_percent` `DECIMAL(5,2)`, all timestamps naive
UTC `DATETIME`, everything `utf8mb4_unicode_ci` (customer names and notes
contain Bangla text).

```
cafes  admin_users  stations  customers  sessions  invoices  invoice_items
products  packages  membership_tiers  wallet_transactions  bookings
shifts  cash_movements  audit_events  app_settings  cafe_features
platform_settings  station_rates  expenses
```

Indexed on `cafe_id` everywhere it exists, plus `sessions.station_id`,
`sessions.status`, `bookings.station_id`, and a **unique** `invoices.session_id`
— ending a session creates exactly one invoice.

`invoice_items` and `cash_movements` deliberately carry no `cafe_id`; they are
scoped through their invoice and shift respectively.

`app_settings` is a composite primary key on `(cafe_id, key)`. `key` is a MySQL
reserved word and is quoted accordingly.

`station_rates` is a composite primary key on `(station_id, controllers)` and
cascades on delete — a price list has no meaning without its station.

`expenses` is indexed on `(cafe_id, spent_on)`, which is what every report
groups by. Its `shift_id` and `cash_movement_id` are nullable and set together:
both present means the money came out of a drawer, both null means it never
touched the till.

`platform_settings` is the one table with no `cafe_id` at all — it holds the
branding a visitor sees before they have signed in, when there is no cafe to
scope it to. Its primary key is `key` alone.

---

## Status codes

| | |
| --- | --- |
| **400** | bad state (double-book, too many controllers, station out of service, insufficient wallet balance, missing `X-Cafe-Id`) |
| **401** | unauthenticated |
| **403** | wrong role, or an unsigned anonymous check-in |
| **404** | missing **or another tenant's** |
| **409** | conflict (second check-in on a busy station, overlapping booking, already-closed session or shift) |
| **422** | validation |
| **429** | rate limited |

Creates return **201**; deletes return **204**.

---

## Deviations from the specification

Three, all small, all deliberate:

1. **`brick/math` instead of the bcmath extension.** The specification allows
   "BCMath or a decimal library". The build environment could not install the
   PHP extension, so the decimal library does the arithmetic; it uses bcmath
   automatically when present, and the Docker image installs it for speed.
   Money is exact decimal either way, and never a float.

   It is pinned to `^0.14` deliberately. Laravel 12 accepts `^0.11` upwards,
   but `RoundingMode` only became an enum in 0.14 — on an older resolution the
   billing code's `RoundingMode::HalfUp` would silently be an undefined
   constant. The pin is what keeps `composer update` from rounding your money
   differently.

2. **`GET /api/shifts/current` returns a literal JSON `null`** when no shift is
   open. Laravel's `response()->json(null)` emits `{}`, because Symfony swaps a
   null payload for an empty object, and the client needs to tell "no shift
   open" apart from "a shift with no fields".

3. **`ResetRequestState` middleware**, described under multi-tenancy above. It
   is not in the specification; it closes a tenant-isolation hole that only
   appears when the container outlives the request.

4. **The frontend is a React SPA rather than one of the three listed options**,
   for the reason given at the top: option (A)'s existing Next.js app was not
   available, and (B)'s Inertia layer would have duplicated the whole API.

5. **No webfont.** The build fetched one from a CDN at build time, which fails
   behind a proxy and in an offline CI. A system stack replaces it, with
   `Noto Sans Bengali` in the list so Bangla customer names render.

Both dependency audits are clean: `composer audit` and `npm audit` each report
no advisories. `firebase/php-jwt` is on `^7.1` for that reason — everything
below 7.0 carries CVE-2025-45769.

The backend test count is **207** rather than the reference's 109 — the same
groups, covered a little more thickly, plus a group for cafe onboarding and
catalogue lifecycle that the reference folds into its other suites.

**The frontend has no automated tests.** It was verified by driving the real
app in Chromium: signing in, loading all fourteen admin screens against seeded
data with no console errors, and running a full session end to end — a signed
QR check-in on a phone viewport, the occupied card appearing on the dashboard,
ending the session with payment, and the resulting invoice breakdown. That is a
manual check, not a suite; a regression here would not be caught automatically.
