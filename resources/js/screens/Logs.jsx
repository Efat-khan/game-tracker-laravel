import { useState } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../lib/hooks';
import { dateTime, titleCase } from '../lib/format';
import { Card, ErrorNote, Field, Loading, PageHeader, Pill, Select, Table } from '../components/ui';

const ACTIONS = [
    'session_start', 'session_end', 'session_cancel',
    'invoice_paid', 'invoice_unpaid', 'invoice_payment_method', 'invoice_discount', 'invoice_void',
    'invoice_item_add', 'invoice_item_remove',
    'wallet_topup', 'wallet_adjust',
    'shift_open', 'shift_close', 'cash_in', 'cash_out',
    'station_create', 'station_update', 'station_delete', 'station_maintenance',
    'product_create', 'product_update', 'product_delete',
    'package_create', 'package_update', 'package_delete',
    'tier_create', 'tier_update', 'tier_delete',
    'booking_create', 'booking_update', 'booking_start', 'booking_cancel',
    'settings_update',
    'staff_create', 'staff_update', 'staff_revoke', 'staff_delete',
];

const ROLE_TONE = { admin: 'indigo', staff: 'slate', superadmin: 'green', public: 'amber' };

export default function Logs() {
    const [action, setAction] = useState('');
    const { data, error, loading, reload } = useAsync(() => api.audit({ action, limit: 200 }), [action]);

    const events = data ?? [];

    return (
        <>
            <PageHeader title="Activity log" subtitle="Append-only. Every money-affecting action, and who did it." />

            <Card className="mb-4 p-4">
                <Field label="Action">
                    <Select value={action} onChange={(e) => setAction(e.target.value)} className="max-w-sm">
                        <option value="">Everything</option>
                        {ACTIONS.map((a) => (
                            <option key={a} value={a}>
                                {titleCase(a)}
                            </option>
                        ))}
                    </Select>
                </Field>
            </Card>

            <ErrorNote error={error} onRetry={reload} />

            {loading && !data ? (
                <Loading />
            ) : (
                <Card className="overflow-hidden">
                    <Table
                        colSpan={4}
                        empty={events.length === 0 ? 'Nothing logged for that filter.' : null}
                        head={
                            <>
                                <th className="ct-th">When</th>
                                <th className="ct-th">Who</th>
                                <th className="ct-th">Action</th>
                                <th className="ct-th">What happened</th>
                            </>
                        }
                    >
                        {events.map((event) => (
                            <tr key={event.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td className="ct-td whitespace-nowrap">{dateTime(event.created_at)}</td>
                                <td className="ct-td">
                                    <p className="truncate">{event.actor_email}</p>
                                    <Pill tone={ROLE_TONE[event.actor_role] ?? 'slate'}>{event.actor_role}</Pill>
                                </td>
                                <td className="ct-td whitespace-nowrap text-xs text-slate-500">{titleCase(event.action)}</td>
                                <td className="ct-td">{event.summary}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </>
    );
}
