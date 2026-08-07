import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/hooks';
import { useChartTheme, rampStep } from '../lib/charts';
import { amount, day, money } from '../lib/format';
import { Card, ErrorNote, Field, Loading, PageHeader, Select, Stat, Table } from '../components/ui';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function Analytics() {
    const { isAdmin } = useAuth();
    const [days, setDays] = useState(30);
    const params = { days };

    const profit = useAsync(() => api.profit(params), [days]);
    const daily = useAsync(() => api.dailyIncome(params), [days]);
    const utilization = useAsync(() => api.utilization(params), [days]);
    const peak = useAsync(() => api.peakHours(params), [days]);
    const stations = useAsync(() => api.topStations({ ...params, limit: 10 }), [days]);
    const customers = useAsync(() => api.topCustomers({ ...params, limit: 10 }), [days]);

    const anyError = profit.error || daily.error || utilization.error || peak.error;

    return (
        <>
            <PageHeader title="Analytics" subtitle="Void invoices are excluded from every figure here.">
                <Field label="">
                    <Select value={days} onChange={(e) => setDays(Number(e.target.value))}>
                        <option value={7}>Last 7 days</option>
                        <option value={30}>Last 30 days</option>
                        <option value={90}>Last 90 days</option>
                        <option value={365}>Last year</option>
                    </Select>
                </Field>
            </PageHeader>

            <ErrorNote error={anyError} onRetry={() => (profit.reload(), daily.reload())} />

            <ProfitTiles data={profit.data} loading={profit.loading} />

            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <IncomeChart rows={daily.data} loading={daily.loading} />
                <HoursChart rows={daily.data} loading={daily.loading} />
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <UtilizationChart rows={utilization.data} loading={utilization.loading} />
                <PeakHeatmap cells={peak.data} loading={peak.loading} />
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <RankTable
                    title="Most-used stations"
                    rows={stations.data}
                    loading={stations.loading}
                    columns={[
                        ['Station', (r) => r.station_name],
                        ['Sessions', (r) => r.sessions, true],
                        ['Hours', (r) => r.hours, true],
                        ['Revenue', (r) => money(r.revenue), true],
                    ]}
                />
                <RankTable
                    title="Top customers"
                    rows={customers.data}
                    loading={customers.loading}
                    columns={[
                        ['Customer', (r) => r.customer_name],
                        ['Visits', (r) => r.visits, true],
                        ['Spend', (r) => money(r.spend), true],
                    ]}
                />
            </div>

            {isAdmin && <StaffRollup days={days} />}
        </>
    );
}

function ProfitTiles({ data, loading }) {
    if (loading && !data) return <Loading />;
    if (!data) return null;

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Stat label="Total revenue" value={money(data.total_revenue)} hint="Play + items − discounts" />
            <Stat label="Play revenue" value={money(data.play_revenue)} />
            <Stat label="Cost of goods" value={money(data.items_cost)} hint="Snapshotted at each sale" />
            <Stat
                label="Gross profit"
                value={money(data.gross_profit)}
                tone="good"
                hint={`${data.margin_percent}% margin`}
            />
        </div>
    );
}

/** A shared tooltip so every chart reads the same way. */
function ChartTooltip({ active, payload, label, theme, format }) {
    if (!active || !payload?.length) return null;

    return (
        <div
            className="rounded-lg border px-3 py-2 text-xs shadow-lg"
            style={{ background: theme.surface, borderColor: theme.grid, color: theme.text }}
        >
            <p className="font-medium">{label}</p>
            <p className="mt-0.5 tabular-nums">{format(payload[0].value)}</p>
        </div>
    );
}

function ChartCard({ title, subtitle, loading, empty, children }) {
    return (
        <Card className="p-5">
            <h3 className="text-sm font-semibold">{title}</h3>
            {subtitle && <p className="mt-0.5 mb-3 text-xs text-slate-500">{subtitle}</p>}
            {loading ? <Loading /> : empty ? <p className="py-12 text-center text-sm text-slate-500">{empty}</p> : children}
        </Card>
    );
}

function IncomeChart({ rows, loading }) {
    const theme = useChartTheme();
    const data = (rows ?? []).map((r) => ({ ...r, label: day(r.date), value: Number(r.income) }));

    return (
        <ChartCard
            title="Daily income"
            subtitle="Total billed per day, void invoices excluded."
            loading={loading && !rows}
            empty={!data.length ? 'Nothing to chart yet.' : null}
        >
            <ResponsiveContainer width="100%" height={240}>
                <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <CartesianGrid stroke={theme.grid} strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="label" stroke={theme.axis} tick={{ fontSize: 11 }} tickLine={false} axisLine={false} minTickGap={24} />
                    <YAxis stroke={theme.axis} tick={{ fontSize: 11 }} tickLine={false} axisLine={false} width={56} tickFormatter={(v) => amount(v).split('.')[0]} />
                    <Tooltip
                        cursor={{ stroke: theme.axis, strokeWidth: 1, strokeDasharray: '3 3' }}
                        content={(props) => <ChartTooltip {...props} theme={theme} format={money} />}
                    />
                    {/* One series, so no legend — the title names it. */}
                    <Line
                        type="monotone"
                        dataKey="value"
                        stroke={theme.series}
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 4, strokeWidth: 2, stroke: theme.surface }}
                    />
                </LineChart>
            </ResponsiveContainer>
        </ChartCard>
    );
}

function HoursChart({ rows, loading }) {
    const theme = useChartTheme();
    const data = (rows ?? []).map((r) => ({ label: day(r.date), value: Number(r.hours) }));

    return (
        <ChartCard
            title="Hours played"
            subtitle="Billed time per day."
            loading={loading && !rows}
            empty={!data.length ? 'Nothing to chart yet.' : null}
        >
            <ResponsiveContainer width="100%" height={240}>
                <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <CartesianGrid stroke={theme.grid} strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="label" stroke={theme.axis} tick={{ fontSize: 11 }} tickLine={false} axisLine={false} minTickGap={24} />
                    <YAxis stroke={theme.axis} tick={{ fontSize: 11 }} tickLine={false} axisLine={false} width={40} />
                    <Tooltip
                        cursor={{ fill: theme.grid, opacity: 0.4 }}
                        content={(props) => <ChartTooltip {...props} theme={theme} format={(v) => `${v} h`} />}
                    />
                    {/* 4px rounded data-end, anchored to the baseline. */}
                    <Bar dataKey="value" fill={theme.series} radius={[4, 4, 0, 0]} maxBarSize={26} />
                </BarChart>
            </ResponsiveContainer>
        </ChartCard>
    );
}

function UtilizationChart({ rows, loading }) {
    const theme = useChartTheme();
    const data = rows ?? [];

    return (
        <ChartCard
            title="Station utilization"
            subtitle="Share of trading hours in play — not of 24 hours."
            loading={loading && !rows}
            empty={!data.length ? 'No stations yet.' : null}
        >
            <ul className="space-y-3">
                {data.map((row) => (
                    <li key={row.station_id}>
                        <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                            <span className="truncate">{row.station_name}</span>
                            {/* Direct label, so the number is never colour-only. */}
                            <span className="shrink-0 tabular-nums text-slate-500">
                                {row.utilization_percent}% · {money(row.revenue)}
                            </span>
                        </div>
                        <div className="h-2 overflow-hidden rounded-full" style={{ background: theme.empty }}>
                            <div
                                className="h-full rounded-full"
                                style={{
                                    width: `${Math.max(1, row.utilization_percent)}%`,
                                    background: theme.series,
                                }}
                            />
                        </div>
                        <p className="mt-0.5 text-xs text-slate-500">
                            {row.occupied_hours} of {row.available_hours} h
                        </p>
                    </li>
                ))}
            </ul>
        </ChartCard>
    );
}

function PeakHeatmap({ cells, loading }) {
    const theme = useChartTheme();
    const data = cells ?? [];

    // The scale is relative to the busiest cell, so a quiet cafe still shows
    // shape rather than a uniform pale grid.
    const peak = data.reduce((max, c) => Math.max(max, c.hours), 0);

    const byDay = WEEKDAYS.map((_, weekday) =>
        Array.from({ length: 24 }, (_, hour) => data.find((c) => c.weekday === weekday && c.hour === hour) ?? { hours: 0 }),
    );

    return (
        <ChartCard
            title="Peak hours"
            subtitle="Hours in play by weekday and hour of day."
            loading={loading && !cells}
            empty={!data.length ? 'Nothing to chart yet.' : null}
        >
            <div className="overflow-x-auto">
                <div className="min-w-[520px]">
                    <div className="mb-1 flex gap-[2px] pl-9">
                        {Array.from({ length: 24 }, (_, h) => (
                            <span key={h} className="w-4 text-center text-[9px] text-slate-400">
                                {h % 3 === 0 ? h : ''}
                            </span>
                        ))}
                    </div>

                    {byDay.map((row, weekday) => (
                        <div key={weekday} className="mb-[2px] flex items-center gap-[2px]">
                            <span className="w-9 shrink-0 text-[10px] text-slate-500">{WEEKDAYS[weekday]}</span>
                            {row.map((cell, hour) => {
                                const colour = rampStep(theme.ramp, peak > 0 ? cell.hours / peak : 0);

                                return (
                                    <span
                                        key={hour}
                                        title={`${WEEKDAYS[weekday]} ${String(hour).padStart(2, '0')}:00 — ${cell.hours} h`}
                                        className="h-4 w-4 shrink-0 rounded-[3px]"
                                        style={{ background: colour ?? theme.empty }}
                                    />
                                );
                            })}
                        </div>
                    ))}

                    <div className="mt-3 flex items-center gap-2 pl-9 text-[10px] text-slate-500">
                        <span>Quiet</span>
                        {theme.ramp.map((c) => (
                            <span key={c} className="h-3 w-4 rounded-[2px]" style={{ background: c }} />
                        ))}
                        <span>Busy</span>
                        <span className="ml-auto">peak {peak.toFixed(1)} h</span>
                    </div>
                </div>
            </div>
        </ChartCard>
    );
}

function RankTable({ title, rows, loading, columns }) {
    return (
        <ChartCard title={title} loading={loading && !rows} empty={!rows?.length ? 'Nothing yet.' : null}>
            <Table
                colSpan={columns.length}
                head={columns.map(([label, , numeric]) => (
                    <th key={label} className={`ct-th ${numeric ? 'text-right' : ''}`}>
                        {label}
                    </th>
                ))}
            >
                {(rows ?? []).map((row, index) => (
                    <tr key={index}>
                        {columns.map(([label, get, numeric]) => (
                            <td key={label} className={`ct-td ${numeric ? 'text-right tabular-nums' : ''}`}>
                                {get(row)}
                            </td>
                        ))}
                    </tr>
                ))}
            </Table>
        </ChartCard>
    );
}

function StaffRollup({ days }) {
    const { data, loading } = useAsync(() => api.staffAnalytics({ days }), [days]);

    return (
        <div className="mt-4">
            <ChartCard
                title="Staff"
                subtitle="From closed shifts only — an open shift has no variance yet."
                loading={loading && !data}
                empty={!data?.length ? 'No closed shifts in this period.' : null}
            >
                <Table
                    colSpan={5}
                    head={
                        <>
                            <th className="ct-th">Who</th>
                            <th className="ct-th text-right">Shifts</th>
                            <th className="ct-th text-right">Sales</th>
                            <th className="ct-th text-right">Cash collected</th>
                            <th className="ct-th text-right">Variance</th>
                        </>
                    }
                >
                    {(data ?? []).map((row) => (
                        <tr key={row.email}>
                            <td className="ct-td">{row.email}</td>
                            <td className="ct-td text-right tabular-nums">{row.shifts}</td>
                            <td className="ct-td text-right tabular-nums">{money(row.total_sales)}</td>
                            <td className="ct-td text-right tabular-nums">{money(row.cash_collected)}</td>
                            <td
                                className={`ct-td text-right font-medium tabular-nums ${
                                    Number(row.total_variance) === 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-rose-600 dark:text-rose-400'
                                }`}
                            >
                                {money(row.total_variance)}
                            </td>
                        </tr>
                    ))}
                </Table>
            </ChartCard>
        </div>
    );
}
