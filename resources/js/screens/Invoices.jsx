import { useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/hooks';
import { dateTime, money, paymentLabel } from '../lib/format';
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

export default function Invoices() {
    const { isAdmin, canFetch } = useAuth();
    // canFetch, not can: `can` is optimistic while the feature map is still in
    // flight, which would fire one doomed request for a disabled module on
    // every page load.
    const hasProducts = canFetch('products');
    const [filters, setFilters] = useState({ payment_status: '', date_from: '', date_to: '' });
    const [expanded, setExpanded] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [actionError, setActionError] = useState(null);

    const products = useAsync(
        () => (hasProducts ? api.products({ active_only: true }) : Promise.resolve([])),
        [hasProducts],
    );
    const { data, error, loading, reload } = useAsync(
        () => api.invoices({ ...filters, limit: 200 }),
        [filters.payment_status, filters.date_from, filters.date_to],
    );

    const invoices = data ?? [];
    const set = (key) => (event) => setFilters((f) => ({ ...f, [key]: event.target.value }));

    /** Clicking the status pill settles or re-opens an invoice. */
    async function togglePaid(invoice) {
        setBusyId(invoice.id);
        setActionError(null);

        try {
            await api.updateInvoice(invoice.id, {
                payment_status: invoice.payment_status === 'paid' ? 'unpaid' : 'paid',
                // A method is only meaningful when settling; sending it while
                // re-opening would be a method change, which staff cannot do.
                ...(invoice.payment_status === 'paid' ? {} : { payment_method: invoice.payment_method || 'cash' }),
            });
            reload();
        } catch (err) {
            setActionError(err);
        } finally {
            setBusyId(null);
        }
    }

    async function downloadCsv() {
        const response = await api.exportCsv({ ...filters, limit: 1000 });
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    }

    return (
        <>
            <PageHeader title="Invoices" subtitle="Click a status pill to settle or re-open a bill.">
                <Button variant="outline" onClick={downloadCsv}>
                    Export CSV
                </Button>
            </PageHeader>

            <Card className="mb-4 p-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Payment status">
                        <Select value={filters.payment_status} onChange={set('payment_status')}>
                            <option value="">Paid and unpaid</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="paid">Paid</option>
                        </Select>
                    </Field>
                    <Field label="From">
                        <Input type="date" value={filters.date_from} onChange={set('date_from')} />
                    </Field>
                    <Field label="To">
                        <Input type="date" value={filters.date_to} onChange={set('date_to')} />
                    </Field>
                </div>
            </Card>

            <ErrorNote error={error || actionError} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <Card className="mt-4 overflow-hidden">
                    <Table
                        colSpan={8}
                        empty={invoices.length === 0 ? 'No invoices match those filters.' : null}
                        head={
                            <>
                                <th className="ct-th">#</th>
                                <th className="ct-th">Raised</th>
                                <th className="ct-th">Station</th>
                                <th className="ct-th">Customer</th>
                                <th className="ct-th">Time</th>
                                <th className="ct-th text-right">Total</th>
                                <th className="ct-th">Payment</th>
                                <th className="ct-th text-right">Actions</th>
                            </>
                        }
                    >
                        {invoices.map((invoice) => (
                            <InvoiceRow
                                key={invoice.id}
                                invoice={invoice}
                                isAdmin={isAdmin}
                                products={products.data ?? []}
                                expanded={expanded === invoice.id}
                                busy={busyId === invoice.id}
                                onToggleExpand={() => setExpanded(expanded === invoice.id ? null : invoice.id)}
                                onTogglePaid={() => togglePaid(invoice)}
                                onChanged={reload}
                                onError={setActionError}
                            />
                        ))}
                    </Table>
                </Card>
            )}
        </>
    );
}

function InvoiceRow({ invoice, isAdmin, products, expanded, busy, onToggleExpand, onTogglePaid, onChanged, onError }) {
    const [adding, setAdding] = useState(false);
    const [discounting, setDiscounting] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const isVoid = invoice.status === 'void';

    return (
        <>
            <tr className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td className="ct-td">
                    <button
                        onClick={onToggleExpand}
                        className="font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                        {expanded ? '▾' : '▸'} {invoice.id}
                    </button>
                </td>
                <td className="ct-td whitespace-nowrap">{dateTime(invoice.created_at)}</td>
                <td className="ct-td">{invoice.station_name}</td>
                <td className="ct-td">{invoice.customer_name}</td>
                <td className="ct-td tabular-nums">{invoice.duration_minutes} min</td>
                <td className={`ct-td text-right font-medium tabular-nums ${isVoid ? 'line-through opacity-60' : ''}`}>
                    {money(invoice.total_amount)}
                </td>
                <td className="ct-td">
                    {isVoid ? (
                        <Pill tone="red">Void</Pill>
                    ) : (
                        <button onClick={onTogglePaid} disabled={busy} title="Click to toggle" className="disabled:opacity-50">
                            <Pill tone={invoice.payment_status === 'paid' ? 'green' : 'amber'} className="cursor-pointer">
                                {invoice.payment_status === 'paid'
                                    ? `Paid · ${paymentLabel(invoice.payment_method)}`
                                    : 'Unpaid'}
                            </Pill>
                        </button>
                    )}
                </td>
                <td className="ct-td text-right">
                    <a
                        href={api.invoicePdfUrl(invoice.id)}
                        target="_blank"
                        rel="noreferrer"
                        className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                        PDF
                    </a>
                </td>
            </tr>

            {expanded && (
                <tr className="bg-slate-50/70 dark:bg-slate-800/30">
                    <td colSpan={8} className="px-4 py-4">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <div>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Breakdown
                                </h3>
                                <dl className="space-y-1.5 text-sm">
                                    <Line label={`Play (${invoice.duration_minutes} min @ ${money(invoice.hourly_rate)}/hr)`} value={money(invoice.session_amount)} />
                                    <Line label="Items" value={money(invoice.items_amount)} />
                                    {Number(invoice.discount_amount) > 0 && (
                                        <Line
                                            label={`Discount${invoice.discount_reason ? ` — ${invoice.discount_reason}` : ''}`}
                                            value={`−${money(invoice.discount_amount)}`}
                                            tone="text-emerald-600 dark:text-emerald-400"
                                        />
                                    )}
                                    <div className="mt-2 border-t border-slate-200 pt-2 dark:border-slate-700">
                                        <Line label="Total" value={money(invoice.total_amount)} bold />
                                    </div>
                                </dl>

                                {isVoid && (
                                    <p className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                                        Voided — {invoice.void_reason}. Excluded from all revenue and analytics.
                                    </p>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Items
                                </h3>

                                {invoice.items?.length ? (
                                    <ul className="space-y-1.5">
                                        {invoice.items.map((item) => (
                                            <li key={item.id} className="flex items-center justify-between gap-3 text-sm">
                                                <span className="min-w-0 truncate">
                                                    {item.quantity} × {item.description}
                                                </span>
                                                <span className="flex shrink-0 items-center gap-2">
                                                    <span className="tabular-nums">{money(item.amount)}</span>
                                                    {!isVoid && (
                                                        <button
                                                            onClick={async () => {
                                                                try {
                                                                    await api.removeInvoiceItem(invoice.id, item.id);
                                                                    onChanged();
                                                                } catch (err) {
                                                                    onError(err);
                                                                }
                                                            }}
                                                            className="text-rose-500 hover:text-rose-700"
                                                            aria-label={`Remove ${item.description}`}
                                                        >
                                                            ×
                                                        </button>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-slate-500">Nothing added.</p>
                                )}

                                {!isVoid && (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                                            + Add item…
                                        </Button>
                                        {invoice.payment_status !== 'paid' && (
                                            <Button
                                                size="sm"
                                                variant="subtle"
                                                onClick={async () => {
                                                    try {
                                                        await api.payInvoiceFromWallet(invoice.id);
                                                        onChanged();
                                                    } catch (err) {
                                                        onError(err);
                                                    }
                                                }}
                                            >
                                                Pay from wallet
                                            </Button>
                                        )}
                                        {isAdmin && (
                                            <>
                                                <Button size="sm" variant="subtle" onClick={() => setDiscounting(true)}>
                                                    Discount
                                                </Button>
                                                <Button size="sm" variant="danger" onClick={() => setVoiding(true)}>
                                                    Void
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </td>
                </tr>
            )}

            <AddItemModal
                open={adding}
                invoice={invoice}
                products={products}
                onClose={() => setAdding(false)}
                onDone={onChanged}
            />
            <DiscountModal
                open={discounting}
                invoice={invoice}
                onClose={() => setDiscounting(false)}
                onDone={onChanged}
            />
            <VoidModal open={voiding} invoice={invoice} onClose={() => setVoiding(false)} onDone={onChanged} />
        </>
    );
}

function Line({ label, value, bold, tone = '' }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className={`text-slate-600 dark:text-slate-400 ${bold ? 'font-semibold' : ''}`}>{label}</dt>
            <dd className={`shrink-0 tabular-nums ${bold ? 'font-semibold' : ''} ${tone}`}>{value}</dd>
        </div>
    );
}

function AddItemModal({ open, invoice, products, onClose, onDone }) {
    const [productId, setProductId] = useState('');
    const [description, setDescription] = useState('');
    const [unitPrice, setUnitPrice] = useState('');
    const [quantity, setQuantity] = useState(1);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            // Picking from the catalogue lets the server snapshot both the
            // price and the cost; a typed line needs its own price.
            await api.addInvoiceItem(
                invoice.id,
                productId
                    ? { product_id: Number(productId), quantity: Number(quantity) }
                    : { description, unit_price: unitPrice, quantity: Number(quantity) },
            );
            onDone();
            close();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setProductId('');
        setDescription('');
        setUnitPrice('');
        setQuantity(1);
        setError(null);
        onClose();
    }

    return (
        <Modal open={open} title={`Add to invoice #${invoice.id}`} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="From the catalogue">
                    <Select value={productId} onChange={(e) => setProductId(e.target.value)}>
                        <option value="">Type it in instead…</option>
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name} — {money(p.price)}
                            </option>
                        ))}
                    </Select>
                </Field>

                {!productId && (
                    <>
                        <Field label="Description">
                            <Input required maxLength={150} value={description} onChange={(e) => setDescription(e.target.value)} />
                        </Field>
                        <Field label="Unit price">
                            <Input required type="number" min="0" step="0.01" value={unitPrice} onChange={(e) => setUnitPrice(e.target.value)} />
                        </Field>
                    </>
                )}

                <Field label="Quantity">
                    <Input type="number" min={1} max={99} value={quantity} onChange={(e) => setQuantity(e.target.value)} />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Add
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function DiscountModal({ open, invoice, onClose, onDone }) {
    const [mode, setMode] = useState('percent');
    const [value, setValue] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.discountInvoice(invoice.id, {
                [mode]: value,
                reason,
            });
            onDone();
            onClose();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open={open} title={`Discount invoice #${invoice.id}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Type">
                    <Select value={mode} onChange={(e) => setMode(e.target.value)}>
                        <option value="percent">Percent of the subtotal</option>
                        <option value="amount">Flat amount</option>
                    </Select>
                </Field>

                <Field label={mode === 'percent' ? 'Percent' : 'Amount'}>
                    <Input
                        required
                        type="number"
                        min="0"
                        max={mode === 'percent' ? 100 : undefined}
                        step="0.01"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                    />
                </Field>

                <Field label="Reason" hint="At least 3 characters. It goes on the receipt and the log.">
                    <Input required minLength={3} maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Apply
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function VoidModal({ open, invoice, onClose, onDone }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.voidInvoice(invoice.id, reason);
            onDone();
            onClose();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open={open} title={`Void invoice #${invoice.id}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-slate-600 dark:text-slate-400">
                    The invoice is kept for the record but excluded from all revenue, analytics and the customer's
                    lifetime spend. If it was paid from a wallet, the credit is returned.
                </p>

                <Field label="Reason" hint="At least 3 characters.">
                    <Input required minLength={3} maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" busy={busy}>
                        Void
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
