import { api } from '../lib/api';
import { useAsync, usePolling } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { money } from '../lib/format';
import { Link } from '../lib/router';
import { Donut } from './viz';
import { Spinner } from './ui';

/**
 * The right rail: today at a glance, on every screen.
 *
 * Everything here comes from endpoints the app already has — the open shift's
 * live totals and the last week of income. No new API surface.
 */
export function RightRail() {
    const { session, isAdmin, cafeName } = useAuth();

    const shift = useAsync(() => api.currentShift(), []);
    const income = useAsync(() => api.dailyIncome({ days: 7 }), []);

    // Same cadence as the dashboard, so the two never disagree on screen.
    usePolling(() => {
        shift.reload();
        income.reload();
    }, 5000);

    const totals = shift.data?.totals;
    const today = income.data?.[income.data.length - 1];

    const segments = [
        { label: 'Cash', value: totals?.cash_sales ?? 0 },
        { label: 'Phone', value: totals?.phone_sales ?? 0 },
        { label: 'Wallet', value: totals?.wallet_sales ?? 0 },
    ];

    const initials = (session?.email ?? '?').slice(0, 2).toUpperCase();

    return (
        <aside className="hidden w-[19rem] shrink-0 border-l border-slate-200 px-5 py-6 xl:block dark:border-slate-800">
            <div className="flex flex-col items-center text-center">
                <span className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-live-500 text-lg font-bold text-white shadow-[0_0_28px_-6px_var(--color-indigo-500)]">
                    {initials}
                </span>
                <p className="mt-3 truncate text-sm font-semibold" title={session?.email}>
                    {session?.email}
                </p>
                <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-indigo-500/15 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-indigo-600 ring-1 ring-inset ring-indigo-500/30 dark:text-indigo-300">
                    {session?.role}
                </span>
                {/* The cafe being worked in. The icon rail has no room for it,
                    so it lives here, visible on every screen. */}
                <p className="mt-2.5 truncate text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500 dark:text-slate-400">
                    {cafeName || '—'}
                </p>
            </div>

            <div className="mt-7 border-t border-slate-200 pt-5 text-center dark:border-slate-800">
                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                    {shift.data ? 'Taken this shift' : 'Taken today'}
                </p>
                <p className="mt-1 text-3xl font-bold tabular-nums">
                    {shift.loading && !shift.data ? (
                        <Spinner className="mx-auto h-6 w-6" />
                    ) : (
                        money(totals?.total_sales ?? today?.income ?? 0)
                    )}
                </p>

                <div className="mt-5 grid grid-cols-3 gap-2">
                    <RailStat label="Cash" value={money(totals?.cash_sales ?? 0)} />
                    <RailStat label="Phone" value={money(totals?.phone_sales ?? 0)} />
                    <RailStat label="Wallet" value={money(totals?.wallet_sales ?? 0)} />
                </div>
            </div>

            <div className="mt-7 border-t border-slate-200 pt-6 dark:border-slate-800">
                {shift.data ? (
                    <Donut
                        segments={segments}
                        centreLabel="In drawer"
                        centreValue={money(shift.data.expected_cash ?? 0)}
                    />
                ) : (
                    <div className="rounded-xl border border-dashed border-slate-300 px-4 py-6 text-center dark:border-slate-700">
                        <p className="text-sm text-slate-500">No shift is open.</p>
                        <Link
                            to="/shifts"
                            className="mt-2 inline-block text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            Open one →
                        </Link>
                    </div>
                )}
            </div>

            {isAdmin && (
                <Link
                    to="/analytics"
                    className="mt-7 block overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-500 via-indigo-600 to-live-600 p-5 text-white transition hover:brightness-110"
                >
                    <p className="text-sm font-semibold leading-snug">
                        See how the floor is really performing
                    </p>
                    <p className="mt-1 text-xs text-white/80">
                        Utilization, peak hours and gross margin.
                    </p>
                    <span className="mt-3 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/20">
                        →
                    </span>
                </Link>
            )}
        </aside>
    );
}

function RailStat({ label, value }) {
    return (
        <div>
            <p className="text-[9px] font-semibold uppercase tracking-[0.1em] text-slate-500">{label}</p>
            <p className="mt-0.5 truncate text-xs font-semibold tabular-nums">{value}</p>
        </div>
    );
}
