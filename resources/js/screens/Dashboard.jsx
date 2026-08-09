import { useEffect, useMemo, useState } from 'react';
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { api, saveResponseAs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync, useNow, usePolling } from '../lib/hooks';
import { useChartTheme } from '../lib/charts';
import { amount, day, duration, money, parseUtc, rateFor } from '../lib/format';
import { printInvoice } from '../lib/print';
import { Link } from '../lib/router';
import { Donut, Meter } from '../components/viz';
import {
    Button,
    Card,
    ErrorNote,
    Field,
    Input,
    Loading,
    Modal,
    Pill,
    Select,
} from '../components/ui';

export default function Dashboard() {
    const { session, cafeName } = useAuth();
    const { data, error, loading, reload } = useAsync(() => api.activeSessions(), []);
    const income = useAsync(() => api.dailyIncome({ days: 30 }), []);
    const utilization = useAsync(() => api.utilization({ days: 7 }), []);

    const [starting, setStarting] = useState(null);
    const [ending, setEnding] = useState(null);
    const [search, setSearch] = useState('');

    // Every 5 seconds, per the spec. Quiet reloads, so the numbers never flash.
    usePolling(reload, 5000);

    // Ticks once a second so elapsed time advances between refreshes rather
    // than jumping in 5-second steps.
    const now = useNow(1000);

    const rows = data ?? [];

    const summary = useMemo(() => {
        const occupied = rows.filter((r) => r.status === 'occupied');
        const running = occupied.reduce((sum, r) => sum + Number(r.running_cost || 0), 0);
        const overdue = occupied.filter(
            (r) => r.planned_minutes != null && r.elapsed_minutes > r.planned_minutes,
        );

        return {
            occupied: occupied.length,
            free: rows.filter((r) => r.status === 'free').length,
            total: rows.length,
            running,
            overdue,
        };
    }, [rows]);

    const visible = search
        ? rows.filter(
              (r) =>
                  r.station_name.toLowerCase().includes(search.toLowerCase()) ||
                  (r.customer_name ?? '').toLowerCase().includes(search.toLowerCase()),
          )
        : rows;

    if (loading && !data) return <Loading label="Loading the floor…" />;

    const firstName = (session?.email ?? '').split('@')[0];

    return (
        <>
            {/* Welcome header, with the cafe named and search to its right. */}
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                        Welcome, <span className="ct-brand capitalize">{firstName}!</span>
                    </h1>
                    <p className="mt-2 flex max-w-xl items-start gap-2 text-sm text-slate-500 dark:text-slate-400">
                        <span className="mt-1 h-3.5 w-0.5 shrink-0 rounded-full bg-gradient-to-b from-indigo-400 to-live-500" />
                        <span>
                            {cafeName} — {summary.occupied} of {summary.total} devices in play, {money(summary.running)}{' '}
                            on the clock right now.
                        </span>
                    </p>
                </div>

                <label className="relative w-full sm:w-64">
                    <span className="sr-only">Search stations and players</span>
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search station or player…"
                        className="ct-input pr-9"
                    />
                    <svg
                        className="pointer-events-none absolute right-3 top-2.5 h-4 w-4 text-slate-400"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7" />
                        <path d="M20 20l-3.5-3.5" strokeLinecap="round" />
                    </svg>
                </label>
            </div>

            <ErrorNote error={error} onRetry={reload} />

            <StationGrid
                rows={visible}
                now={now}
                onStart={setStarting}
                onEnd={setEnding}
                onMaintenance={async (row) => {
                    await api.toggleMaintenance(row.station_id, !row.maintenance);
                    reload();
                }}
            />

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                <IncomeCard rows={income.data} loading={income.loading} />
                <TodayCard income={income.data} />
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                <FloorTable rows={visible} now={now} onEnd={setEnding} onStart={setStarting} />
                <div className="grid gap-4">
                    <UtilizationCard rows={utilization.data} loading={utilization.loading} />
                    <AttentionCard rows={rows} overdue={summary.overdue} />
                </div>
            </div>

            <StartSessionModal row={starting} onClose={() => setStarting(null)} onDone={reload} />
            <EndSessionModal row={ending} onClose={() => setEnding(null)} onDone={reload} />
        </>
    );
}

/* --------------------------------------------------------------- floor grid */

/**
 * Every device on the floor, wrapped onto as many rows as it takes.
 *
 * It deliberately does not scroll sideways: a device hidden off the edge of a
 * track is a device nobody notices is free, and the whole point of this panel
 * is that the floor is visible at a glance.
 */
function StationGrid({ rows, now, onStart, onEnd, onMaintenance }) {
    return (
        <Card className="p-4">
            <div className="mb-3 flex items-baseline justify-between gap-3">
                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">The floor</p>
                <p className="text-xs text-slate-400">{rows.length} devices</p>
            </div>

            {rows.length === 0 ? (
                <p className="py-8 text-center text-sm text-slate-500">Nothing matches that search.</p>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    {rows.map((row) => (
                        <StationTile
                            key={row.station_id}
                            row={row}
                            now={now}
                            onStart={() => onStart(row)}
                            onEnd={() => onEnd(row)}
                            onMaintenance={() => onMaintenance(row)}
                        />
                    ))}
                </div>
            )}
        </Card>
    );
}

function StationTile({ row, now, onStart, onEnd, onMaintenance }) {
    const free = row.status === 'free';
    const maintenance = row.status === 'maintenance';

    const started = parseUtc(row.start_time);
    const elapsed = started ? Math.max(0, Math.floor((now - started.getTime()) / 60000)) : row.elapsed_minutes;
    const overdue = row.planned_minutes != null && elapsed > row.planned_minutes;

    return (
        <div
            className={`relative flex flex-col rounded-xl border p-3 transition ${
                maintenance
                    ? 'border-amber-400/60 dark:border-amber-500/40'
                    : free
                      ? 'border-slate-200 hover:border-indigo-400 dark:border-slate-800 dark:hover:border-indigo-500/60'
                      : 'border-live-500/50 ct-live-ring'
            }`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 truncate text-sm font-semibold">
                        {!free && !maintenance && (
                            <span className="ct-live-dot h-1.5 w-1.5 shrink-0 rounded-full bg-live-500" aria-hidden="true" />
                        )}
                        {row.station_name}
                    </p>
                    <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        {/* An occupied booth shows what THIS player is being
                            charged, which is the rate for their controller
                            count — not the station's one-pad headline. The two
                            differ the moment somebody picks up a second pad. */}
                        {row.station_type} · {money(row.session_hourly_rate ?? row.hourly_rate)}/hr
                        {row.controllers > 1 && ` · ${row.controllers} pads`}
                    </p>
                </div>

                <button
                    onClick={onMaintenance}
                    title={row.maintenance ? 'Put back in service' : 'Mark out of service'}
                    aria-label={row.maintenance ? 'Put back in service' : 'Mark out of service'}
                    className={`shrink-0 rounded-lg p-1.5 transition ${
                        row.maintenance
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'
                            : 'text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800'
                    }`}
                >
                    <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <path d="M14.7 6.3a4 4 0 0 0 5 5l-9 9a2.8 2.8 0 1 1-4-4l9-9z" strokeLinejoin="round" />
                    </svg>
                </button>
            </div>

            {free && (
                <>
                    <p className="mt-3 flex-1 text-xs text-slate-400">Free</p>
                    <Button size="sm" className="mt-2.5 w-full" onClick={onStart}>
                        Start session
                    </Button>
                </>
            )}

            {maintenance && !row.session_id && (
                <p className="mt-3 text-xs text-amber-600 dark:text-amber-400">Out of service</p>
            )}

            {row.session_id && (
                <>
                    <div className="mt-2.5 flex flex-1 items-end justify-between gap-2">
                        <div className="min-w-0">
                            <p className="truncate text-xs text-slate-500">{row.customer_name}</p>
                            <p className="text-lg font-bold tabular-nums text-live-600 dark:text-live-400">
                                {duration(elapsed)}
                            </p>
                        </div>
                        <p className="shrink-0 text-lg font-bold tabular-nums">{money(row.running_cost)}</p>
                    </div>

                    {row.planned_minutes != null && (
                        <div className="mt-2">
                            <Meter
                                percent={(elapsed / row.planned_minutes) * 100}
                                tone={overdue ? 'rose' : 'live'}
                            />
                            <p className="mt-1 text-[10px] text-slate-500">
                                {overdue
                                    ? `Overdue by ${duration(elapsed - row.planned_minutes)}`
                                    : `${duration(row.planned_minutes - elapsed)} of ${duration(row.planned_minutes)} left`}
                            </p>
                        </div>
                    )}

                    <Button size="sm" variant="success" className="mt-2.5 w-full" onClick={onEnd}>
                        End session
                    </Button>
                </>
            )}
        </div>
    );
}

/* --------------------------------------------------------------- income card */

function IncomeCard({ rows, loading }) {
    const theme = useChartTheme();
    const [range, setRange] = useState(30);

    const all = (rows ?? []).map((r) => ({ label: day(r.date), value: Number(r.income) }));
    const series = all.slice(-range);

    const total = series.reduce((sum, d) => sum + d.value, 0);
    const best = series.reduce((max, d) => Math.max(max, d.value), 0);

    return (
        // A flex column so the chart grows to whatever height the card is given
        // by the row, rather than leaving a slab of empty card beneath it.
        <Card className="flex flex-col p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold">Takings</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Void invoices excluded.</p>
                </div>

                <div className="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800/70">
                    {[7, 14, 30].map((n) => (
                        <button
                            key={n}
                            onClick={() => setRange(n)}
                            className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                                range === n
                                    ? 'bg-indigo-600 text-white'
                                    : 'text-slate-500 hover:text-slate-900 dark:hover:text-slate-100'
                            }`}
                        >
                            {n}D
                        </button>
                    ))}
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <span className="text-2xl font-bold tabular-nums">{money(total)}</span>
                <span className="text-xs text-slate-500">
                    Best day <span className="font-semibold tabular-nums">{money(best)}</span>
                </span>
            </div>

            {loading && !rows ? (
                <Loading />
            ) : (
                <ResponsiveContainer width="100%" height="100%" minHeight={210} className="flex-1">
                    <AreaChart data={series} margin={{ top: 6, right: 6, bottom: 0, left: 0 }}>
                        <defs>
                            <linearGradient id="ct-income" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor={theme.series} stopOpacity="0.45" />
                                <stop offset="100%" stopColor={theme.series} stopOpacity="0" />
                            </linearGradient>
                        </defs>
                        <XAxis
                            dataKey="label"
                            stroke={theme.axis}
                            tick={{ fontSize: 10 }}
                            tickLine={false}
                            axisLine={false}
                            minTickGap={28}
                        />
                        <YAxis
                            stroke={theme.axis}
                            tick={{ fontSize: 10 }}
                            tickLine={false}
                            axisLine={false}
                            width={48}
                            tickFormatter={(v) => amount(v).split('.')[0]}
                        />
                        <Tooltip
                            cursor={{ stroke: theme.axis, strokeWidth: 1, strokeDasharray: '3 3' }}
                            content={({ active, payload, label }) =>
                                active && payload?.length ? (
                                    <div
                                        className="rounded-lg border px-3 py-2 text-xs shadow-lg"
                                        style={{ background: theme.surface, borderColor: theme.grid, color: theme.text }}
                                    >
                                        <p className="font-medium">{label}</p>
                                        <p className="mt-0.5 tabular-nums">{money(payload[0].value)}</p>
                                    </div>
                                ) : null
                            }
                        />
                        {/* One series, so no legend — the card title names it. */}
                        <Area
                            type="monotone"
                            dataKey="value"
                            stroke={theme.series}
                            strokeWidth={2}
                            fill="url(#ct-income)"
                            activeDot={{ r: 5, strokeWidth: 2, stroke: theme.surface }}
                        />
                    </AreaChart>
                </ResponsiveContainer>
            )}
        </Card>
    );
}

/* ----------------------------------------------------------------- today card */

/**
 * Who is signed in and what the till has taken — the old right rail, now a
 * dashboard card.
 *
 * It used to be pinned beside every screen, which meant a fixed column of
 * takings looming over Settings and Logs, where it means nothing. It belongs
 * next to the floor it describes and nowhere else.
 *
 * The daily income series is passed in rather than fetched again: the takings
 * chart above already has it, and two copies of the same figure that refresh on
 * different clocks are two figures that will eventually disagree on screen.
 */
function TodayCard({ income }) {
    const { session, isAdmin, cafeName } = useAuth();
    const shift = useAsync(() => api.currentShift(), []);

    // Same 5s cadence as the floor, so the card never lags the tiles beside it.
    usePolling(shift.reload, 5000);

    const totals = shift.data?.totals;
    const today = income?.[income.length - 1];

    const segments = [
        { label: 'Cash', value: totals?.cash_sales ?? 0 },
        { label: 'Phone', value: totals?.phone_sales ?? 0 },
        { label: 'Wallet', value: totals?.wallet_sales ?? 0 },
    ];

    return (
        <Card className="p-5">
            <div className="flex items-center gap-3">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-live-500 text-sm font-bold text-white shadow-[0_0_24px_-8px_var(--color-indigo-500)]">
                    {(session?.email ?? '?').slice(0, 2).toUpperCase()}
                </span>
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold" title={session?.email}>
                        {session?.email}
                    </p>
                    <p className="mt-0.5 flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        <span className="rounded-full bg-indigo-500/15 px-2 py-0.5 text-indigo-600 ring-1 ring-inset ring-indigo-500/30 dark:text-indigo-300">
                            {session?.role}
                        </span>
                        <span className="truncate">{cafeName || '—'}</span>
                    </p>
                </div>
            </div>

            <div className="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                    {shift.data ? 'Taken this shift' : 'Taken today'}
                </p>
                <p className="mt-1 text-3xl font-bold tabular-nums">
                    {money(totals?.total_sales ?? today?.income ?? 0)}
                </p>

                <div className="mt-4 grid grid-cols-3 gap-2">
                    <TodayStat label="Cash" value={money(totals?.cash_sales ?? 0)} />
                    <TodayStat label="Phone" value={money(totals?.phone_sales ?? 0)} />
                    <TodayStat label="Wallet" value={money(totals?.wallet_sales ?? 0)} />
                </div>
            </div>

            <div className="mt-5 border-t border-slate-200 pt-5 dark:border-slate-800">
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
                    className="mt-5 block overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-500 via-indigo-600 to-live-600 p-4 text-white transition hover:brightness-110"
                >
                    <p className="text-sm font-semibold leading-snug">See how the floor is really performing</p>
                    <p className="mt-1 text-xs text-white/80">Utilization, peak hours and gross margin.</p>
                </Link>
            )}
        </Card>
    );
}

function TodayStat({ label, value }) {
    return (
        <div>
            <p className="text-[9px] font-semibold uppercase tracking-[0.1em] text-slate-500">{label}</p>
            <p className="mt-0.5 truncate text-xs font-semibold tabular-nums">{value}</p>
        </div>
    );
}

/* ---------------------------------------------------------- utilization card */

function UtilizationCard({ rows, loading }) {
    const list = (rows ?? []).slice(0, 6);

    return (
        <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h2 className="text-sm font-semibold">Busiest devices</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Share of trading hours, last 7 days.</p>
                </div>
                <Link
                    to="/analytics"
                    className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                >
                    View all
                </Link>
            </div>

            {loading && !rows ? (
                <Loading />
            ) : list.length === 0 ? (
                <p className="py-10 text-center text-sm text-slate-500">No stations yet.</p>
            ) : (
                <ul className="space-y-3.5">
                    {list.map((row) => (
                        <li key={row.station_id}>
                            <div className="mb-1.5 flex items-baseline justify-between gap-3 text-xs">
                                <span className="truncate font-medium">{row.station_name}</span>
                                {/* Direct label, so the number is never colour-only. */}
                                <span className="shrink-0 tabular-nums text-slate-500">
                                    {row.utilization_percent}% · {money(row.revenue)}
                                </span>
                            </div>
                            <Meter percent={row.utilization_percent} />
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/* ---------------------------------------------------------------- floor table */

function FloorTable({ rows, now, onEnd, onStart }) {
    const occupied = rows.filter((r) => r.status === 'occupied');

    return (
        <Card className="overflow-hidden">
            <div className="flex items-center justify-between px-5 py-4">
                <h2 className="text-sm font-semibold">In play</h2>
                <Link
                    to="/sessions"
                    className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                >
                    Session history
                </Link>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse">
                    <thead className="border-y border-slate-200 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-950/40">
                        <tr>
                            <th className="ct-th">Station</th>
                            <th className="ct-th">Player</th>
                            <th className="ct-th text-right">Pads</th>
                            <th className="ct-th text-right">Elapsed</th>
                            <th className="ct-th text-right">Cost so far</th>
                            <th className="ct-th text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {occupied.map((row) => {
                            const started = parseUtc(row.start_time);
                            const elapsed = started
                                ? Math.max(0, Math.floor((now - started.getTime()) / 60000))
                                : row.elapsed_minutes;
                            const overdue = row.planned_minutes != null && elapsed > row.planned_minutes;

                            return (
                                <tr key={row.session_id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                    <td className="ct-td font-medium">{row.station_name}</td>
                                    <td className="ct-td">{row.customer_name}</td>
                                    <td className="ct-td text-right tabular-nums">{row.controllers}</td>
                                    <td className="ct-td text-right tabular-nums">
                                        {duration(elapsed)}
                                        {overdue && <Pill tone="red" className="ml-2">Overdue</Pill>}
                                    </td>
                                    <td className="ct-td text-right font-semibold tabular-nums">
                                        {money(row.running_cost)}
                                    </td>
                                    <td className="ct-td text-right">
                                        <Button size="sm" variant="success" onClick={() => onEnd(row)}>
                                            End
                                        </Button>
                                    </td>
                                </tr>
                            );
                        })}

                        {occupied.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-500">
                                    Nothing in play. Tap a free device above to start someone off.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------- attention card */

/** What a member of staff should walk over and deal with next. */
function AttentionCard({ rows, overdue }) {
    const unpaid = useAsync(() => api.invoices({ payment_status: 'unpaid', limit: 6 }), []);
    const maintenance = rows.filter((r) => r.maintenance);

    const items = [
        ...overdue.map((row) => ({
            key: `overdue-${row.session_id}`,
            tone: 'red',
            tag: 'Overdue',
            title: `${row.customer_name} on ${row.station_name}`,
            detail: `${duration(row.elapsed_minutes - row.planned_minutes)} past the booked time`,
            to: '/',
        })),
        ...(unpaid.data ?? []).map((invoice) => ({
            key: `unpaid-${invoice.id}`,
            tone: 'amber',
            tag: 'Unpaid',
            title: `Invoice #${invoice.id} — ${invoice.customer_name ?? 'walk-in'}`,
            detail: `${money(invoice.total_amount)} still owed`,
            to: '/invoices',
        })),
        ...maintenance.map((row) => ({
            key: `maint-${row.station_id}`,
            tone: 'slate',
            tag: 'Down',
            title: row.station_name,
            detail: 'Out of service',
            to: '/stations',
        })),
    ];

    return (
        <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h2 className="text-sm font-semibold">Needs attention</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Overdue play, unpaid bills, devices down.</p>
                </div>
                {items.length > 0 && <Pill tone="indigo">{items.length}</Pill>}
            </div>

            {items.length === 0 ? (
                <p className="py-10 text-center text-sm text-slate-500">All clear.</p>
            ) : (
                <ul className="space-y-2">
                    {items.slice(0, 6).map((item) => (
                        <li key={item.key}>
                            <Link
                                to={item.to}
                                className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 transition
                                           hover:border-indigo-400 dark:border-slate-800 dark:hover:border-indigo-500/60"
                            >
                                <Pill tone={item.tone}>{item.tag}</Pill>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-xs font-medium">{item.title}</span>
                                    <span className="block truncate text-[11px] text-slate-500">{item.detail}</span>
                                </span>
                                <span className="shrink-0 text-slate-400">›</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/* -------------------------------------------------------------------- modals */

function StartSessionModal({ row, onClose, onDone }) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [controllers, setControllers] = useState(1);
    const [planned, setPlanned] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const max = row?.max_controllers ?? 1;

    // Show the rate the player will actually pay as they change the picker.
    const effective = rateFor(row, controllers);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.checkin(row.station_id, {
                name: name.trim(),
                phone_or_id: phone.trim(),
                controllers,
                ...(planned ? { planned_minutes: Number(planned) } : {}),
            });
            onDone();
            close();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setName('');
        setPhone('');
        setControllers(1);
        setPlanned('');
        setError(null);
        onClose();
    }

    return (
        <Modal open={Boolean(row)} title={`Start on ${row?.station_name ?? ''}`} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Player name">
                    <Input required autoFocus maxLength={150} value={name} onChange={(e) => setName(e.target.value)} />
                </Field>

                <Field label="Phone or ID">
                    <Input required maxLength={100} value={phone} onChange={(e) => setPhone(e.target.value)} />
                </Field>

                <Field label="Controllers">
                    <div className="flex flex-wrap gap-2">
                        {Array.from({ length: max }, (_, i) => i + 1).map((n) => (
                            <button
                                key={n}
                                type="button"
                                onClick={() => setControllers(n)}
                                className={`h-11 w-11 rounded-xl border text-sm font-semibold transition ${
                                    controllers === n
                                        ? 'border-indigo-500 bg-indigo-600 text-white shadow-[0_0_18px_-6px_var(--color-indigo-500)]'
                                        : 'border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'
                                }`}
                            >
                                {n}
                            </button>
                        ))}
                    </div>
                    <p className="mt-2.5 text-base font-bold tabular-nums text-indigo-600 dark:text-indigo-300">
                        {money(effective)}
                        <span className="ml-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                            per hour
                        </span>
                    </p>
                </Field>

                <Field label="Planned minutes" hint="Optional — the floor flags a session that runs past it.">
                    <Input
                        type="number"
                        min={5}
                        max={1440}
                        value={planned}
                        onChange={(e) => setPlanned(e.target.value)}
                        placeholder="e.g. 60"
                    />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Start
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

/**
 * Ending a session.
 *
 * The floor shows a running cost, which is deliberately unrounded — it is a
 * ticking display, not a bill. This screen shows the BILL: the same §5.1
 * pipeline the checkout runs, quoted from the server so the figure the operator
 * reads out is the figure that gets charged, itemised so they can say why.
 *
 * After it ends, the invoice is offered as a PDF without leaving the screen —
 * the receipt is wanted at the counter, not three clicks later on another one.
 */
function EndSessionModal({ row, onClose, onDone }) {
    const { cafeName, isAdmin } = useAuth();
    const [method, setMethod] = useState('cash');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [invoice, setInvoice] = useState(null);
    const [savingPdf, setSavingPdf] = useState(false);
    const [discount, setDiscount] = useState(BLANK_DISCOUNT);

    // Only send it once it is complete, so a half-typed reason does not make
    // every keystroke a 422.
    const ready = Boolean(discount.on && discount.value && discount.reason.trim().length >= 3);
    const applied = useDebounced(ready ? discount : null, 400);

    // Re-quoted every time the dialog opens, and again whenever the discount
    // settles: the clock has moved since the tile last refreshed, and the
    // server does the discount arithmetic so the preview cannot drift from
    // what the checkout charges.
    const quote = useAsync(
        () => (row?.session_id ? api.sessionQuote(row.session_id, applied) : Promise.resolve(null)),
        [row?.session_id, applied?.kind, applied?.value, applied?.reason],
    );

    const bill = quote.data;

    async function end(takePayment) {
        setBusy(true);
        setError(null);

        try {
            // wallet is never sent from here — it is set by the pay-from-wallet
            // action on the invoice itself.
            const created = await api.checkout(row.session_id, takePayment ? method : null, applied);
            setInvoice(created);
            onDone();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    async function downloadPdf() {
        setSavingPdf(true);
        setError(null);

        try {
            const response = await api.invoicePdf(invoice.id);
            await saveResponseAs(response, `invoice-${invoice.id}.pdf`);
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setSavingPdf(false);
        }
    }

    function close() {
        setInvoice(null);
        setError(null);
        setMethod('cash');
        setDiscount(BLANK_DISCOUNT);
        onClose();
    }

    // Settled: show what was charged and offer the receipt.
    if (invoice) {
        return (
            <Modal open title="Session ended" onClose={close}>
                <div className="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-center dark:border-emerald-500/40 dark:bg-emerald-500/10">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-emerald-700 dark:text-emerald-400">
                        Invoice #{invoice.id} · {invoice.payment_status === 'paid' ? 'Paid' : 'Unpaid'}
                    </p>
                    <p className="mt-1 text-3xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">
                        {money(invoice.total_amount)}
                    </p>
                </div>

                {error && <p className="mt-3 text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <Button variant="outline" busy={savingPdf} onClick={downloadPdf}>
                        Download PDF
                    </Button>
                    <Button variant="subtle" onClick={() => printInvoice(invoice, cafeName)}>
                        Print receipt
                    </Button>
                    <Button onClick={close}>Done</Button>
                </div>
            </Modal>
        );
    }

    return (
        <Modal open={Boolean(row)} title={`End ${row?.customer_name ?? 'session'}`} onClose={close}>
            <p className="text-sm text-slate-600 dark:text-slate-400">
                {row?.station_name}
                {bill ? ` · ${bill.controllers} controller${bill.controllers === 1 ? '' : 's'} · ${money(bill.hourly_rate)}/hr` : ''}
            </p>

            {quote.loading && !bill ? (
                <Loading label="Working out the bill…" />
            ) : (
                bill && (
                    <div className="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                        <BillLine
                            label="Time played"
                            note={
                                bill.billed_minutes !== bill.actual_minutes
                                    ? `${duration(bill.actual_minutes)}, rounded up to a ${bill.block_minutes}-minute block`
                                    : 'to the minute'
                            }
                            value={duration(bill.billed_minutes)}
                        />

                        <BillLine
                            label={`${duration(bill.billed_minutes)} at ${money(bill.hourly_rate)}/hr`}
                            value={money(bill.gross)}
                        />

                        {Number(bill.rounding) !== 0 && (
                            <BillLine
                                label="Rounding"
                                note={`to the nearest ${money(bill.round_amount_to)}`}
                                value={`${Number(bill.rounding) > 0 ? '+' : '−'}${money(Math.abs(Number(bill.rounding)))}`}
                            />
                        )}

                        {Number(bill.discount) > 0 && (
                            <BillLine
                                label={bill.discount_reason ?? 'Discount'}
                                note={
                                    // A typed discount replaces the tier's, so
                                    // say so rather than dropping it silently.
                                    bill.discount_is_manual && Number(bill.tier_discount) > 0
                                        ? `instead of ${bill.tier_discount_reason} (−${money(bill.tier_discount)})`
                                        : undefined
                                }
                                value={`−${money(bill.discount)}`}
                                tone="good"
                            />
                        )}

                        <div className="mt-3 flex items-baseline justify-between border-t border-slate-200 pt-3 dark:border-slate-800">
                            <span className="text-sm font-semibold">To collect</span>
                            <span className="text-3xl font-bold tabular-nums">{money(bill.total)}</span>
                        </div>
                    </div>
                )
            )}

            <ErrorNote error={quote.error} onRetry={quote.reload} />

            {/* Money given away is not a floor decision — the API refuses it
                from staff too, so this is only the presentation half. */}
            {isAdmin && <DiscountFields value={discount} onChange={setDiscount} />}

            <div className="mt-4">
                <Field label="Payment">
                    <Select value={method} onChange={(e) => setMethod(e.target.value)}>
                        <option value="cash">Cash</option>
                        <option value="phone_payment">Phone payment</option>
                    </Select>
                </Field>
            </div>

            {error && <p className="mt-3 text-sm text-rose-600 dark:text-rose-400">{error}</p>}

            <div className="mt-5 flex flex-wrap justify-end gap-2">
                <Button variant="outline" onClick={close} disabled={busy}>
                    Cancel
                </Button>
                <Button variant="subtle" busy={busy} onClick={() => end(false)}>
                    End, pay later
                </Button>
                <Button variant="success" busy={busy} disabled={!bill} onClick={() => end(true)}>
                    {bill ? `Take ${money(bill.total)}` : 'End & take payment'}
                </Button>
            </div>
        </Modal>
    );
}

const BLANK_DISCOUNT = { on: false, kind: 'amount', value: '', reason: '' };

/**
 * Hold a value still for a moment before acting on it.
 *
 * Without this the dialog re-quotes on every keystroke of the reason, which is
 * a request per character for a number that has not changed.
 */
function useDebounced(value, delay) {
    const [settled, setSettled] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setSettled(value), delay);
        return () => clearTimeout(timer);
        // Compared by content, not identity: the caller rebuilds the object on
        // every render, so an identity check would never settle.
    }, [JSON.stringify(value ?? null), delay]);

    return settled;
}

/** Admin-only: knock something off this bill, with a reason worth reading. */
function DiscountFields({ value, onChange }) {
    const set = (key) => (event) => onChange({ ...value, [key]: event.target.value });

    if (!value.on) {
        return (
            <button
                type="button"
                onClick={() => onChange({ ...BLANK_DISCOUNT, on: true })}
                className="mt-3 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
            >
                + Add a discount
            </button>
        );
    }

    return (
        <div className="mt-3 rounded-xl border border-indigo-300 bg-indigo-50/50 p-3 dark:border-indigo-500/40 dark:bg-indigo-500/5">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                    Discount
                </span>
                <button
                    type="button"
                    onClick={() => onChange(BLANK_DISCOUNT)}
                    className="text-xs font-semibold text-slate-500 hover:underline"
                >
                    Remove
                </button>
            </div>

            <div className="grid grid-cols-[7rem_minmax(0,1fr)] gap-2">
                <Select value={value.kind} onChange={set('kind')}>
                    <option value="amount">৳ off</option>
                    <option value="percent">% off</option>
                </Select>

                <Input
                    autoFocus
                    type="number"
                    min="0"
                    step="0.01"
                    max={value.kind === 'percent' ? 100 : undefined}
                    value={value.value}
                    onChange={set('value')}
                    placeholder={value.kind === 'percent' ? '10' : '50'}
                />
            </div>

            <div className="mt-2">
                <Input
                    maxLength={200}
                    value={value.reason}
                    onChange={set('reason')}
                    placeholder="Reason — e.g. regular customer"
                />
            </div>

            {value.value && value.reason.trim().length < 3 && (
                <p className="mt-1.5 text-xs text-slate-500">
                    A reason of at least 3 characters applies it.
                </p>
            )}
        </div>
    );
}

function BillLine({ label, note, value, tone }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <span className="min-w-0">
                <span className="text-sm">{label}</span>
                {note && <span className="block text-xs text-slate-500">{note}</span>}
            </span>
            <span
                className={`shrink-0 tabular-nums ${
                    tone === 'good' ? 'text-emerald-600 dark:text-emerald-400' : ''
                }`}
            >
                {value}
            </span>
        </div>
    );
}
