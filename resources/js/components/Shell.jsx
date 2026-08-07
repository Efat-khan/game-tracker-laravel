import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { useTheme } from '../lib/hooks';
import { Link, useRouter } from '../lib/router';
import { Button } from './ui';

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

export function BrandMark({ size = 'md' }) {
    const sizes = { md: 'h-9 w-9 text-sm', lg: 'h-12 w-12 text-base' };

    return (
        <span
            className={`ct-brand-mark flex shrink-0 items-center justify-center rounded-xl font-bold tracking-tight ${sizes[size]}`}
        >
            CT
        </span>
    );
}

export function Shell({ children, onStartTour }) {
    const { session, signOut, isAdmin, can, cafeName, needsCafe } = useAuth();
    const { path } = useRouter();
    const { dark, toggle } = useTheme();
    const [mobileOpen, setMobileOpen] = useState(false);

    const items = NAV.filter((item) => (!item.adminOnly || isAdmin) && can(item.feature));

    return (
        <div className="min-h-screen lg:flex">
            {/* Mobile top bar */}
            <div className="sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:hidden dark:border-slate-800 dark:bg-slate-950/90">
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

            {mobileOpen && (
                <div
                    className="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm lg:hidden"
                    onClick={() => setMobileOpen(false)}
                    aria-hidden="true"
                />
            )}

            <aside
                className={`fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white
                            transition-transform lg:static lg:translate-x-0
                            dark:border-slate-800 dark:bg-slate-900/60 dark:backdrop-blur-xl
                            ${mobileOpen ? 'translate-x-0' : '-translate-x-full'}`}
            >
                <div className="border-b border-slate-200 px-5 py-5 dark:border-slate-800">
                    <div className="flex items-center gap-2.5">
                        <BrandMark />
                        <div className="min-w-0">
                            <p className="ct-brand text-lg font-bold leading-none tracking-tight">CAFETRACK</p>
                            {/* The current cafe's name, under the logo. */}
                            <p
                                className={`mt-1.5 truncate text-[11px] font-medium uppercase tracking-[0.1em] ${
                                    needsCafe
                                        ? 'text-amber-500 dark:text-amber-400'
                                        : 'text-slate-500 dark:text-slate-400'
                                }`}
                                title={cafeName || ''}
                            >
                                {cafeName || '—'}
                            </p>
                        </div>
                    </div>
                </div>

                <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-3">
                    {items.map((item) => {
                        const active = item.to === '/' ? path === '/' : path.startsWith(item.to);

                        return (
                            <Link
                                key={item.to}
                                to={item.to}
                                data-tour={item.to}
                                onClick={() => setMobileOpen(false)}
                                className={`group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${
                                    active
                                        ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-200'
                                }`}
                            >
                                {/* A lit edge on the active item. */}
                                {active && (
                                    <span className="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-gradient-to-b from-indigo-400 to-live-500" />
                                )}
                                <Icon name={item.icon} />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>

                <div className="space-y-1 border-t border-slate-200 px-3 py-3 dark:border-slate-800">
                    <button
                        onClick={onStartTour}
                        className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800/60"
                    >
                        <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.3M12 17h.01" strokeLinecap="round" />
                        </svg>
                        Guide
                    </button>

                    <button
                        onClick={toggle}
                        className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800/60"
                    >
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
                        {dark ? 'Light mode' : 'Dark mode'}
                    </button>

                    <div className="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-950/50">
                        <p className="truncate text-xs font-medium" title={session?.email}>
                            {session?.email}
                        </p>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-indigo-500 dark:text-indigo-400">
                            {session?.role}
                        </p>
                        <Button size="sm" variant="ghost" className="mt-1.5 -ml-1.5" onClick={signOut}>
                            Sign out
                        </Button>
                    </div>
                </div>
            </aside>

            <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
                {needsCafe && path !== '/cafes' ? <NoCafeSelected /> : children}
            </main>
        </div>
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
