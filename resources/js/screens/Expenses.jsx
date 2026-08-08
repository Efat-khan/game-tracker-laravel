import { useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/hooks';
import { dateTime, money, titleCase } from '../lib/format';
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

const METHOD_LABELS = {
    cash: 'Cash',
    phone_payment: 'Phone payment',
    bank: 'Bank transfer',
    other: 'Other',
};

/**
 * What the cafe spends.
 *
 * Not the same ledger as the cash drawer, and the difference matters: the
 * Shifts screen reconciles the till, this records the cost. They meet at one
 * point — paying in cash writes the matching drawer movement — and the form
 * says so, because "why can't I record this?" is otherwise a mystery.
 */
export default function Expenses() {
    const { isAdmin } = useAuth();
    const today = new Date().toISOString().slice(0, 10);
    const monthStart = today.slice(0, 8) + '01';

    const [range, setRange] = useState({ from: monthStart, to: today });
    const [category, setCategory] = useState('');
    const [adding, setAdding] = useState(false);
    const [actionError, setActionError] = useState(null);

    const { data, error, loading, reload } = useAsync(
        () => api.expenses({ ...range, category }),
        [range.from, range.to, category],
    );

    const expenses = data?.expenses ?? [];

    async function remove(expense) {
        setActionError(null);
        try {
            await api.deleteExpense(expense.id);
            reload();
        } catch (err) {
            setActionError(err);
        }
    }

    return (
        <>
            <PageHeader
                title="Expenses"
                subtitle="What the cafe spends. Cash comes out of the open drawer; anything else never touches the till."
            >
                <Button onClick={() => setAdding(true)}>Record an expense</Button>
            </PageHeader>

            <Card className="mb-4 flex flex-wrap items-end gap-4 p-4">
                <label className="block">
                    <span className="ct-label">From</span>
                    <Input
                        type="date"
                        value={range.from}
                        onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))}
                    />
                </label>

                <label className="block">
                    <span className="ct-label">To</span>
                    <Input
                        type="date"
                        value={range.to}
                        onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))}
                    />
                </label>

                <label className="block">
                    <span className="ct-label">Category</span>
                    <Select value={category} onChange={(e) => setCategory(e.target.value)}>
                        <option value="">Everything</option>
                        {(data?.categories ?? []).map((c) => (
                            <option key={c.key} value={c.key}>
                                {c.label}
                            </option>
                        ))}
                    </Select>
                </label>

                <button
                    onClick={() => {
                        setRange({ from: monthStart, to: today });
                        setCategory('');
                    }}
                    className="pb-2 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                >
                    This month
                </button>
            </Card>

            <ErrorNote error={error || actionError} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Stat
                            label="Spent"
                            value={money(data?.total)}
                            tone={Number(data?.total) > 0 ? 'bad' : 'default'}
                            hint={`${expenses.length} ${expenses.length === 1 ? 'entry' : 'entries'}`}
                        />
                        {(data?.by_category ?? []).slice(0, 3).map((row) => (
                            <Stat
                                key={row.category}
                                label={row.label}
                                value={money(row.total)}
                                hint={`${row.count} ${row.count === 1 ? 'entry' : 'entries'}`}
                            />
                        ))}
                    </div>

                    {(data?.by_category ?? []).length > 3 && (
                        <Card className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 p-4">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                And the rest
                            </span>
                            {data.by_category.slice(3).map((row) => (
                                <span key={row.category} className="flex items-baseline gap-1.5 text-sm">
                                    <span className="text-xs text-slate-500">{row.label}</span>
                                    <span className="font-semibold tabular-nums">{money(row.total)}</span>
                                </span>
                            ))}
                        </Card>
                    )}

                    <Card className="mt-4 overflow-hidden">
                        <Table
                            colSpan={6}
                            empty={expenses.length === 0 ? 'Nothing spent in this window.' : null}
                            head={
                                <>
                                    <th className="ct-th">Date</th>
                                    <th className="ct-th">Category</th>
                                    <th className="ct-th">Note</th>
                                    <th className="ct-th">Paid by</th>
                                    <th className="ct-th text-right">Amount</th>
                                    <th className="ct-th text-right">{isAdmin ? '' : ''}</th>
                                </>
                            }
                        >
                            {expenses.map((expense) => (
                                <tr key={expense.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                    <td className="ct-td whitespace-nowrap font-medium">{expense.spent_on}</td>
                                    <td className="ct-td">
                                        <Pill>{expense.category_label}</Pill>
                                    </td>
                                    <td className="ct-td">
                                        <p className="truncate">{expense.note || '—'}</p>
                                        <p className="text-xs text-slate-500">
                                            {expense.actor_email} · {dateTime(expense.created_at)}
                                        </p>
                                    </td>
                                    <td className="ct-td">
                                        <span className="text-xs">
                                            {METHOD_LABELS[expense.payment_method] ??
                                                titleCase(expense.payment_method)}
                                        </span>
                                        {expense.shift_id && (
                                            <p className="text-[11px] text-slate-500">
                                                Drawer · shift #{expense.shift_id}
                                            </p>
                                        )}
                                    </td>
                                    <td className="ct-td text-right font-semibold tabular-nums text-rose-600 dark:text-rose-400">
                                        −{money(expense.amount)}
                                    </td>
                                    <td className="ct-td text-right">
                                        {isAdmin && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                disabled={expense.locked}
                                                title={
                                                    expense.locked
                                                        ? 'That shift has been closed and counted.'
                                                        : undefined
                                                }
                                                onClick={() => remove(expense)}
                                            >
                                                Delete
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </>
            )}

            <RecordModal
                open={adding}
                categories={data?.categories ?? []}
                onClose={() => setAdding(false)}
                onDone={reload}
            />
        </>
    );
}

function RecordModal({ open, categories, onClose, onDone }) {
    const today = new Date().toISOString().slice(0, 10);
    const blank = { category: 'stock', amount: '', payment_method: 'cash', note: '', spent_on: today };

    const [form, setForm] = useState(blank);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }));
    const isCash = form.payment_method === 'cash';

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.createExpense({
                category: form.category,
                amount: form.amount,
                payment_method: form.payment_method,
                ...(form.note ? { note: form.note } : {}),
                // Cash is pinned to today by the server anyway; not sending it
                // keeps the request honest about what it is asking for.
                ...(isCash ? {} : { spent_on: form.spent_on }),
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
        setForm(blank);
        setError(null);
        onClose();
    }

    return (
        <Modal open={open} title="Record an expense" onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Category">
                        <Select value={form.category} onChange={set('category')}>
                            {categories.map((c) => (
                                <option key={c.key} value={c.key}>
                                    {c.label}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label="Amount">
                        <Input
                            required
                            autoFocus
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={form.amount}
                            onChange={set('amount')}
                        />
                    </Field>
                </div>

                <Field label="Paid by">
                    <Select value={form.payment_method} onChange={set('payment_method')}>
                        {Object.entries(METHOD_LABELS).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </Select>
                </Field>

                {isCash ? (
                    <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        Cash comes straight out of the open drawer, so a shift has to be open and this is dated
                        today. Pick another method to record a bill for an earlier day.
                    </p>
                ) : (
                    <Field label="Date" hint="The day the cost belongs to — a bill can be entered late.">
                        <Input
                            type="date"
                            max={today}
                            value={form.spent_on}
                            onChange={set('spent_on')}
                        />
                    </Field>
                )}

                <Field label="Note" hint="What it was actually for.">
                    <Input maxLength={200} value={form.note} onChange={set('note')} placeholder="Snacks restock" />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

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
