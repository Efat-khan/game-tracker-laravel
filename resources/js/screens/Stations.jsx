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
    max_controllers: 4,
    is_active: true,
    // One price per controller count. The 1-controller entry is the station's
    // headline hourly rate.
    rates: { 1: '150', 2: '200', 3: '260', 4: '320' },
};

/** The saved price list as a form-friendly {count: "text"} map. */
function ratesFrom(station) {
    const max = station.max_controllers ?? 1;
    const saved = station.rates ?? {};
    const base = String(station.hourly_rate ?? '0');
    const out = {};

    for (let n = 1; n <= max; n++) out[n] = String(saved[n] ?? saved[String(n)] ?? base);

    return out;
}

/** The whole price list on one line of the stations table. */
function RateChips({ rates }) {
    const entries = Object.entries(rates ?? {}).sort((a, b) => Number(a[0]) - Number(b[0]));

    if (entries.length === 0) return <span className="text-xs text-slate-400">—</span>;

    return (
        <div className="flex flex-wrap gap-1">
            {entries.map(([controllers, rate]) => (
                <span
                    key={controllers}
                    title={`${controllers} controller${controllers === '1' ? '' : 's'}`}
                    className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] tabular-nums text-slate-600
                               dark:bg-slate-800 dark:text-slate-300"
                >
                    <span className="text-slate-400">{controllers}×</span> {money(rate)}
                </span>
            ))}
        </div>
    );
}

/**
 * A rate per controller count.
 *
 * One row per count up to the station's maximum, because a real price list does
 * not step evenly — 100 / 120 / 160 / 200 adds 20 for the second pad and 40 for
 * the third and fourth, which no single "extra controller" figure can express.
 */
function RateTable({ max, rates, onChange }) {
    const counts = Array.from({ length: Math.max(1, Math.min(8, max)) }, (_, i) => i + 1);

    return (
        <div>
            <span className="ct-label">Hourly rate by controllers</span>
            <p className="-mt-0.5 mb-2 text-xs text-slate-500">
                What one hour costs for each number of pads. Set them independently — they do not have to step
                evenly.
            </p>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {counts.map((n) => (
                    <label key={n} className="block">
                        <span className="mb-1 block text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                            {n} controller{n === 1 ? '' : 's'}
                        </span>
                        <Input
                            required
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={rates[n] ?? ''}
                            onChange={(e) => onChange(n, e.target.value)}
                        />
                    </label>
                ))}
            </div>

            <p className="mt-2 text-xs text-slate-500">
                The 1-controller price is the station's headline rate, shown on the floor and on its QR page.
            </p>
        </div>
    );
}

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
                            <th className="ct-th">By controllers</th>
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
                            <td className="ct-td">
                                <RateChips rates={station.rates} />
                            </td>
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
            max_controllers: station.max_controllers ?? 4,
            is_active: station.is_active ?? true,
            rates: ratesFrom(station),
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

        const max = Number(form.max_controllers) || 1;

        // Only the counts this station can actually reach are sent; the API
        // refuses a list with a hole in it or an entry above the maximum.
        const rates = {};
        for (let n = 1; n <= max; n++) rates[n] = form.rates[n] ?? form.rates[1] ?? '0';

        const body = {
            name: form.name,
            type: form.type,
            is_active: form.is_active,
            max_controllers: max,
            // hourly_rate is the 1-controller price; the server keeps the two
            // in step, but sending it keeps the payload self-describing.
            hourly_rate: rates[1],
            rates,
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

                <Field label="Max controllers" hint="1–8. A check-in above this is refused.">
                    <Input type="number" min={1} max={8} value={form.max_controllers} onChange={set('max_controllers')} />
                </Field>

                <RateTable
                    max={Number(form.max_controllers) || 1}
                    rates={form.rates}
                    onChange={(n, value) => setForm((f) => ({ ...f, rates: { ...f.rates, [n]: value } }))}
                />

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
