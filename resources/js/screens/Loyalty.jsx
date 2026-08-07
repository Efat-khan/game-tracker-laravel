import { useState } from 'react';
import { api } from '../lib/api';
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

export default function Loyalty() {
    const packages = useAsync(() => api.packages(), []);
    const tiers = useAsync(() => api.tiers(), []);
    const [editingPackage, setEditingPackage] = useState(null);
    const [editingTier, setEditingTier] = useState(null);

    if ((packages.loading && !packages.data) || (tiers.loading && !tiers.data)) return <Loading />;

    return (
        <>
            <PageHeader title="Loyalty" subtitle="Top-up deals, and the spend that earns a discount." />

            <ErrorNote error={packages.error || tiers.error} onRetry={() => (packages.reload(), tiers.reload())} />

            <div className="grid gap-6 xl:grid-cols-2">
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Packages</h2>
                        <Button size="sm" onClick={() => setEditingPackage({ name: '', price: '', credit: '', is_active: true })}>
                            New package
                        </Button>
                    </div>

                    <Card className="overflow-hidden">
                        <Table
                            colSpan={5}
                            empty={!packages.data?.length ? 'No packages yet.' : null}
                            head={
                                <>
                                    <th className="ct-th">Package</th>
                                    <th className="ct-th text-right">Price</th>
                                    <th className="ct-th text-right">Credit</th>
                                    <th className="ct-th text-right">Bonus</th>
                                    <th className="ct-th text-right">Actions</th>
                                </>
                            }
                        >
                            {(packages.data ?? []).map((p) => (
                                <tr key={p.id}>
                                    <td className="ct-td">
                                        <span className="font-medium">{p.name}</span>
                                        {!p.is_active && <Pill className="ml-2">Retired</Pill>}
                                    </td>
                                    <td className="ct-td text-right tabular-nums">{money(p.price)}</td>
                                    <td className="ct-td text-right tabular-nums">{money(p.credit)}</td>
                                    <td className="ct-td text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                        {money(Number(p.credit) - Number(p.price))}
                                    </td>
                                    <td className="ct-td text-right">
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" variant="outline" onClick={() => setEditingPackage(p)}>
                                                Edit
                                            </Button>
                                            <ConfirmButton
                                                size="sm"
                                                variant="ghost"
                                                title={`Delete ${p.name}?`}
                                                message="A package that has already been sold is retired instead, so past top-ups keep their reference."
                                                confirmLabel="Delete"
                                                onConfirm={async () => {
                                                    await api.deletePackage(p.id);
                                                    packages.reload();
                                                }}
                                            >
                                                Delete
                                            </ConfirmButton>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </section>

                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                            Membership tiers
                        </h2>
                        <Button
                            size="sm"
                            onClick={() => setEditingTier({ name: '', min_spend: '0', discount_percent: '0', is_active: true })}
                        >
                            New tier
                        </Button>
                    </div>

                    <Card className="overflow-hidden">
                        <Table
                            colSpan={4}
                            empty={!tiers.data?.length ? 'No tiers yet.' : null}
                            head={
                                <>
                                    <th className="ct-th">Tier</th>
                                    <th className="ct-th text-right">Reached at</th>
                                    <th className="ct-th text-right">Discount</th>
                                    <th className="ct-th text-right">Actions</th>
                                </>
                            }
                        >
                            {(tiers.data ?? []).map((t) => (
                                <tr key={t.id}>
                                    <td className="ct-td">
                                        <span className="font-medium">{t.name}</span>
                                        {!t.is_active && <Pill className="ml-2">Off</Pill>}
                                    </td>
                                    <td className="ct-td text-right tabular-nums">{money(t.min_spend)}</td>
                                    <td className="ct-td text-right tabular-nums">{Number(t.discount_percent)}%</td>
                                    <td className="ct-td text-right">
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" variant="outline" onClick={() => setEditingTier(t)}>
                                                Edit
                                            </Button>
                                            <ConfirmButton
                                                size="sm"
                                                variant="ghost"
                                                title={`Delete ${t.name}?`}
                                                message="Tiers are matched on computed lifetime spend, so removing one only changes who qualifies from now on."
                                                confirmLabel="Delete"
                                                onConfirm={async () => {
                                                    await api.deleteTier(t.id);
                                                    tiers.reload();
                                                }}
                                            >
                                                Delete
                                            </ConfirmButton>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <p className="mt-3 text-xs text-slate-500">
                        Lifetime spend counts paid, non-void invoices only, and is recomputed on the fly — voiding a
                        bill cannot leave a customer stranded in a tier they no longer qualify for.
                    </p>
                </section>
            </div>

            <EditModal
                title="package"
                record={editingPackage}
                fields={[
                    { key: 'name', label: 'Name', type: 'text', required: true },
                    { key: 'price', label: 'Price', type: 'number', required: true, hint: 'What the customer pays.' },
                    { key: 'credit', label: 'Credit', type: 'number', required: true, hint: 'What lands in their wallet.' },
                ]}
                onClose={() => setEditingPackage(null)}
                onSave={(id, body) => (id ? api.updatePackage(id, body) : api.createPackage(body))}
                onSaved={packages.reload}
            />

            <EditModal
                title="tier"
                record={editingTier}
                fields={[
                    { key: 'name', label: 'Name', type: 'text', required: true },
                    { key: 'min_spend', label: 'Reached at', type: 'number', hint: 'Lifetime spend needed.' },
                    { key: 'discount_percent', label: 'Discount %', type: 'number', hint: '0–100. Applied at checkout.' },
                ]}
                onClose={() => setEditingTier(null)}
                onSave={(id, body) => (id ? api.updateTier(id, body) : api.createTier(body))}
                onSaved={tiers.reload}
            />
        </>
    );
}

/** Packages and tiers differ only in their fields, so they share one dialog. */
function EditModal({ title, record, fields, onClose, onSave, onSaved }) {
    const [form, setForm] = useState({});
    const [seeded, setSeeded] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    if (record && seeded !== record) {
        setSeeded(record);
        setForm(
            fields.reduce(
                (acc, f) => ({ ...acc, [f.key]: String(record[f.key] ?? '') }),
                { is_active: record.is_active ?? true },
            ),
        );
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
            await onSave(record?.id, form);
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
        <Modal open={Boolean(record)} title={record?.id ? `Edit ${title}` : `New ${title}`} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                {fields.map((field) => (
                    <Field key={field.key} label={field.label} hint={field.hint}>
                        <Input
                            required={field.required}
                            type={field.type}
                            step={field.type === 'number' ? '0.01' : undefined}
                            min={field.type === 'number' ? '0' : undefined}
                            value={form[field.key] ?? ''}
                            onChange={set(field.key)}
                        />
                    </Field>
                ))}

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.is_active ?? true} onChange={set('is_active')} className="h-4 w-4 rounded" />
                    Active
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
