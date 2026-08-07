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

const BLANK = {
    name: '',
    type: 'PS5',
    hourly_rate: '150',
    extra_controller_rate: '50',
    max_controllers: 4,
    is_active: true,
};

export default function Stations() {
    const { isAdmin } = useAuth();
    const { data, error, loading, reload } = useAsync(() => api.stations(), []);
    const [editing, setEditing] = useState(null);
    const [qr, setQr] = useState(null);

    const stations = data ?? [];

    if (loading && !data) return <Loading />;

    return (
        <>
            <PageHeader title="Stations" subtitle="Every device you rent out, and the sticker that starts it.">
                {isAdmin && <Button onClick={() => setEditing(BLANK)}>New station</Button>}
            </PageHeader>

            <ErrorNote error={error} onRetry={reload} />

            <Card className="mt-4 overflow-hidden">
                <Table
                    colSpan={7}
                    empty={stations.length === 0 ? 'No stations yet.' : null}
                    head={
                        <>
                            <th className="ct-th">Station</th>
                            <th className="ct-th">Rate</th>
                            <th className="ct-th">Extra / controller</th>
                            <th className="ct-th">Max</th>
                            <th className="ct-th">Status</th>
                            <th className="ct-th">QR</th>
                            <th className="ct-th text-right">{isAdmin ? 'Actions' : ''}</th>
                        </>
                    }
                >
                    {stations.map((station) => (
                        <tr key={station.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                            <td className="ct-td">
                                <p className="font-medium text-slate-900 dark:text-slate-100">{station.name}</p>
                                <p className="text-xs text-slate-500">{station.type}</p>
                            </td>
                            <td className="ct-td tabular-nums">{money(station.hourly_rate)}</td>
                            <td className="ct-td tabular-nums">{money(station.extra_controller_rate)}</td>
                            <td className="ct-td tabular-nums">{station.max_controllers}</td>
                            <td className="ct-td">
                                {station.maintenance ? (
                                    <Pill tone="amber">Out of service</Pill>
                                ) : station.is_active ? (
                                    <Pill tone="green">Active</Pill>
                                ) : (
                                    <Pill>Retired</Pill>
                                )}
                            </td>
                            <td className="ct-td">
                                <button
                                    onClick={() => setQr(station)}
                                    className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Preview
                                </button>
                            </td>
                            <td className="ct-td text-right">
                                {isAdmin && (
                                    <div className="flex justify-end gap-2">
                                        <Button size="sm" variant="outline" onClick={() => setEditing(station)}>
                                            Edit
                                        </Button>
                                        <ConfirmButton
                                            size="sm"
                                            variant="ghost"
                                            title={`Delete ${station.name}?`}
                                            message="If this station has any session or booking history it is retired instead of deleted, so its past invoices still point somewhere."
                                            confirmLabel="Delete"
                                            onConfirm={async () => {
                                                await api.deleteStation(station.id);
                                                reload();
                                            }}
                                        >
                                            Delete
                                        </ConfirmButton>
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <StationModal station={editing} onClose={() => setEditing(null)} onSaved={reload} />
            <QrModal station={qr} onClose={() => setQr(null)} />
        </>
    );
}

function StationModal({ station, onClose, onSaved }) {
    const [form, setForm] = useState(BLANK);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [seeded, setSeeded] = useState(null);

    // Load the row into the form the first time this station opens.
    if (station && seeded !== station) {
        setSeeded(station);
        setForm({
            name: station.name ?? '',
            type: station.type ?? 'PS5',
            hourly_rate: String(station.hourly_rate ?? '150'),
            extra_controller_rate: String(station.extra_controller_rate ?? '0'),
            max_controllers: station.max_controllers ?? 4,
            is_active: station.is_active ?? true,
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

        const body = {
            ...form,
            max_controllers: Number(form.max_controllers),
        };

        try {
            station?.id ? await api.updateStation(station.id, body) : await api.createStation(body);
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
        <Modal open={Boolean(station)} title={station?.id ? `Edit ${station.name}` : 'New station'} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Name">
                    <Input required maxLength={100} value={form.name} onChange={set('name')} placeholder="PS5 - Booth 1" />
                </Field>

                <Field label="Type">
                    <Input required maxLength={50} value={form.type} onChange={set('type')} placeholder="PS5" />
                </Field>

                <div className="grid grid-cols-2 gap-3">
                    <Field label="Hourly rate" hint="Covers the first controller.">
                        <Input required type="number" min="0.01" step="0.01" value={form.hourly_rate} onChange={set('hourly_rate')} />
                    </Field>

                    <Field label="Extra / controller" hint="Added per hour, per extra pad.">
                        <Input type="number" min="0" step="0.01" value={form.extra_controller_rate} onChange={set('extra_controller_rate')} />
                    </Field>
                </div>

                <Field label="Max controllers" hint="1–8. A check-in above this is refused.">
                    <Input type="number" min={1} max={8} value={form.max_controllers} onChange={set('max_controllers')} />
                </Field>

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.is_active} onChange={set('is_active')} className="h-4 w-4 rounded" />
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

function QrModal({ station, onClose }) {
    if (!station) return null;

    const src = api.qrCodeUrl(station.id);

    return (
        <Modal open title={`${station.name} — QR sticker`} onClose={onClose}>
            <div className="text-center">
                <img src={src} alt={`QR code for ${station.name}`} className="mx-auto h-64 w-64 rounded-lg bg-white p-2" />
                <p className="mt-3 break-all text-xs text-slate-500">{station.qr_code_url}</p>
                <p className="mt-2 text-xs text-slate-500">
                    The link is signed, so a guessed station number will not open a check-in.
                </p>
                <a
                    href={src}
                    download={`station-${station.id}.png`}
                    className="mt-4 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    Download PNG
                </a>
            </div>
        </Modal>
    );
}
