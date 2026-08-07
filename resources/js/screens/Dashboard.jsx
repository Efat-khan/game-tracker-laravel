import { useMemo, useRef, useState } from 'react';
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync, useNow, usePolling } from '../lib/hooks';
import { useChartTheme } from '../lib/charts';
import { amount, day, duration, money, parseUtc } from '../lib/format';
import { Link } from '../lib/router';
import { Meter } from '../components/viz';
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

            <StationStrip
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
                <UtilizationCard rows={utilization.data} loading={utilization.loading} />
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                <FloorTable rows={visible} now={now} onEnd={setEnding} onStart={setStarting} />
                <AttentionCard rows={rows} overdue={summary.overdue} />
            </div>

            <StartSessionModal row={starting} onClose={() => setStarting(null)} onDone={reload} />
            <EndSessionModal row={ending} onClose={() => setEnding(null)} onDone={reload} />
        </>
    );
}

/* ------------------------------------------------------------- ticker strip */

/**
 * The horizontal strip of devices, scrolled with the arrows at its right.
 *
 * Free cards are clickable in their entirety, which is how staff start a
 * session fastest — no aiming at a button.
 */
function StationStrip({ rows, now, onStart, onEnd, onMaintenance }) {
    const track = useRef(null);

    const scroll = (direction) =>
        track.current?.scrollBy({ left: direction * 320, behavior: 'smooth' });

    return (
        <Card className="p-3">
            <div className="flex items-center gap-3">
                <div className="hidden shrink-0 pl-2 pr-1 sm:block">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        The floor
                    </p>
                    <p className="mt-0.5 text-xs text-slate-400">{rows.length} devices</p>
                </div>

                <div
                    ref={track}
                    className="flex flex-1 gap-3 overflow-x-auto scroll-smooth pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                >
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

                    {rows.length === 0 && (
                        <p className="px-3 py-6 text-sm text-slate-500">Nothing matches that search.</p>
                    )}
                </div>

                <div className="flex shrink-0 gap-1">
                    <ArrowButton onClick={() => scroll(-1)} label="Scroll left" d="M15 6l-6 6 6 6" />
                    <ArrowButton onClick={() => scroll(1)} label="Scroll right" d="M9 6l6 6-6 6" />
                </div>
            </div>
        </Card>
    );
}

function ArrowButton({ onClick, label, d }) {
    return (
        <button
            onClick={onClick}
            aria-label={label}
            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500
                       transition hover:bg-slate-100 hover:text-slate-900
                       dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-100"
        >
            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d={d} strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        </button>
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
            className={`relative w-[15.5rem] shrink-0 rounded-xl border p-3 transition ${
                maintenance
                    ? 'border-amber-400/60 dark:border-amber-500/40'
                    : free
                      ? 'cursor-pointer border-slate-200 hover:border-indigo-400 dark:border-slate-800 dark:hover:border-indigo-500/60'
                      : 'border-live-500/50 ct-live-ring'
            }`}
            onClick={free ? onStart : undefined}
            role={free ? 'button' : undefined}
            tabIndex={free ? 0 : undefined}
            onKeyDown={free ? (e) => (e.key === 'Enter' || e.key === ' ') && onStart() : undefined}
            aria-label={free ? `Start a session on ${row.station_name}` : undefined}
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
                        {row.station_type} · {money(row.hourly_rate)}/hr
                    </p>
                </div>

                <button
                    onClick={(e) => {
                        e.stopPropagation();
                        onMaintenance();
                    }}
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
                <div className="mt-3 flex items-center justify-between">
                    <span className="text-xs text-slate-400">Free</span>
                    <span className="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Start →</span>
                </div>
            )}

            {maintenance && !row.session_id && (
                <p className="mt-3 text-xs text-amber-600 dark:text-amber-400">Out of service</p>
            )}

            {row.session_id && (
                <>
                    <div className="mt-2.5 flex items-end justify-between gap-2">
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

                    <Button
                        size="sm"
                        variant="success"
                        className="mt-2.5 w-full"
                        onClick={(e) => {
                            e.stopPropagation();
                            onEnd();
                        }}
                    >
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
        <Card className="p-5">
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
                <ResponsiveContainer width="100%" height={210}>
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
    const effective =
        Number(row?.hourly_rate ?? 0) + Number(row?.extra_controller_rate ?? 0) * Math.max(0, controllers - 1);

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

function EndSessionModal({ row, onClose, onDone }) {
    const [method, setMethod] = useState('cash');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function end(takePayment) {
        setBusy(true);
        setError(null);

        try {
            // wallet is never sent from here — it is set by the pay-from-wallet
            // action on the invoice itself.
            await api.checkout(row.session_id, takePayment ? method : null);
            onDone();
            onClose();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open={Boolean(row)} title={`End ${row?.customer_name ?? 'session'}`} onClose={onClose}>
            <p className="text-sm text-slate-600 dark:text-slate-400">
                {row?.station_name} · {duration(row?.elapsed_minutes)} so far ·{' '}
                <span className="font-semibold">{money(row?.running_cost)}</span> at the current rate.
            </p>
            <p className="mt-2 text-xs text-slate-500">
                The final bill rounds the time up to a whole block and the amount to the nearest step, so it may
                differ slightly from the running figure.
            </p>

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
                <Button variant="outline" onClick={onClose} disabled={busy}>
                    Cancel
                </Button>
                <Button variant="subtle" busy={busy} onClick={() => end(false)}>
                    End, pay later
                </Button>
                <Button variant="success" busy={busy} onClick={() => end(true)}>
                    End &amp; take payment
                </Button>
            </div>
        </Modal>
    );
}
