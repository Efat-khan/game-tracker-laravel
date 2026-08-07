import { useState } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../lib/hooks';
import { dateTime, money, titleCase } from '../lib/format';
import { Card, ErrorNote, Field, Input, Loading, PageHeader, Pill, Select, Table } from '../components/ui';

const STATUS_TONE = { active: 'indigo', completed: 'green', cancelled: 'slate' };

export default function Sessions() {
    const [filters, setFilters] = useState({
        station_id: '',
        status: '',
        customer_id: '',
        date_from: '',
        date_to: '',
    });

    const stations = useAsync(() => api.stations(), []);
    const { data, error, loading, reload } = useAsync(
        () => api.sessions({ ...filters, limit: 200 }),
        [filters.station_id, filters.status, filters.customer_id, filters.date_from, filters.date_to],
    );

    const set = (key) => (event) => setFilters((f) => ({ ...f, [key]: event.target.value }));
    const sessions = data ?? [];

    return (
        <>
            <PageHeader title="Sessions" subtitle="Everything that has played here." />

            <Card className="mb-4 p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Station">
                        <Select value={filters.station_id} onChange={set('station_id')}>
                            <option value="">All stations</option>
                            {(stations.data ?? []).map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label="Status">
                        <Select value={filters.status} onChange={set('status')}>
                            <option value="">Any status</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
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

            <ErrorNote error={error} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <Card className="overflow-hidden">
                    <Table
                        colSpan={7}
                        empty={sessions.length === 0 ? 'No sessions match those filters.' : null}
                        head={
                            <>
                                <th className="ct-th">Started</th>
                                <th className="ct-th">Station</th>
                                <th className="ct-th">Customer</th>
                                <th className="ct-th">Pads</th>
                                <th className="ct-th">Rate</th>
                                <th className="ct-th">Ended</th>
                                <th className="ct-th">Status</th>
                            </>
                        }
                    >
                        {sessions.map((session) => (
                            <tr key={session.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td className="ct-td whitespace-nowrap">{dateTime(session.start_time)}</td>
                                <td className="ct-td">{session.station_name}</td>
                                <td className="ct-td">{session.customer_name}</td>
                                <td className="ct-td tabular-nums">{session.controllers}</td>
                                <td className="ct-td tabular-nums">{money(session.hourly_rate)}</td>
                                <td className="ct-td whitespace-nowrap">
                                    {session.end_time ? dateTime(session.end_time) : '—'}
                                </td>
                                <td className="ct-td">
                                    <Pill tone={STATUS_TONE[session.status]}>{titleCase(session.status)}</Pill>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </>
    );
}
