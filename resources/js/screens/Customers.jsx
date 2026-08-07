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
    Table,
} from '../components/ui';

export default function Customers() {
    const { isAdmin } = useAuth();
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [toppingUp, setToppingUp] = useState(null);
    const [adjusting, setAdjusting] = useState(null);
    const [viewingWallet, setViewingWallet] = useState(null);

    const { data, error, loading, reload } = useAsync(() => api.customers({ search: query, limit: 200 }), [query]);
    const packages = useAsync(() => api.packages({ active_only: true }), []);

    const customers = data ?? [];

    return (
        <>
            <PageHeader title="Customers" subtitle="Who plays here, what they have spent, and what they have on account." />

            <Card className="mb-4 p-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        setQuery(search.trim());
                    }}
                    className="flex gap-2"
                >
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search by name or phone…"
                    />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </form>
            </Card>

            <ErrorNote error={error} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <Card className="overflow-hidden">
                    <Table
                        colSpan={6}
                        empty={customers.length === 0 ? 'No customers yet.' : null}
                        head={
                            <>
                                <th className="ct-th">Customer</th>
                                <th className="ct-th">Tier</th>
                                <th className="ct-th text-right">Visits</th>
                                <th className="ct-th text-right">Lifetime spend</th>
                                <th className="ct-th text-right">Balance</th>
                                <th className="ct-th text-right">Actions</th>
                            </>
                        }
                    >
                        {customers.map((customer) => (
                            <tr key={customer.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td className="ct-td">
                                    <p className="font-medium text-slate-900 dark:text-slate-100">{customer.name}</p>
                                    <p className="text-xs text-slate-500">{customer.phone_or_id}</p>
                                </td>
                                <td className="ct-td">
                                    {customer.tier_name ? (
                                        <Pill tone="indigo">
                                            {customer.tier_name}
                                            {Number(customer.tier_discount_percent) > 0 &&
                                                ` · ${Number(customer.tier_discount_percent)}%`}
                                        </Pill>
                                    ) : (
                                        <span className="text-xs text-slate-400">—</span>
                                    )}
                                </td>
                                <td className="ct-td text-right tabular-nums">{customer.visits}</td>
                                <td className="ct-td text-right tabular-nums">{money(customer.lifetime_spend)}</td>
                                <td className="ct-td text-right tabular-nums font-medium">{money(customer.balance)}</td>
                                <td className="ct-td text-right">
                                    <div className="flex justify-end gap-2">
                                        <Button size="sm" variant="outline" onClick={() => setToppingUp(customer)}>
                                            Top up
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setViewingWallet(customer)}>
                                            Wallet
                                        </Button>
                                        {isAdmin && (
                                            <Button size="sm" variant="ghost" onClick={() => setAdjusting(customer)}>
                                                Adjust
                                            </Button>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            <TopupModal
                customer={toppingUp}
                packages={packages.data ?? []}
                onClose={() => setToppingUp(null)}
                onDone={reload}
            />
            <AdjustModal customer={adjusting} onClose={() => setAdjusting(null)} onDone={reload} />
            <WalletModal customer={viewingWallet} onClose={() => setViewingWallet(null)} />
        </>
    );
}

function TopupModal({ customer, packages, onClose, onDone }) {
    const [packageId, setPackageId] = useState('');
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState('cash');
    const [note, setNote] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const chosen = packages.find((p) => String(p.id) === packageId);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.topup(customer.id, {
                ...(packageId ? { package_id: Number(packageId) } : { amount }),
                payment_method: method,
                ...(note ? { note } : {}),
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
        setPackageId('');
        setAmount('');
        setNote('');
        setError(null);
        onClose();
    }

    return (
        <Modal open={Boolean(customer)} title={`Top up ${customer?.name ?? ''}`} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Package">
                    <Select value={packageId} onChange={(e) => setPackageId(e.target.value)}>
                        <option value="">Custom amount…</option>
                        {packages.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name} — pay {money(p.price)}, get {money(p.credit)}
                            </option>
                        ))}
                    </Select>
                </Field>

                {chosen && (
                    <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                        Takes {money(chosen.price)} at the till and credits {money(chosen.credit)} — a{' '}
                        {money(Number(chosen.credit) - Number(chosen.price))} bonus.
                    </p>
                )}

                {!packageId && (
                    <Field label="Amount">
                        <Input required type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                    </Field>
                )}

                <Field label="Paid by" hint="Only cash reaches the drawer.">
                    <Select value={method} onChange={(e) => setMethod(e.target.value)}>
                        <option value="cash">Cash</option>
                        <option value="phone_payment">Phone payment</option>
                    </Select>
                </Field>

                <Field label="Note">
                    <Input maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Top up
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function AdjustModal({ customer, onClose, onDone }) {
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.adjust(customer.id, { amount, reason });
            onDone();
            close();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setAmount('');
        setReason('');
        setError(null);
        onClose();
    }

    return (
        <Modal open={Boolean(customer)} title={`Adjust ${customer?.name ?? ''}`} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-slate-600 dark:text-slate-400">
                    A manual correction. Use a negative number to take credit away. It is written to the ledger and
                    the activity log with your name on it.
                </p>

                <Field label="Amount" hint="e.g. -200 to remove ৳200">
                    <Input required type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                </Field>

                <Field label="Reason" hint="At least 3 characters.">
                    <Input required minLength={3} maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Adjust
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

const KIND_TONE = { topup: 'green', spend: 'slate', refund: 'indigo', adjust: 'amber' };

function WalletModal({ customer, onClose }) {
    const { data, loading } = useAsync(
        () => (customer ? api.wallet(customer.id, { limit: 100 }) : Promise.resolve(null)),
        [customer?.id],
    );

    return (
        <Modal open={Boolean(customer)} title={`${customer?.name ?? ''} — wallet`} onClose={onClose} wide>
            {loading && !data ? (
                <Loading />
            ) : (
                <>
                    <p className="mb-4 text-sm text-slate-600 dark:text-slate-400">
                        Balance <span className="font-semibold text-slate-900 dark:text-slate-100">{money(data?.balance)}</span>
                    </p>

                    <Table
                        colSpan={5}
                        empty={!data?.transactions?.length ? 'No wallet activity yet.' : null}
                        head={
                            <>
                                <th className="ct-th">When</th>
                                <th className="ct-th">Kind</th>
                                <th className="ct-th text-right">Amount</th>
                                <th className="ct-th text-right">Balance after</th>
                                <th className="ct-th">Note</th>
                            </>
                        }
                    >
                        {(data?.transactions ?? []).map((tx) => (
                            <tr key={tx.id}>
                                <td className="ct-td whitespace-nowrap">{dateTime(tx.created_at)}</td>
                                <td className="ct-td">
                                    <Pill tone={KIND_TONE[tx.kind]}>{titleCase(tx.kind)}</Pill>
                                </td>
                                <td
                                    className={`ct-td text-right tabular-nums ${
                                        Number(tx.amount) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'
                                    }`}
                                >
                                    {money(tx.amount)}
                                </td>
                                <td className="ct-td text-right tabular-nums">{money(tx.balance_after)}</td>
                                <td className="ct-td">
                                    <p className="truncate">{tx.note || '—'}</p>
                                    <p className="text-xs text-slate-500">{tx.actor_email}</p>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </>
            )}
        </Modal>
    );
}
