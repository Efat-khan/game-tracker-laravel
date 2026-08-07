/**
 * Money is a string everywhere in this app — it arrives from the API as
 * "1500000.00" and is only ever turned into digits for display.
 */

const grouper = new Intl.NumberFormat('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * ৳1,50,000.00 — lakh-style grouping.
 *
 * The symbol is prepended by hand rather than using currency: 'BDT', because
 * several browsers render that as the literal text "BDT" instead of ৳.
 */
export function money(value) {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return '৳0.00';
    return `৳${grouper.format(n)}`;
}

/** Same grouping, no symbol — for axis ticks and tight table cells. */
export function amount(value) {
    const n = Number(value ?? 0);
    return Number.isFinite(n) ? grouper.format(n) : '0.00';
}

/** "1.5 h" from a minute count. */
export function hours(minutes) {
    const n = Number(minutes ?? 0);
    return `${(n / 60).toFixed(1)} h`;
}

/** "1h 15m" — how staff read an elapsed timer. */
export function duration(minutes) {
    const n = Math.max(0, Math.round(Number(minutes ?? 0)));
    const h = Math.floor(n / 60);
    const m = n % 60;
    return h ? `${h}h ${m}m` : `${m}m`;
}

/**
 * The API sends naive UTC ISO-8601 with no offset ("2026-07-30T08:51:00").
 * Appending Z is what makes the browser read it as UTC rather than local time —
 * without it every timestamp in the app would be wrong by the viewer's offset.
 */
export function parseUtc(value) {
    if (!value) return null;
    const iso = /[Zz]|[+-]\d{2}:?\d{2}$/.test(value) ? value : `${value}Z`;
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? null : date;
}

export function dateTime(value) {
    const date = parseUtc(value);
    return date
        ? date.toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';
}

export function time(value) {
    const date = parseUtc(value);
    return date ? date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '—';
}

export function day(value) {
    const date = parseUtc(value);
    return date ? date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—';
}

/** For <input type="datetime-local">, which wants local wall-clock time. */
export function toLocalInput(date) {
    const d = date instanceof Date ? date : parseUtc(date) || new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** A datetime-local value back to the naive-UTC string the API expects. */
export function fromLocalInput(value) {
    if (!value) return null;
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return null;
    return d.toISOString().slice(0, 19);
}

export function titleCase(value) {
    if (!value) return '';
    return String(value).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export const paymentLabel = (method) =>
    ({ cash: 'Cash', phone_payment: 'Phone', wallet: 'Wallet' })[method] || '—';
