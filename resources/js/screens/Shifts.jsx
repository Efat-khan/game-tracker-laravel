import { useState } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../lib/hooks';
import { dateTime, money } from '../lib/format';
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
    Stat,
    Table,
} from '../components/ui';

export default function Shifts() {
    const current = useAsync(() => api.currentShift(), []);
    const history = useAsync(() => api.shifts({ limit: 50 }), []);
    const [opening, setOpening] = useState(false);
    const [closing, setClosing] = useState(false);
    const [cashing, setCashing] = useState(null); // 'in' | 'out'
    const [actionError, setActionError] = useState(null);

    const shift = current.data;

    function refresh() {
        current.reload();
        history.reload();
    }

    if (current.loading && !current.data) return <Loading />;

    return (
        <>
            <PageHeader title="Shifts" subtitle="Open a shift, take the money, count the drawer.">
                {shift ? (
                    <>
                        <Button variant="outline" onClick={() => setCashing('in')}>
                            Cash in
                        </Button>
                        <Button variant="outline" onClick={() => setCashing('out')}>
                            Cash out
                        </Button>
                        <Button onClick={() => setClosing(true)}>Close shift</Button>
                    </>
                ) : (
                    <Button onClick={() => setOpening(true)}>Open shift</Button>
                )}
            </PageHeader>

            <ErrorNote error={current.error || actionError} onRetry={refresh} />

            {shift ? (
                <CurrentShift shift={shift} />
            ) : (
                <Card className="p-10 text-center">
                    <p className="text-sm text-slate-500">
                        No shift is open. Open one so the day's takings have somewhere to land.
                    </p>
                </Card>
            )}

            <h2 className="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Past shifts</h2>

            <Card className="overflow-hidden">
                <Table
                    colSpan={7}
                    empty={!history.data?.length ? 'No shifts yet.' : null}
                    head={
                        <>
                            <th className="ct-th">Opened</th>
                            <th className="ct-th">By</th>
                            <th className="ct-th">Closed</th>
                            <th className="ct-th text-right">Float</th>
                            <th className="ct-th text-right">Expected</th>
                            <th className="ct-th text-right">Counted</th>
                            <th className="ct-th text-right">Variance</th>
                        </>
                    }
                >
                    {(history.data ?? []).map((row) => {
                        const variance = Number(row.variance ?? 0);

                        return (
                            <tr key={row.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td className="ct-td whitespace-nowrap">{dateTime(row.opened_at)}</td>
                                <td className="ct-td">{row.opened_by_email}</td>
                                <td className="ct-td whitespace-nowrap">
                                    {row.closed_at ? dateTime(row.closed_at) : <Pill tone="indigo">Open</Pill>}
                                </td>
                                <td className="ct-td text-right tabular-nums">{money(row.opening_float)}</td>
                                <td className="ct-td text-right tabular-nums">
                                    {row.expected_cash === null ? '—' : money(row.expected_cash)}
                                </td>
                                <td className="ct-td text-right tabular-nums">
                                    {row.counted_cash === null ? '—' : money(row.counted_cash)}
                                </td>
                                <td
                                    className={`ct-td text-right font-medium tabular-nums ${
                                        row.variance === null
                                            ? ''
                                            : variance === 0
                                              ? 'text-emerald-600 dark:text-emerald-400'
                                              : 'text-rose-600 dark:text-rose-400'
                                    }`}
                                >
                                    {row.variance === null ? '—' : money(row.variance)}
                                </td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>

            <OpenModal open={opening} onClose={() => setOpening(false)} onDone={refresh} onError={setActionError} />
            <CloseModal shift={closing ? shift : null} onClose={() => setClosing(false)} onDone={refresh} onError={setActionError} />
            <CashModal
                shift={cashing ? shift : null}
                kind={cashing}
                onClose={() => setCashing(null)}
                onDone={refresh}
                onError={setActionError}
            />
        </>
    );
}

function CurrentShift({ shift }) {
    const t = shift.totals ?? {};

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="Total sales" value={money(t.total_sales)} />
                <Stat label="Cash sales" value={money(t.cash_sales)} hint="Reaches the drawer" />
                <Stat label="Phone sales" value={money(t.phone_sales)} hint="Never reaches the drawer" />
                <Stat label="Wallet sales" value={money(t.wallet_sales)} hint="Paid for at top-up time" />
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                {/* The drawer arithmetic, spelled out line by line so a
                    discrepancy can be traced without a calculator. */}
                <Card className="p-5">
                    <h3 className="mb-3 text-sm font-semibold">Drawer</h3>
                    <dl className="space-y-2 text-sm">
                        <DrawerLine label="Opening float" value={money(shift.opening_float)} />
                        <DrawerLine label="Cash sales" value={`+ ${money(t.cash_sales)}`} />
                        <DrawerLine label="Cash top-ups" value={`+ ${money(t.cash_topups)}`} />
                        <DrawerLine label="Cash paid in" value={`+ ${money(t.paid_in)}`} />
                        <DrawerLine label="Cash paid out" value={`− ${money(t.paid_out)}`} />
                        <div className="border-t border-slate-200 pt-2 dark:border-slate-700">
                            <DrawerLine label="Expected in the drawer" value={money(shift.expected_cash)} bold />
                        </div>
                    </dl>
                    <p className="mt-3 text-xs text-slate-500">
                        Phone payments and wallet spends are deliberately excluded — that money never entered the till.
                    </p>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-3 text-sm font-semibold">This shift</h3>
                    <dl className="space-y-2 text-sm">
                        <DrawerLine label="Opened" value={dateTime(shift.opened_at)} />
                        <DrawerLine label="Opened by" value={shift.opened_by_email} />
                        <DrawerLine label="Cash top-ups" value={money(t.cash_topups)} />
                        <DrawerLine label="Phone top-ups" value={money(t.phone_topups)} />
                        {shift.open_note && <DrawerLine label="Note" value={shift.open_note} />}
                    </dl>
                </Card>
            </div>
        </>
    );
}

function DrawerLine({ label, value, bold }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className={`text-slate-600 dark:text-slate-400 ${bold ? 'font-semibold' : ''}`}>{label}</dt>
            <dd className={`shrink-0 tabular-nums ${bold ? 'text-base font-semibold' : ''}`}>{value}</dd>
        </div>
    );
}

function OpenModal({ open, onClose, onDone, onError }) {
    const [float, setFloat] = useState('1000');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);

        try {
            await api.openShift({ opening_float: float, note });
            onDone();
            onClose();
        } catch (err) {
            onError(err);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open={open} title="Open a shift" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Opening float" hint="What is in the drawer before you start.">
                    <Input required type="number" min="0" step="0.01" value={float} onChange={(e) => setFloat(e.target.value)} autoFocus />
                </Field>
                <Field label="Note">
                    <Input maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                </Field>
                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Open
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function CloseModal({ shift, onClose, onDone, onError }) {
    const [counted, setCounted] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const expected = Number(shift?.expected_cash ?? 0);
    const variance = counted === '' ? null : Number(counted) - expected;

    async function submit(event) {
        event.preventDefault();
        setBusy(true);

        try {
            await api.closeShift(shift.id, { counted_cash: counted, note });
            onDone();
            close();
        } catch (err) {
            onError(err);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setCounted('');
        setNote('');
        onClose();
    }

    return (
        <Modal open={Boolean(shift)} title="Close the shift" onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-slate-600 dark:text-slate-400">
                    Expected in the drawer:{' '}
                    <span className="font-semibold text-slate-900 dark:text-slate-100">{money(expected)}</span>
                </p>

                <Field label="Counted cash">
                    <Input
                        required
                        type="number"
                        min="0"
                        step="0.01"
                        value={counted}
                        onChange={(e) => setCounted(e.target.value)}
                        autoFocus
                    />
                </Field>

                {variance !== null && (
                    <p
                        className={`rounded-lg px-3 py-2 text-sm ${
                            variance === 0
                                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'
                        }`}
                    >
                        {variance === 0
                            ? 'Balances exactly.'
                            : `${variance > 0 ? 'Over' : 'Short'} by ${money(Math.abs(variance))}.`}
                    </p>
                )}

                <Field label="Note">
                    <Input maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                </Field>

                <p className="text-xs text-slate-500">
                    Closing freezes these figures. A void tomorrow will not rewrite tonight's reconciliation.
                </p>

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Close shift
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function CashModal({ shift, kind, onClose, onDone, onError }) {
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);

        try {
            await api.cashMovement(shift.id, { kind, amount, reason });
            onDone();
            close();
        } catch (err) {
            onError(err);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setAmount('');
        setReason('');
        onClose();
    }

    return (
        <Modal open={Boolean(shift && kind)} title={kind === 'in' ? 'Cash in' : 'Cash out'} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Amount">
                    <Input required type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
                </Field>
                <Field label="Reason" hint="At least 3 characters.">
                    <Input required minLength={3} maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Record
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
