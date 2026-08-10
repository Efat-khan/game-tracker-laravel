# Build prompt — the CafeTrack marketing website

Copy everything between the rules below into your website builder (Claude, v0,
Lovable, Bolt, Cursor) or hand it to a designer. Replace every `[SQUARE
BRACKET]` placeholder with your real details **before** sending it — the ones
left unfilled are the things only you can decide.

---

## THE PROMPT

Build a marketing website for **CafeTrack**, a gaming-cafe management system
sold to gaming cafe owners in Bangladesh. The site's single job is to get a
cafe owner to message me on WhatsApp and book a 10-minute demo.

### 1. Who is reading this page

The owner of a small gaming cafe in Bangladesh with 4–20 consoles (PS4, PS5,
PS5 Pro, racing wheel rigs). They currently run the business on a paper khata
or an Excel sheet. They are not technical. They may read the page on a mid-range
Android phone over mobile data. Half of them will read Bangla faster than
English.

Their real, felt problems — write every line of copy against these, not against
a feature list:

- They cannot tell which device actually earns and which one sits idle.
- Staff take cash; the owner has no way to check the day's total is honest.
- They know money came in, but not what profit was left after electricity,
  internet, salaries and snacks.
- Customers stay past their time and nobody notices.
- The Excel sheet is filled in at the end of the night, from memory.

### 2. What the product actually does — these facts are true, use them

**The floor board.** Every device in one grid on one screen, refreshing every
5 seconds. Each tile shows free or busy, who is playing, the elapsed timer,
what the bill is at this moment, and a progress meter against the time the
player booked. A free tile has a **Start session** button; a busy one has
**End session**.

**Pricing the way a cafe actually charges.** A separate hourly rate per
controller count — 1 controller ৳100/hr, 2 controllers ৳120/hr, and so on —
set individually for every device, so a PS5 Pro and a PS4 can be priced apart.
The owner also controls whether time bills per minute or rounds up to a block
(15/30/60 min), and whether the final amount rounds to the nearest ৳5 or ৳10.

**The bill at the counter.** Ending a session shows the whole calculation
before anything is charged: time played, the block it rounded up to, the rate,
the rounding adjustment, any discount, and **To collect** in large type. Print
an 80mm receipt or save a PDF without leaving the screen. The figure on screen
is the figure charged.

**Discounts that are controlled.** An admin can take a flat amount or a
percentage off; the total updates as the number is typed. Staff cannot discount
and cannot void a bill — the system refuses them. Every discount is written to
an activity log with the amount, the reason and who authorised it.

**The cash drawer and shifts.** Open a shift, and the system tracks takings
split by cash / phone payment / wallet. At close it shows the expected-cash
arithmetic line by line, so a shortfall is visible immediately rather than at
the end of the month.

**Expenses.** A proper ledger of what the cafe spends, by category — electricity,
internet, salary, games, snacks, repairs — filterable by date. An expense paid
in cash comes straight out of the open drawer.

**Daily and monthly sheets.** The daily sheet: takings broken down by device
type, the day's expenses by category, the drawer's movements, how money arrived
(cash / phone / wallet), and what is still unpaid. The monthly sheet: every day
of the month with sessions, hours, income, expenses and net profit. These are
tables to settle up against, not decoration.

**Regular customers.** Prepaid wallet top-ups with owner-defined packages (pay
৳500, get ৳600 balance), membership tiers that apply their discount
automatically, and per-customer visit count, lifetime spend and balance.

**Knowing the business.** A 7 × 24 heatmap of when the cafe is actually busy,
device utilisation bars, gross-profit tiles, income and hours charts, and
rankings of the top-earning devices and highest-spending customers.

**Also:** advance bookings that convert to a live session on arrival; a
printable QR code per device so a customer can check themselves in from their
phone; invoice CSV export; separate staff accounts with owner / admin / staff
roles; a full activity log; and multiple branches kept separate under one
account.

**How it runs.** In the browser — phone, tablet or laptop. Nothing to install.

### 3. Honesty rules — these are hard requirements

Do **not** invent any of the following. I am sending this page to real business
owners in my own city and a single false claim ends the sale:

- No fake testimonials, quotes, names, photos or cafe logos.
- No invented statistics ("used by 500+ cafes", "saves 30% of costs",
  "4.9 stars"). The product has no customers yet.
- No "trusted by" logo wall. No award badges. No press mentions.
- Do not claim a mobile app — it is a website that works well on a phone.
- Do not claim bKash/Nagad integration or any online payment gateway. The
  system *records* that a payment arrived by phone; it does not connect to one.
- Do not claim SMS or push notifications to customers.
- Do not claim AI features.

Where a testimonial section would normally go, put an honest **"Be an early
cafe"** section instead: this is new, the first few cafes get free setup and a
direct line to me, and in exchange I want their feedback. Frame being early as
the offer, not as something to hide.

### 4. Page structure

One long single-page site with a sticky header. Anchor links, smooth scroll.

1. **Header** — CafeTrack wordmark left; nav (Features · How it works · Pricing ·
   FAQ) centre; a **বাংলা / EN** language toggle and a green "WhatsApp করুন"
   button right. On mobile: logo, language toggle, hamburger.

2. **Hero** — one screen tall, no more.
   - Headline (BN): **আপনার গেমিং ক্যাফে চলছে অনুমানে, নাকি হিসাবে?**
   - Headline (EN): **Run your gaming cafe on numbers, not guesswork.**
   - Sub (BN): কোন ডিভাইস চলছে, কে খেলছে, কত বিল উঠল, দিনশেষে খরচ বাদে কত থাকল
     — সব এক জায়গায়। খাতা আর Excel এর দিন শেষ।
   - Sub (EN): Every device, every session, every taka — live on one screen.
     Daily and monthly profit worked out for you.
   - Primary button: **ফ্রি ডেমো নিন** → WhatsApp deep link.
     Secondary: **ফিচার দেখুন** → scrolls to features.
   - Visual: a browser-framed screenshot of the floor board. Placeholder image
     with a clear note telling me exactly what to replace it with and at what
     size.
   - Directly under the buttons, one small honest line: *"Game n Chill এর জন্য
     বানানো, এখন সব ক্যাফের জন্য"* / "Built for my own cafe. Now open to yours."

3. **The problem** — four short cards, no icons-for-the-sake-of-icons:
   "কোন বুথে কত ইনকাম, বলতে পারেন?" · "স্টাফের ক্যাশের হিসাব মিলছে তো?" ·
   "খরচ বাদে আসল লাভ কত?" · "কাস্টমার সময় পার করে ফেললে কে খেয়াল রাখে?"

4. **Features** — six to eight blocks, each: short benefit headline, two lines
   of plain-language explanation, and a screenshot. Alternate image left/right
   on desktop; stack on mobile. Order them: the floor board → per-controller
   pricing → the bill and receipt → staff control and the cash drawer →
   expenses and the daily/monthly sheets → customers and loyalty → analytics
   and peak hours.

5. **How it works** — three numbered steps: *১. আপনার ডিভাইস আর রেট বসিয়ে দিই
   (আমি নিজে করে দিই) → ২. স্টাফ শুধু Start আর End চাপে → ৩. দিনশেষে হিসাব
   রেডি*. Emphasise that I do the setup personally.

6. **Who it's for** — a short strip: গেমিং ক্যাফে · PlayStation লাউঞ্জ · একাধিক
   ব্রাঞ্চ · ছোট ক্যাফে (৪+ ডিভাইস).

7. **Pricing** — [DECIDE AND FILL IN: e.g. ১ মাস ফ্রি ট্রায়াল, তারপর ৳X/মাস প্রতি
   ব্রাঞ্চ, সেটআপ ফ্রি]. Two or three cards at most. State plainly what is
   included and that there is no setup fee. If I have not decided, render a
   single card saying "প্রথম ৫টি ক্যাফের জন্য ফ্রি — কথা বলে ঠিক করব" with the
   WhatsApp button.

8. **Be an early cafe** — the honest section described in §3.

9. **FAQ** — accordion, eight items, answered in one short paragraph each:
   ইন্টারনেট ছাড়া চলবে? · আমার পুরনো হিসাব কি ঢোকানো যাবে? · স্টাফ কি ডাটা মুছে
   দিতে পারবে? · ফোনে চলবে? · একাধিক ব্রাঞ্চ? · ডাটা কোথায় থাকে? · শিখতে কত সময়
   লাগবে? · বন্ধ করতে চাইলে ডাটা পাব?
   [I will supply the final answers — write honest first drafts and mark them
   `TODO: confirm` in a comment so I can check each one.]

10. **Final call to action** — full-width band, one headline, one WhatsApp
    button, my phone number as readable text as well as a link, and a simple
    contact form (নাম, ক্যাফের নাম, ফোন, এলাকা). [TELL ME where form
    submissions should go — I will supply a Formspree/Google Form endpoint.]

11. **Footer** — CafeTrack, a line of description, Facebook page link, phone,
    email, © year. No fake company address.

### 5. Language

Fully bilingual, Bangla first. A single toggle switches every string on the
page; remember the choice in `localStorage` and honour the browser language on
first visit. Keep all copy in one structured translations object so I can edit
wording without touching markup. Bangla must use a proper font —
Noto Sans Bengali or Hind Siliguri, self-hosted or from Google Fonts — and be
tested for correct conjunct rendering; never let Bangla fall back to a system
serif. English text uses Inter or a similar clean sans.

Bangla copy should sound like a cafe owner talking to another cafe owner:
direct, respectful, "আপনি" throughout, and comfortable leaving common English
terms in English (session, booth, invoice, PDF, discount, dashboard). Do not
write formal literary Bangla; do not machine-translate the English.

### 6. Design direction

The audience is gaming, but the buyer is a business owner deciding where his
money goes. The page should feel like a **serious tool used in a gaming
business** — not a neon arcade poster and not a beige accounting product.

- Dark theme as the default, matching the product: deep slate/near-black
  background (#0b1020-ish), one indigo/violet accent for primary actions, one
  emerald for money and success states, amber only for warnings. No rainbow
  gradients, no glassmorphism everywhere, no purple-blob hero blur.
- Money and numbers in a tabular-figure font, always with the ৳ symbol.
- Generous space, strong type hierarchy, a large readable base size (17–18px)
  — many readers are on a phone in a bright room.
- Motion: subtle fade-and-rise as sections enter the viewport, nothing that
  moves on its own, and everything respecting `prefers-reduced-motion`.
- Screenshots inside a simple browser frame or phone frame with a soft shadow,
  never tilted at an angle that hides text.
- Include a light theme as well, switched by a control in the header and
  defaulting to the OS setting.

### 7. Technical requirements

- [PICK ONE: a single self-contained `index.html` with inline CSS/JS that I can
  host anywhere · Next.js + Tailwind · plain HTML + Tailwind CDN]. Prefer the
  simplest thing that ships.
- Mobile-first. Must look correct at 360px wide. No horizontal scrolling
  anywhere, ever.
- Fast on 3G: images lazy-loaded, no video background, no heavy libraries, no
  webfont blocking first paint. Target under 500KB for the initial view.
- WhatsApp buttons use `https://wa.me/[MY NUMBER, 8801XXXXXXXXX]?text=` with
  the message pre-filled and URL-encoded: *"আসসালামু আলাইকুম, CafeTrack এর ডেমো
  দেখতে চাই। আমার ক্যাফে: "* — leaving the cursor where they type their cafe
  name.
- A floating WhatsApp button, bottom-right, visible after the hero scrolls past.
- SEO: title, meta description, canonical, `lang` attribute switching with the
  toggle, Open Graph and Twitter tags, and an OG image sized 1200×630 — build a
  placeholder and tell me what to put in it. Add `LocalBusiness` /
  `SoftwareApplication` JSON-LD.
- Accessibility: real semantic headings in order, alt text on every screenshot
  describing what the screen shows, visible focus rings, WCAG AA contrast in
  both themes, the accordion and the language toggle operable by keyboard.
- Every screenshot as a clearly-labelled placeholder file with a caption in the
  code telling me which screen to capture and at what width.

### 8. Deliverables

1. The site itself.
2. A short list of exactly which screenshots I need to take, in order, with the
   product screen named for each and the browser width to capture at.
3. The translations object separated out so I can correct the Bangla wording
   without touching layout.
4. A one-line note on where to host it and how to point a domain at it.

Ask me before inventing any fact about the business, the pricing, or the
number of customers. If something is unknown, leave a clearly marked
placeholder rather than filling it with something plausible.

---

## END OF PROMPT

### Before you send it, fill in

| Placeholder | What to put |
| --- | --- |
| `[MY NUMBER]` | WhatsApp number in `8801XXXXXXXXX` form, no `+`, no spaces |
| Pricing (§7) | Your monthly price per branch and trial length, or leave the "first 5 cafes free" card |
| Form endpoint (§4.10) | A Formspree, Google Form or Netlify Forms target for the contact form |
| Tech choice (§7) | Single HTML file is right if you just want to upload it somewhere and be done |
| FAQ answers (§4.9) | Let the builder draft them, then correct each one yourself |

### Screenshots you will need

Take these from your own running system, with real-looking but not real
customer names, at 1440px wide for desktop shots:

1. The dashboard floor grid with 3–4 devices busy and the rest free.
2. The End session dialog showing the itemised bill and **To collect**.
3. The Stations screen showing per-controller rates.
4. The Daily summary sheet.
5. The Analytics peak-hours heatmap.
6. The Shifts drawer panel at close.
7. The public QR check-in page, captured at phone width (390px).

### One caution

Do not put a live demo login on the public site. If you want to show the
product without a meeting, record a 90-second screen video instead — a shared
demo account gets found, filled with junk data, and shown to the next visitor
in that state.
