import { useMemo, useState } from 'react';
import { api } from '../lib/api';
import { useAsync, useNow, usePolling } from '../lib/hooks';
import { duration, money, parseUtc } from '../lib/format';
import {
    Button,
    Card,
    ErrorNote,
    Field,
    Input,
    Loading,
    Modal,
    PageHeader,
    Pill,
    Select,
} from '../components/ui';

export default function Dashboard() {
    const { data, error, loading, reload } = useAsync(() => api.activeSessions(), []);
    const [starting, setStarting] = useState(null); // station row
    const [ending, setEnding] = useState(null); // station row

    // Every 5 seconds, per the spec. Quiet reloads, so the numbers never flash.
    usePolling(reload, 5000);

    // Ticks once a second so elapsed time advances between refreshes rather
    // than jumping in 5-second steps.
    const now = useNow(1000);

    const rows = data ?? [];

    const summary = useMemo(() => {
        const occupied = rows.filter((r) => r.status === 'occupied');
        const running = occupied.reduce((sum, r) => sum + Number(r.running_cost || 0), 0);
        return { occupied: occupied.length, total: rows.length, running };
    }, [rows]);

    if (loading && !data) return <Loading label="Loading the floor…" />;

    return (
        <>
            <PageHeader
                title="Dashboard"
                subtitle={`${summary.occupied} of ${summary.total} in play · ${money(summary.running)} running`}
            >
                <Pill tone="indigo">Live · refreshes every 5s</Pill>
            </PageHeader>

            <ErrorNote error={error} onRetry={reload} />

            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                {rows.map((row) => (
                    <StationCard
                        key={row.station_id}
                        row={row}
                        now={now}
                        onStart={() => setStarting(row)}
                        onEnd={() => setEnding(row)}
                        onMaintenance={async () => {
                            await api.toggleMaintenance(row.station_id, !row.maintenance);
                            reload();
                        }}
                    />
                ))}
            </div>

            {rows.length === 0 && !loading && (
                <Card className="p-10 text-center text-sm text-slate-500">
                    No stations yet. Add one on the Stations screen and its QR sticker will be ready to print.
                </Card>
            )}

            <StartSessionModal row={starting} onClose={() => setStarting(null)} onDone={reload} />
            <EndSessionModal row={ending} onClose={() => setEnding(null)} onDone={reload} />
        </>
    );
}

function StationCard({ row, now, onStart, onEnd, onMaintenance }) {
    const free = row.status === 'free';
    const maintenance = row.status === 'maintenance';

    // Recompute elapsed from the start time so the timer ticks smoothly; the
    // API's elapsed_minutes only moves when we poll.
    const started = parseUtc(row.start_time);
    const elapsed = started ? Math.max(0, Math.floor((now - started.getTime()) / 60000)) : row.elapsed_minutes;
    const overdue = row.planned_minutes != null && elapsed > row.planned_minutes;

    // A running station should be the thing your eye lands on across the room.
    const tone = maintenance
        ? 'border-amber-400/60 dark:border-amber-500/40'
        : free
          ? 'border-slate-200 dark:border-slate-800'
          : 'border-live-500/50 ct-live-ring';

    return (
        <Card className={`relative overflow-hidden border transition ${tone}`}>
            {/* Clicking anywhere on a free card opens the same start form. */}
            {free && (
                <button
                    onClick={onStart}
                    className="absolute inset-0 z-0 cursor-pointer"
                    aria-label={`Start a session on ${row.station_name}`}
                />
            )}

            <div className="pointer-events-none relative z-10 p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="flex items-center gap-2 truncate font-semibold">
                            {!free && !maintenance && (
                                <span className="ct-live-dot h-2 w-2 shrink-0 rounded-full bg-live-500" aria-hidden="true" />
                            )}
                            {row.station_name}
                        </p>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500 dark:text-slate-400">
                            {row.station_type} · {money(row.hourly_rate)}/hr
                        </p>
                    </div>

                    <div className="flex shrink-0 items-center gap-1.5">
                        {maintenance && <Pill tone="amber">Out of service</Pill>}
                        {overdue && <Pill tone="red">Overdue {duration(elapsed - row.planned_minutes)}</Pill>}

                        <button
                            onClick={onMaintenance}
                            title={row.maintenance ? 'Put back in service' : 'Mark out of service'}
                            aria-label={row.maintenance ? 'Put back in service' : 'Mark out of service'}
                            className={`pointer-events-auto rounded-lg p-1.5 transition ${
                                row.maintenance
                                    ? 'bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-500/20 dark:text-amber-300'
                                    : 'text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800'
                            }`}
                        >
                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                                <path
                                    d="M14.7 6.3a4 4 0 0 0 5 5l-9 9a2.8 2.8 0 1 1-4-4l9-9z"
                                    strokeLinejoin="round"
                                />
                            </svg>
                        </button>
                    </div>
                </div>

                {free && (
                    <div className="mt-5">
                        <p className="text-sm text-slate-400 dark:text-slate-500">Free</p>
                        <Button className="pointer-events-auto mt-3 w-full" onClick={onStart}>
                            Start session
                        </Button>
                    </div>
                )}

                {maintenance && !row.session_id && (
                    <p className="mt-5 pb-1 text-sm text-amber-600 dark:text-amber-400">
                        Not taking players until this is cleared.
                    </p>
                )}

                {row.session_id && (
                    <div className="mt-4">
                        <p className="truncate text-sm font-medium">{row.customer_name}</p>
                        <div className="mt-3 flex items-end justify-between">
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                                    Elapsed
                                </p>
                                <p className="text-xl font-bold tabular-nums text-live-600 dark:text-live-400">
                                    {duration(elapsed)}
                                </p>
                            </div>
                            <div className="text-right">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                                    Cost so far
                                </p>
                                <p className="text-xl font-bold tabular-nums">{money(row.running_cost)}</p>
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            {row.controllers} controller{row.controllers === 1 ? '' : 's'} ·{' '}
                            {money(row.session_hourly_rate)}/hr
                            {row.planned_minutes ? ` · planned ${duration(row.planned_minutes)}` : ''}
                        </p>
                        <Button variant="success" className="pointer-events-auto mt-3 w-full" onClick={onEnd}>
                            End session
                        </Button>
                    </div>
                )}
            </div>
        </Card>
    );
}

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

                <Field label="Controllers" hint={`${money(effective)}/hr with ${controllers}`}>
                    <div className="flex flex-wrap gap-2">
                        {Array.from({ length: max }, (_, i) => i + 1).map((n) => (
                            <button
                                key={n}
                                type="button"
                                onClick={() => setControllers(n)}
                                className={`h-10 w-10 rounded-lg border text-sm font-medium transition ${
                                    controllers === n
                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                        : 'border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'
                                }`}
                            >
                                {n}
                            </button>
                        ))}
                    </div>
                </Field>

                <Field label="Planned minutes" hint="Optional — the dashboard flags a session that runs past it.">
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
                <span className="font-medium">{money(row?.running_cost)}</span> at the current rate.
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
