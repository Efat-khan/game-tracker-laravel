/**
 * The one place that talks to the backend.
 *
 * Everything the screens need goes through `api`, mirroring the 69 routes.
 * Two things this layer guarantees:
 *
 *   - Money stays a STRING all the way from the API to the screen. It is never
 *     parsed into a JS number, because a float cannot hold every 2dp value and
 *     a cafe's takings should not drift by a paisa on the way to a <td>.
 *   - A 401 signs the user out rather than leaving a dead screen. Tokens last
 *     12 hours and are invalidated server-side on a password or role change,
 *     so this fires in normal use, not just on expiry.
 */

const TOKEN_KEY = 'cafetrack.token';
const CAFE_KEY = 'cafetrack.cafe';

export function getToken() {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export function setToken(token) {
    try {
        token ? localStorage.setItem(TOKEN_KEY, token) : localStorage.removeItem(TOKEN_KEY);
    } catch {
        /* private browsing */
    }
}

/**
 * The cafe a superadmin is currently working inside. Admins and staff are bound
 * to their own cafe by their token and this is ignored for them — the server
 * ignores the header too.
 */
export function getActiveCafe() {
    try {
        const raw = localStorage.getItem(CAFE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

export function setActiveCafe(cafe) {
    try {
        cafe ? localStorage.setItem(CAFE_KEY, JSON.stringify(cafe)) : localStorage.removeItem(CAFE_KEY);
    } catch {
        /* private browsing */
    }
}

export class ApiError extends Error {
    constructor(status, payload) {
        super(payload?.message || `Request failed (${status})`);
        this.status = status;
        this.payload = payload;
        // Laravel returns {message, errors:{field:[msg]}} on a 422.
        this.errors = payload?.errors || null;
    }

    /** The first validation message, which is what a form wants to show. */
    get firstError() {
        if (!this.errors) return this.message;
        const first = Object.values(this.errors)[0];
        return Array.isArray(first) ? first[0] : this.message;
    }
}

let onUnauthorized = () => {};

export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

function buildQuery(params) {
    if (!params) return '';
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '') search.append(key, value);
    }
    const qs = search.toString();
    return qs ? `?${qs}` : '';
}

async function request(method, path, { body, params, raw, file, inCafe } = {}) {
    const headers = { Accept: 'application/json' };
    const token = getToken();

    if (token) headers.Authorization = `Bearer ${token}`;

    // `inCafe` lets a superadmin act inside a named cafe for one request without
    // switching the whole app into it — editing another cafe's accounts from
    // the Cafes screen, say. Admins and staff are pinned to their own cafe by
    // their token and the server ignores the header for them either way.
    const cafeId = inCafe ?? getActiveCafe()?.id;
    if (cafeId) headers['X-Cafe-Id'] = String(cafeId);

    let requestBody;

    if (file) {
        // No Content-Type header: the browser has to set it so it can append
        // the multipart boundary. Setting it by hand produces a body the server
        // cannot parse, and an empty $request->file().
        requestBody = new FormData();
        requestBody.append('image', file);
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        requestBody = JSON.stringify(body);
    }

    const response = await fetch(`/api${path}${buildQuery(params)}`, {
        method,
        headers,
        body: requestBody,
    });

    if (response.status === 401) {
        onUnauthorized();
        throw new ApiError(401, { message: 'Your session has ended. Please sign in again.' });
    }

    if (raw) {
        if (!response.ok) throw new ApiError(response.status, await safeJson(response));
        return response;
    }

    if (response.status === 204) return null;

    const payload = await safeJson(response);

    if (!response.ok) throw new ApiError(response.status, payload);

    return payload;
}

async function safeJson(response) {
    const text = await response.text();
    if (!text) return null;
    try {
        return JSON.parse(text);
    } catch {
        return { message: text.slice(0, 200) };
    }
}

/**
 * Turn a `raw: true` response into a file on the user's disk.
 *
 * Downloads have to go through fetch so the bearer token rides along, which
 * means the browser's own "save this" behaviour is bypassed and we have to
 * synthesise the click ourselves.
 */
export async function saveResponseAs(response, filename) {
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    // Give the browser a tick to start reading the object before revoking it;
    // Safari aborts the download otherwise.
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const get = (path, params) => request('GET', path, { params });
const post = (path, body, params) => request('POST', path, { body: body ?? {}, params });
const patch = (path, body) => request('PATCH', path, { body });
const del = (path) => request('DELETE', path);

export const api = {
    /* ---- auth & cafes ------------------------------------------------- */
    login: (email, password) => post('/auth/login', { email, password }),
    myCafes: () => get('/cafes/mine'),
    createCafe: (body) => post('/cafes', body),
    updateCafe: (id, body) => patch(`/cafes/${id}`, body),

    /* ---- branding ------------------------------------------------------ */
    // Public: the login screen reads this before anyone has a token.
    branding: () => get('/branding'),
    // Platform owner only — the server enforces that, not this file.
    uploadBranding: (asset, file) => request('POST', `/branding/${asset}`, { file }),
    removeBranding: (asset) => del(`/branding/${asset}`),

    /* ---- expenses ------------------------------------------------------ */
    expenses: (params) => get('/expenses', params),
    createExpense: (body) => post('/expenses', body),
    // Admin only, and refused once the shift it came out of has been counted.
    deleteExpense: (id) => del(`/expenses/${id}`),

    /* ---- optional modules ---------------------------------------------- */
    // What this cafe may use. The sidebar reads it.
    features: () => get('/features'),
    // The grant. Superadmin only — the server enforces that, not this file.
    setCafeFeatures: (id, body) => patch(`/cafes/${id}/features`, body),

    /* ---- stations ----------------------------------------------------- */
    stations: () => get('/stations'),
    createStation: (body) => post('/stations', body),
    updateStation: (id, body) => patch(`/stations/${id}`, body),
    deleteStation: (id) => del(`/stations/${id}`),
    toggleMaintenance: (id, maintenance) => post(`/stations/${id}/maintenance`, { maintenance }),
    qrCodeUrl: (id) => `/api/stations/${id}/qrcode`,

    /* ---- public (no account) ------------------------------------------ */
    publicStation: (id) => get(`/stations/${id}/public`),
    checkin: (stationId, body, token) => post(`/checkin/${stationId}`, body, token ? { t: token } : null),

    /* ---- sessions ----------------------------------------------------- */
    activeSessions: () => get('/sessions/active'),
    // What a running session bills if it ends now, itemised. Nothing is written.
    sessionQuote: (id) => get(`/sessions/${id}/quote`),
    sessions: (params) => get('/sessions', params),
    checkout: (sessionId, paymentMethod) =>
        post(`/checkout/${sessionId}`, paymentMethod ? { payment_method: paymentMethod } : {}),
    cancelSession: (id) => post(`/sessions/${id}/cancel`),

    /* ---- invoices ------------------------------------------------------ */
    invoices: (params) => get('/invoices', params),
    updateInvoice: (id, body) => patch(`/invoices/${id}`, body),
    addInvoiceItem: (id, body) => post(`/invoices/${id}/items`, body),
    removeInvoiceItem: (id, itemId) => del(`/invoices/${id}/items/${itemId}`),
    discountInvoice: (id, body) => post(`/invoices/${id}/discount`, body),
    voidInvoice: (id, reason) => post(`/invoices/${id}/void`, { reason }),
    payInvoiceFromWallet: (id) => post(`/invoices/${id}/pay-wallet`),
    // Both of these must be FETCHED, not linked. A plain <a href> is a browser
    // navigation: it carries no Authorization header, so the API answers 401.
    // `raw` hands back the Response so the caller can take the blob.
    invoicePdf: (id) => request('GET', `/invoices/${id}/pdf`, { raw: true }),
    exportCsv: (params) => request('GET', '/invoices/export.csv', { params, raw: true }),

    /* ---- catalogue ----------------------------------------------------- */
    products: (params) => get('/products', params),
    createProduct: (body) => post('/products', body),
    updateProduct: (id, body) => patch(`/products/${id}`, body),
    deleteProduct: (id) => del(`/products/${id}`),

    packages: (params) => get('/packages', params),
    createPackage: (body) => post('/packages', body),
    updatePackage: (id, body) => patch(`/packages/${id}`, body),
    deletePackage: (id) => del(`/packages/${id}`),

    tiers: (params) => get('/tiers', params),
    createTier: (body) => post('/tiers', body),
    updateTier: (id, body) => patch(`/tiers/${id}`, body),
    deleteTier: (id) => del(`/tiers/${id}`),

    /* ---- customers & wallet -------------------------------------------- */
    customers: (params) => get('/customers', params),
    customer: (id) => get(`/customers/${id}`),
    wallet: (id, params) => get(`/customers/${id}/wallet`, params),
    topup: (id, body) => post(`/customers/${id}/topup`, body),
    adjust: (id, body) => post(`/customers/${id}/adjust`, body),

    /* ---- bookings ------------------------------------------------------ */
    bookings: (params) => get('/bookings', params),
    createBooking: (body) => post('/bookings', body),
    updateBooking: (id, body) => patch(`/bookings/${id}`, body),
    startBooking: (id) => post(`/bookings/${id}/start`),
    cancelBooking: (id, noShow) => post(`/bookings/${id}/cancel`, { no_show: !!noShow }),

    /* ---- shifts -------------------------------------------------------- */
    shifts: (params) => get('/shifts', params),
    currentShift: () => get('/shifts/current'),
    shift: (id) => get(`/shifts/${id}`),
    openShift: (body) => post('/shifts/open', body),
    closeShift: (id, body) => post(`/shifts/${id}/close`, body),
    cashMovement: (id, body) => post(`/shifts/${id}/cash`, body),

    /* ---- analytics ------------------------------------------------------ */
    dailyIncome: (params) => get('/analytics/daily-income', params),
    topStations: (params) => get('/analytics/top-stations', params),
    topCustomers: (params) => get('/analytics/top-customers', params),
    peakHours: (params) => get('/analytics/peak-hours', params),
    utilization: (params) => get('/analytics/utilization', params),
    profit: (params) => get('/analytics/profit', params),
    // The two summary sheets. Admin only — the server enforces that.
    dailySummary: (date) => get('/analytics/daily-summary', date ? { date } : null),
    monthlySummary: (month) => get('/analytics/monthly-summary', month ? { month } : null),
    staffAnalytics: (params) => get('/analytics/staff', params),

    /* ---- admin ---------------------------------------------------------- */
    audit: (params) => get('/audit', params),
    settings: () => get('/settings'),
    updateSettings: (body) => patch('/settings', body),

    /*
     * `inCafe` is optional and only a superadmin can use it — the Cafes screen
     * edits another cafe's accounts through these without switching into it.
     * Everyone else omits it and works in the cafe their token names.
     */
    staff: (inCafe) => request('GET', '/staff', { inCafe }),
    createStaff: (body, inCafe) => request('POST', '/staff', { body, inCafe }),
    updateStaff: (id, body, inCafe) => request('PATCH', `/staff/${id}`, { body, inCafe }),
    revokeStaff: (id, inCafe) => request('POST', `/staff/${id}/revoke`, { body: {}, inCafe }),
    deleteStaff: (id, inCafe) => request('DELETE', `/staff/${id}`, { inCafe }),
};
