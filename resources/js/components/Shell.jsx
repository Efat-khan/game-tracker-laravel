import { useEffect, useState } from 'react';
import { useAuth } from '../lib/auth';
import { useBranding } from '../lib/branding';
import { useTheme } from '../lib/hooks';
import { Link, useRouter } from '../lib/router';

/**
 * Nav order is fixed by the spec.
 *
 * `adminOnly` hides an item from staff. `feature` hides it when the platform
 * owner has not granted this cafe that module — in both cases the item is
 * absent rather than shown-and-disabled, so nobody sees a door they cannot
 * open. The API refuses the routes too; this is only the presentation half.
 */
export const NAV = [
    { to: '/', label: 'Dashboard', icon: 'grid', tour: 'Every device at a glance, refreshing every five seconds. Start a session on a free card, end one on a busy card.' },
    { to: '/stations', label: 'Stations', icon: 'device', tour: 'Your PS5s, PCs and consoles — rates, controller limits and the QR sticker each one needs.' },
    { to: '/sessions', label: 'Sessions', icon: 'clock', tour: 'Everything that has ever played here, filterable by station, status, customer and date.' },
    { to: '/invoices', label: 'Invoices', icon: 'receipt', tour: 'Click a status pill to mark an invoice paid. Expand a row to add snacks, or export the lot to CSV.' },
    { to: '/products', label: 'Products', icon: 'box', feature: 'products', tour: 'The snacks and drinks you sell, with the cost price behind each one so profit reports stay honest.' },
    { to: '/bookings', label: 'Bookings', icon: 'calendar', feature: 'bookings', tour: 'Reserve a station ahead of time. When the player walks in, Arrived turns the booking into a live session.' },
    { to: '/customers', label: 'Customers', icon: 'users', tour: 'Who plays here, what they have spent, their tier and their wallet balance. Top up from here.' },
    { to: '/shifts', label: 'Shifts', icon: 'cash', tour: 'Open a shift at the start of the day and count the drawer at the end. Only cash counts towards it.' },
    { to: '/loyalty', label: 'Loyalty', icon: 'star', adminOnly: true, feature: 'loyalty', tour: 'Top-up packages and the spend thresholds that earn a member their discount.' },
    { to: '/analytics', label: 'Analytics', icon: 'chart', tour: 'Income, hours played, gross profit, how busy each station is and when your peak hours really are.' },
    { to: '/summary', label: 'Summary', icon: 'sheet', adminOnly: true, tour: 'The day and the month as a sheet: takings by device, cash paid out of the drawer, and what is left.' },
    { to: '/logs', label: 'Logs', icon: 'list', adminOnly: true, tour: 'Every money-affecting action, who did it and when. Append-only.' },
    { to: '/staff', label: 'Staff', icon: 'badge', adminOnly: true, tour: 'Accounts and roles. Resetting a password or forcing a sign-out ends that person’s sessions everywhere.' },
    { to: '/settings', label: 'Settings', icon: 'cog', adminOnly: true, tour: 'Your billing block, rounding step and trading hours — each shown with a worked example.' },
    { to: '/cafes', label: 'Cafes', icon: 'building', tour: 'The cafe you are working in. Platform owners can open, suspend and switch between all of them.' },
];

const ICONS = {
    grid: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z',
    device: 'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6zm4 14h8',
    clock: 'M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zm3 5h6M9 12h6',
    box: 'M4 7l8-4 8 4v10l-8 4-8-4V7zm8-4v18M4 7l8 4 8-4',
    calendar: 'M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z',
    users: 'M16 18v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1m13-9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9.5 9a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM21 18v-1a4 4 0 0 0-3-3.9',
    cash: 'M3 7h18v10H3V7zm9 5a2 2 0 1 0 0-.001M6 10v.01M18 14v.01',
    star: 'M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 20.9l1.1-6.5L2.6 9.8l6.5-.9L12 3z',
    chart: 'M4 20V10m6 10V4m6 16v-6m4 6H2',
    list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    sheet: 'M4 4h16v16H4V4zm0 5h16M4 14h16M9 4v16M14.5 4v16',
    badge: 'M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 0v7l-3-2-3 2V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17l-3-2-3 2',
    cog: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.4 7.4 0 0 0-2-1.2l-.4-2.6h-4l-.4 2.6a7.4 7.4 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7.4 7.4 0 0 0 2 1.2l.4 2.6h4l.4-2.6a7.4 7.4 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.06-.4.1-.8.1-1.2z',
    building: 'M3 21h18M5 21V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v16M13 9h5a1 1 0 0 1 1 1v11M8 8h2M8 12h2M8 16h2M16 13h1M16 17h1',
};

function Icon({ name, className = 'h-[18px] w-[18px]' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d={ICONS[name]} />
        </svg>
    );
}

/**
 * The logo. The platform owner's upload if there is one, the built-in CT tile
 * otherwise — the fallback is never a broken image.
 */
export function BrandMark({ size = 'md' }) {
    const { logoUrl } = useBranding();
    const sizes = { md: 'h-9 w-9 text-sm', lg: 'h-12 w-12 text-base' };

    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt=""
                // contain, not cover: a logo that has been cropped to fill a
                // square is a logo nobody recognises.
                className={`shrink-0 rounded-xl object-contain ${sizes[size]}`}
            />
        );
    }

    return (
        <span
            className={`ct-brand-mark flex shrink-0 items-center justify-center rounded-xl font-bold tracking-tight ${sizes[size]}`}
        >
            CT
        </span>
    );
}

const COLLAPSED_KEY = 'cafetrack.sidebar-collapsed';

/**
 * Whether the desktop sidebar is showing labels.
 *
 * It survives a reload, because a rail someone deliberately collapsed
 * springing back open on every page load is worse than no toggle at all.
 * Mobile ignores this entirely — the drawer is always labelled there.
 */
function useSidebarCollapsed() {
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem(COLLAPSED_KEY) === '1';
        } catch {
            return false;
        }
    });

    useEffect(() => {
        try {
            localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
        } catch {
            /* private browsing */
        }
    }, [collapsed]);

    return [collapsed, () => setCollapsed((c) => !c)];
}

export function Shell({ children, onStartTour }) {
    const { signOut, isAdmin, can, needsCafe } = useAuth();
    const { path } = useRouter();
    const { dark, toggle } = useTheme();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, toggleCollapsed] = useSidebarCollapsed();

    const items = NAV.filter((item) => (!item.adminOnly || isAdmin) && can(item.feature));

    return (
        <div className="flex min-h-screen bg-white dark:bg-slate-950">
            {mobileOpen && (
                <div
                    className="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm lg:hidden"
                    onClick={() => setMobileOpen(false)}
                    aria-hidden="true"
                />
            )}

            {/* Labels and icons together. Collapsing narrows it to the icons,
                which is a choice the user makes rather than the default. */}
            <nav
                className={`fixed inset-y-0 left-0 z-50 flex w-60 shrink-0 flex-col border-r border-slate-200 bg-white
                            transition-[transform,width] lg:static lg:translate-x-0
                            dark:border-slate-800 dark:bg-slate-950
                            ${mobileOpen ? 'translate-x-0' : '-translate-x-full'}
                            ${collapsed ? 'lg:w-[4.75rem]' : 'lg:w-60'}`}
                aria-label="Main"
            >
                <div className={`flex items-center gap-2.5 px-5 py-5 ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}>
                    <BrandMark />
                    <span className={`ct-brand text-base font-bold tracking-tight ${collapsed ? 'lg:hidden' : ''}`}>
                        CAFETRACK
                    </span>
                </div>

                <div className="flex-1 space-y-1 overflow-y-auto px-3 py-2">
                    {items.map((item) => {
                        const active = item.to === '/' ? path === '/' : path.startsWith(item.to);

                        return (
                            <Link
                                key={item.to}
                                to={item.to}
                                data-tour={item.to}
                                onClick={() => setMobileOpen(false)}
                                aria-current={active ? 'page' : undefined}
                                className={`group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition
                                            ${collapsed ? 'lg:justify-center lg:px-0' : ''} ${
                                                active
                                                    ? 'bg-gradient-to-br from-indigo-500 to-indigo-600 text-white shadow-[0_0_20px_-6px_var(--color-indigo-500)]'
                                                    : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-100'
                                            }`}
                            >
                                <Icon name={item.icon} />
                                <span className={collapsed ? 'lg:hidden' : ''}>{item.label}</span>
                                {collapsed && <RailTip label={item.label} />}
                            </Link>
                        );
                    })}
                </div>

                <div className="space-y-1 border-t border-slate-200 px-3 py-3 dark:border-slate-800">
                    <RailButton onClick={onStartTour} label="Guide" collapsed={collapsed}>
                        <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.3M12 17h.01" strokeLinecap="round" />
                        </svg>
                    </RailButton>

                    <RailButton onClick={toggle} label={dark ? 'Light mode' : 'Dark mode'} collapsed={collapsed}>
                        {dark ? (
                            <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
                                <circle cx="12" cy="12" r="4" />
                                <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" strokeLinecap="round" />
                            </svg>
                        ) : (
                            <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
                                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" strokeLinejoin="round" />
                            </svg>
                        )}
                    </RailButton>

                    <RailButton onClick={signOut} label="Sign out" collapsed={collapsed}>
                        <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
                            <path d="M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h6" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                    </RailButton>

                    {/* Desktop only: on mobile the drawer closes instead. */}
                    <button
                        onClick={toggleCollapsed}
                        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        aria-expanded={!collapsed}
                        className={`group relative hidden w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium
                                    text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 lg:flex
                                    dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-100
                                    ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}
                    >
                        <svg
                            className={`h-[18px] w-[18px] transition-transform ${collapsed ? 'rotate-180' : ''}`}
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.7"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M15 6l-6 6 6 6" />
                        </svg>
                        <span className={collapsed ? 'lg:hidden' : ''}>Collapse</span>
                        {collapsed && <RailTip label="Expand sidebar" />}
                    </button>
                </div>
            </nav>

            <div className="flex min-w-0 flex-1 flex-col">
                {/* Mobile bar: the sidebar is off-canvas here. */}
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 lg:hidden dark:border-slate-800">
                    <button
                        onClick={() => setMobileOpen(true)}
                        aria-label="Open menu"
                        className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M4 6h16M4 12h16M4 18h16" strokeLinecap="round" />
                        </svg>
                    </button>
                    <span className="ct-brand text-sm font-bold tracking-tight">CAFETRACK</span>
                    <span className="w-9" />
                </div>

                <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {needsCafe && path !== '/cafes' ? <NoCafeSelected /> : children}
                </main>
            </div>
        </div>
    );
}

/** The hover label a collapsed rail needs; title= appears far too slowly. */
function RailTip({ label }) {
    return (
        <span
            className="pointer-events-none absolute left-full z-50 ml-3 hidden whitespace-nowrap rounded-lg
                       bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 shadow-lg
                       transition-opacity group-hover:opacity-100 lg:block dark:bg-slate-800"
        >
            {label}
        </span>
    );
}

function RailButton({ onClick, label, collapsed, children }) {
    return (
        <button
            onClick={onClick}
            aria-label={label}
            className={`group relative flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium
                        text-slate-500 transition hover:bg-slate-100 hover:text-slate-900
                        dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-100
                        ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}
        >
            {children}
            <span className={collapsed ? 'lg:hidden' : ''}>{label}</span>
            {collapsed && <RailTip label={label} />}
        </button>
    );
}

/**
 * A superadmin belongs to no cafe, so every scoped screen would answer 400
 * until they pick one. Say so plainly instead of showing a broken screen.
 */
function NoCafeSelected() {
    return (
        <div className="mx-auto max-w-md py-20 text-center">
            <div className="mx-auto mb-5 w-fit">
                <BrandMark size="lg" />
            </div>
            <h2 className="text-lg font-semibold">Pick a cafe to work in</h2>
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                You are signed in as the platform owner, which means you do not belong to any one cafe. Choose
                one and everything else will act inside it.
            </p>
            <Link
                to="/cafes"
                className="mt-5 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
            >
                Go to Cafes
            </Link>
        </div>
    );
}
