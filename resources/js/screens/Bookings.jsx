import { useState } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../lib/hooks';
import { dateTime, fromLocalInput, time, titleCase, toLocalInput } from '../lib/format';
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

const STATUS_TONE = { booked: 'indigo', arrived: 'green', cancelled: 'slate', no_show: 'red' };

export default function Bookings() {
    const [status, setStatus] = useState('booked');
    const [creating, setCreating] = useState(false);
    const [actionError, setActionError] = useState(null);

    const stations = useAsync(() => api.stations(), []);
    const { data, error, loading, reload } = useAsync(() => api.bookings({ status, limit: 200 }), [status]);

    const bookings = data ?? [];

    async function act(fn) {
        setActionError(null);
        try {
            await fn();
            reload();
        } catch (err) {
            setActionError(err);
        }
    }

    return (
        <>
            <PageHeader title="Bookings" subtitle="Reserved slots. Arrived turns one into a live session.">
                <Button onClick={() => setCreating(true)}>New booking</Button>
            </PageHeader>

            <Card className="mb-4 p-4">
                <Field label="Status">
                    <Select value={status} onChange={(e) => setStatus(e.target.value)} className="max-w-xs">
                        <option value="">All</option>
                        <option value="booked">On the books</option>
                        <option value="arrived">Arrived</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="no_show">No-show</option>
                    </Select>
                </Field>
            </Card>

            <ErrorNote error={error || actionError} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <Card className="mt-4 overflow-hidden">
                    <Table
                        colSpan={6}
                        empty={bookings.length === 0 ? 'Nothing booked.' : null}
                        head={
                            <>
                                <th className="ct-th">Slot</th>
                                <th className="ct-th">Station</th>
                                <th className="ct-th">Customer</th>
                                <th className="ct-th text-right">Pads</th>
                                <th className="ct-th">Status</th>
                                <th className="ct-th text-right">Actions</th>
                            </>
                        }
                    >
                        {bookings.map((booking) => (
                            <tr key={booking.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td className="ct-td whitespace-nowrap">
                                    {dateTime(booking.starts_at)}
                                    <span className="text-slate-400"> → {time(booking.ends_at)}</span>
                                </td>
                                <td className="ct-td">{booking.station_name}</td>
                                <td className="ct-td">
                                    <p>{booking.customer_name}</p>
                                    <p className="text-xs text-slate-500">{booking.customer_phone}</p>
                                </td>
                                <td className="ct-td text-right tabular-nums">{booking.controllers}</td>
                                <td className="ct-td">
                                    <Pill tone={STATUS_TONE[booking.status]}>{titleCase(booking.status)}</Pill>
                                </td>
                                <td className="ct-td text-right">
                                    {booking.status === 'booked' && (
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                size="sm"
                                                variant="success"
                                                onClick={() => act(() => api.startBooking(booking.id))}
                                            >
                                                Arrived
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => act(() => api.cancelBooking(booking.id, true))}
                                            >
                                                No-show
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => act(() => api.cancelBooking(booking.id, false))}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            <BookingModal
                open={creating}
                stations={stations.data ?? []}
                onClose={() => setCreating(false)}
                onDone={reload}
            />
        </>
    );
}

function BookingModal({ open, stations, onClose, onDone }) {
    const now = new Date();
    const inAnHour = new Date(now.getTime() + 3600_000);
    const inThreeHours = new Date(now.getTime() + 3 * 3600_000);

    const [form, setForm] = useState({
        station_id: '',
        customer_name: '',
        customer_phone: '',
        starts_at: toLocalInput(inAnHour),
        ends_at: toLocalInput(inThreeHours),
        controllers: 1,
        note: '',
    });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.createBooking({
                ...form,
                station_id: Number(form.station_id),
                controllers: Number(form.controllers),
                // The inputs are local wall-clock; the API stores naive UTC.
                starts_at: fromLocalInput(form.starts_at),
                ends_at: fromLocalInput(form.ends_at),
            });
            onDone();
            onClose();
        } catch (err) {
            // A 409 names who already holds the slot — show it verbatim.
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open={open} title="New booking" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Station">
                    <Select required value={form.station_id} onChange={set('station_id')}>
                        <option value="">Choose a station…</option>
                        {stations
                            .filter((s) => s.is_active)
                            .map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                    </Select>
                </Field>

                <Field label="Customer name">
                    <Input required maxLength={150} value={form.customer_name} onChange={set('customer_name')} />
                </Field>

                <Field label="Phone">
                    <Input required maxLength={100} value={form.customer_phone} onChange={set('customer_phone')} />
                </Field>

                <div className="grid grid-cols-2 gap-3">
                    <Field label="From">
                        <Input required type="datetime-local" value={form.starts_at} onChange={set('starts_at')} />
                    </Field>
                    <Field label="To">
                        <Input required type="datetime-local" value={form.ends_at} onChange={set('ends_at')} />
                    </Field>
                </div>

                <Field label="Controllers">
                    <Input type="number" min={1} max={8} value={form.controllers} onChange={set('controllers')} />
                </Field>

                <Field label="Note">
                    <Input maxLength={200} value={form.note} onChange={set('note')} />
                </Field>

                {error && (
                    <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        {error}
                    </p>
                )}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Book
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
