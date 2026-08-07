import { useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/hooks';
import { money } from '../lib/format';
import {
    Button,
    Card,
    ConfirmButton,
    ErrorNote,
    Field,
    Input,
    Loading,
    Modal,
    PageHeader,
    Pill,
    Table,
} from '../components/ui';

const BLANK = { name: '', category: 'Snacks', price: '', cost_price: '', is_active: true };

export default function Products() {
    const { isAdmin } = useAuth();
    const { data, error, loading, reload } = useAsync(() => api.products(), []);
    const [editing, setEditing] = useState(null);

    const products = data ?? [];

    if (loading && !data) return <Loading />;

    return (
        <>
            <PageHeader title="Products" subtitle="What you sell alongside the play time.">
                {isAdmin && <Button onClick={() => setEditing(BLANK)}>New product</Button>}
            </PageHeader>

            <ErrorNote error={error} onRetry={reload} />

            <Card className="mt-4 overflow-hidden">
                <Table
                    colSpan={6}
                    empty={products.length === 0 ? 'Nothing in the catalogue yet.' : null}
                    head={
                        <>
                            <th className="ct-th">Product</th>
                            <th className="ct-th">Category</th>
                            <th className="ct-th text-right">Price</th>
                            {isAdmin && <th className="ct-th text-right">Cost</th>}
                            {isAdmin && <th className="ct-th text-right">Margin</th>}
                            <th className="ct-th text-right">{isAdmin ? 'Actions' : 'Status'}</th>
                        </>
                    }
                >
                    {products.map((product) => {
                        const price = Number(product.price);
                        const cost = Number(product.cost_price);
                        const margin = price > 0 ? ((price - cost) / price) * 100 : 0;

                        return (
                            <tr key={product.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td className="ct-td">
                                    <span className="font-medium text-slate-900 dark:text-slate-100">{product.name}</span>
                                    {!product.is_active && (
                                        <Pill className="ml-2">Retired</Pill>
                                    )}
                                </td>
                                <td className="ct-td">{product.category}</td>
                                <td className="ct-td text-right tabular-nums">{money(product.price)}</td>
                                {isAdmin && <td className="ct-td text-right tabular-nums">{money(product.cost_price)}</td>}
                                {isAdmin && (
                                    <td className="ct-td text-right tabular-nums text-slate-500">
                                        {margin.toFixed(0)}%
                                    </td>
                                )}
                                <td className="ct-td text-right">
                                    {isAdmin ? (
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" variant="outline" onClick={() => setEditing(product)}>
                                                Edit
                                            </Button>
                                            <ConfirmButton
                                                size="sm"
                                                variant="ghost"
                                                title={`Delete ${product.name}?`}
                                                message="A product that has already been sold is retired rather than deleted, so past invoices keep their line items."
                                                confirmLabel="Delete"
                                                onConfirm={async () => {
                                                    await api.deleteProduct(product.id);
                                                    reload();
                                                }}
                                            >
                                                Delete
                                            </ConfirmButton>
                                        </div>
                                    ) : product.is_active ? (
                                        <Pill tone="green">On sale</Pill>
                                    ) : (
                                        <Pill>Retired</Pill>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>

            <ProductModal product={editing} onClose={() => setEditing(null)} onSaved={reload} />
        </>
    );
}

function ProductModal({ product, onClose, onSaved }) {
    const [form, setForm] = useState(BLANK);
    const [seeded, setSeeded] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    if (product && seeded !== product) {
        setSeeded(product);
        setForm({
            name: product.name ?? '',
            category: product.category ?? 'Snacks',
            price: String(product.price ?? ''),
            cost_price: String(product.cost_price ?? ''),
            is_active: product.is_active ?? true,
        });
        setError(null);
    }

    const set = (key) => (event) =>
        setForm((f) => ({
            ...f,
            [key]: event.target.type === 'checkbox' ? event.target.checked : event.target.value,
        }));

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            product?.id ? await api.updateProduct(product.id, form) : await api.createProduct(form);
            onSaved();
            close();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setSeeded(null);
        onClose();
    }

    return (
        <Modal open={Boolean(product)} title={product?.id ? `Edit ${product.name}` : 'New product'} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Name">
                    <Input required maxLength={100} value={form.name} onChange={set('name')} />
                </Field>

                <Field label="Category">
                    <Input required maxLength={50} value={form.category} onChange={set('category')} />
                </Field>

                <div className="grid grid-cols-2 gap-3">
                    <Field label="Price">
                        <Input required type="number" min="0" step="0.01" value={form.price} onChange={set('price')} />
                    </Field>
                    <Field label="Cost price" hint="Snapshotted at each sale.">
                        <Input type="number" min="0" step="0.01" value={form.cost_price} onChange={set('cost_price')} />
                    </Field>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.is_active} onChange={set('is_active')} className="h-4 w-4 rounded" />
                    On sale
                </label>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Save
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
