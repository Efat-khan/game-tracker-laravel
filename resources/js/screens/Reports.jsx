import { useState } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../lib/hooks';
import { dateTime, money } from '../lib/format';
import { Card, ErrorNote, Input, Loading, PageHeader, Pill, Stat, Table } from '../components/ui';

/**
 * The two summary sheets — a day and a month.
 *
 * Deliberately tables and not charts. Analytics answers "how are we doing";
 * this answers "what happened, exactly", in the shape somebody settles up
 * against at closing time and reconciles against at month end.
 */
export default function Reports() {
    const [tab, setTab] = useState('daily');

    return (
        <>
            <PageHeader
                title="Summary"
                subtitle="The day and the month, laid out as a sheet you can read down."
            >
                <div className="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800/70">
                    {[
                        ['daily', 'Daily'],
                        ['monthly', 'Monthly'],
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setTab(key)}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                tab === key
                                    ? 'bg-indigo-600 text-white'
                                    : 'text-slate-500 hover:text-slate-900 dark:hover:text-slate-100'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </PageHeader>

            {tab === 'daily' ? <DailySheet /> : <MonthlySheet />}
        </>
    );
}

/* ------------------------------------------------------------------- daily */

function DailySheet() {
    const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
    const { data, error, loading, reload } = useAsync(() => api.dailySummary(date), [date]);

    const totals = data?.totals;

    return (
        <>
            <Card className="mb-4 flex flex-wrap items-end gap-4 p-4">
                <label className="block">
                    <span className="ct-label">Day</span>
                    <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="ct-input" />
                </label>

                <button
                    onClick={() => setDate(new Date().toISOString().slice(0, 10))}
                    className="pb-2 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                >
                    Today
                </button>
            </Card>

            <ErrorNote error={error} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <>
                    <TotalsRow totals={totals} />

                    <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <DeviceTable rows={data?.devices} />
                        <MovementTable rows={data?.expenses} />
                    </div>
                </>
            )}
        </>
    );
}

/* ----------------------------------------------------------------- monthly */

function MonthlySheet() {
    const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
    const { data, error, loading, reload } = useAsync(() => api.monthlySummary(month), [month]);

    const days = data?.days ?? [];
    // Empty days are kept in the payload so the ledger has no gaps, but the
    // table hides them behind a toggle — thirty blank rows bury the real ones.
    const [showEmpty, setShowEmpty] = useState(false);
    const visible = showEmpty ? days : days.filter((d) => d.sessions > 0 || Number(d.expenses) > 0);

    return (
        <>
            <Card className="mb-4 flex flex-wrap items-end gap-4 p-4">
                <label className="block">
                    <span className="ct-label">Month</span>
                    <Input
                        type="month"
                        value={month}
                        onChange={(e) => setMonth(e.target.value)}
                        className="ct-input"
                    />
                </label>

                <label className="flex items-center gap-2 pb-2 text-xs text-slate-600 dark:text-slate-300">
                    <input
                        type="checkbox"
                        checked={showEmpty}
                        onChange={(e) => setShowEmpty(e.target.checked)}
                        className="h-4 w-4 rounded"
                    />
                    Show days with no trade
                </label>
            </Card>

            <ErrorNote error={error} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <>
                    <TotalsRow totals={data?.totals} />

                    <Card className="mt-4 overflow-hidden">
                        <div className="px-5 py-4">
                            <h2 className="text-sm font-semibold">Day by day</h2>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {visible.length} of {days.length} days shown.
                            </p>
                        </div>

                        <Table
                            colSpan={6}
                            empty={visible.length === 0 ? 'Nothing traded this month.' : null}
                            head={
                                <>
                                    <th className="ct-th">Date</th>
                                    <th className="ct-th text-right">Sessions</th>
                                    <th className="ct-th text-right">Hours</th>
                                    <th className="ct-th text-right">Income</th>
                                    <th className="ct-th text-right">Expenses</th>
                                    <th className="ct-th text-right">Net</th>
                                </>
                            }
                        >
                            {visible.map((row) => (
                                <tr key={row.date} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                    <td className="ct-td whitespace-nowrap font-medium">{row.date}</td>
                                    <td className="ct-td text-right tabular-nums">{row.sessions}</td>
                                    <td className="ct-td text-right tabular-nums">{row.hours}</td>
                                    <td className="ct-td text-right tabular-nums">{money(row.income)}</td>
                                    <td
                                        className={`ct-td text-right tabular-nums ${
                                            Number(row.expenses) > 0
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : 'text-slate-400'
                                        }`}
                                    >
                                        {Number(row.expenses) > 0 ? `−${money(row.expenses)}` : money(0)}
                                    </td>
                                    <td className="ct-td text-right font-semibold tabular-nums">{money(row.net)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <div className="mt-4">
                        <DeviceTable rows={data?.devices} title="By device, this month" />
                    </div>
                </>
            )}
        </>
    );
}

/* ------------------------------------------------------------------ shared */

function TotalsRow({ totals }) {
    if (!totals) return null;

    const net = Number(totals.net);

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="Income" value={money(totals.income)} hint={`${totals.sessions} sessions`} />
                <Stat
                    label="Expenses"
                    value={money(totals.expenses)}
                    tone={Number(totals.expenses) > 0 ? 'bad' : 'default'}
                    hint="Cash paid out of the drawer"
                />
                <Stat label="Net" value={money(totals.net)} tone={net < 0 ? 'bad' : 'good'} hint="Income − expenses" />
                <Stat label="Hours played" value={totals.hours} hint="Across every device" />
            </div>

            <Card className="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 p-4 text-sm">
                <span className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                    How it arrived
                </span>
                <Split label="Cash" value={totals.cash_sales} />
                <Split label="Phone" value={totals.phone_sales} />
                <Split label="Wallet" value={totals.wallet_sales} />
                <Split label="Cash in" value={totals.cash_in} />
                {Number(totals.unpaid) > 0 && (
                    <span className="flex items-center gap-2">
                        <Pill tone="amber">Unpaid</Pill>
                        <span className="font-semibold tabular-nums">{money(totals.unpaid)}</span>
                    </span>
                )}
            </Card>
        </>
    );
}

function Split({ label, value }) {
    return (
        <span className="flex items-baseline gap-1.5">
            <span className="text-xs text-slate-500">{label}</span>
            <span className="font-semibold tabular-nums">{money(value)}</span>
        </span>
    );
}

function DeviceTable({ rows, title = 'By device' }) {
    const list = rows ?? [];

    return (
        <Card className="overflow-hidden">
            <div className="px-5 py-4">
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="mt-0.5 text-xs text-slate-500">Grouped by device type, void invoices excluded.</p>
            </div>

            <Table
                colSpan={4}
                empty={list.length === 0 ? 'Nothing played.' : null}
                head={
                    <>
                        <th className="ct-th">Device</th>
                        <th className="ct-th text-right">Sessions</th>
                        <th className="ct-th text-right">Hours</th>
                        <th className="ct-th text-right">Income</th>
                    </>
                }
            >
                {list.map((row) => (
                    <tr key={row.device} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                        <td className="ct-td font-medium">{row.device}</td>
                        <td className="ct-td text-right tabular-nums">{row.sessions}</td>
                        <td className="ct-td text-right tabular-nums">{row.hours}</td>
                        <td className="ct-td text-right font-semibold tabular-nums">{money(row.income)}</td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

/**
 * Cash in and out of the drawer for the day, each with the reason it was
 * recorded under. This is the only outgoing the system holds — money that never
 * passed through the till is not in here.
 */
function MovementTable({ rows }) {
    const list = rows ?? [];

    return (
        <Card className="overflow-hidden">
            <div className="px-5 py-4">
                <h2 className="text-sm font-semibold">Money out of the drawer</h2>
                <p className="mt-0.5 text-xs text-slate-500">
                    Recorded against the open shift on the <span className="font-medium">Shifts</span> screen.
                </p>
            </div>

            <Table
                colSpan={4}
                empty={list.length === 0 ? 'Nothing paid in or out.' : null}
                head={
                    <>
                        <th className="ct-th">When</th>
                        <th className="ct-th">Reason</th>
                        <th className="ct-th">Who</th>
                        <th className="ct-th text-right">Amount</th>
                    </>
                }
            >
                {list.map((row) => (
                    <tr key={row.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                        <td className="ct-td whitespace-nowrap">{dateTime(row.created_at)}</td>
                        <td className="ct-td">{row.reason || '—'}</td>
                        <td className="ct-td text-xs text-slate-500">{row.actor_email}</td>
                        <td
                            className={`ct-td text-right font-semibold tabular-nums ${
                                row.kind === 'out'
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-emerald-600 dark:text-emerald-400'
                            }`}
                        >
                            {row.kind === 'out' ? '−' : '+'}
                            {money(row.amount)}
                        </td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}
