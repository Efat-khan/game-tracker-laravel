import { useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/hooks';
import { dateTime } from '../lib/format';
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
} from '../components/ui';

export default function Cafes() {
    const { isSuperadmin, cafe, workInCafe } = useAuth();
    const { data, error, loading, reload } = useAsync(() => api.myCafes(), []);
    const [creating, setCreating] = useState(false);
    const [actionError, setActionError] = useState(null);

    const cafes = data ?? [];

    async function setActive(row, isActive) {
        setActionError(null);
        try {
            await api.updateCafe(row.id, { is_active: isActive });
            reload();
        } catch (err) {
            setActionError(err);
        }
    }

    if (loading && !data) return <Loading />;

    return (
        <>
            <PageHeader
                title="Cafes"
                subtitle={
                    isSuperadmin
                        ? 'Every cafe on the platform. Pick one to work inside it.'
                        : 'The cafe this account belongs to.'
                }
            >
                {isSuperadmin && <Button onClick={() => setCreating(true)}>New cafe</Button>}
            </PageHeader>

            <ErrorNote error={error || actionError} onRetry={reload} />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {cafes.map((row) => {
                    const active = cafe?.id === row.id;

                    return (
                        <Card
                            key={row.id}
                            className={`p-5 ${active ? 'ring-2 ring-indigo-500' : ''}`}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate text-base font-semibold">{row.name}</p>
                                    <p className="truncate text-xs text-slate-500">{row.slug}</p>
                                </div>
                                {row.is_active ? <Pill tone="green">Active</Pill> : <Pill tone="red">Suspended</Pill>}
                            </div>

                            {isSuperadmin && (
                                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-slate-500">Stations</dt>
                                        <dd className="font-semibold tabular-nums">{row.station_count ?? 0}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-slate-500">Accounts</dt>
                                        <dd className="font-semibold tabular-nums">{row.account_count ?? 0}</dd>
                                    </div>
                                </dl>
                            )}

                            <p className="mt-3 text-xs text-slate-500">Opened {dateTime(row.created_at)}</p>

                            {isSuperadmin && row.features && (
                                <FeatureSwitches
                                    cafe={row}
                                    onChanged={reload}
                                    onError={setActionError}
                                />
                            )}

                            {isSuperadmin && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant={active ? 'subtle' : 'primary'}
                                        onClick={() => workInCafe(row)}
                                        disabled={active}
                                    >
                                        {active ? 'Working here' : 'Work in this cafe'}
                                    </Button>

                                    {row.is_active ? (
                                        <Button size="sm" variant="outline" onClick={() => setActive(row, false)}>
                                            Suspend
                                        </Button>
                                    ) : (
                                        <Button size="sm" variant="outline" onClick={() => setActive(row, true)}>
                                            Restore
                                        </Button>
                                    )}
                                </div>
                            )}
                        </Card>
                    );
                })}
            </div>

            {isSuperadmin && (
                <p className="mt-4 text-xs text-slate-500">
                    Suspending a cafe stops everyone in it signing in — its data is untouched. Switching a module
                    off hides it from that cafe's sidebar and refuses its routes; existing records are kept, so
                    turning it back on restores everything.
                </p>
            )}

            <NewCafeModal open={creating} onClose={() => setCreating(false)} onDone={reload} />
        </>
    );
}

/**
 * The owner's grant: which optional modules this cafe gets.
 *
 * Only rendered for a superadmin, and only because the API sends `features`
 * to them alone — a cafe admin cannot see or change their own grant, or they
 * would simply switch on whatever they had not been given.
 */
function FeatureSwitches({ cafe, onChanged, onError }) {
    const [busy, setBusy] = useState(null);

    async function toggle(feature, enabled) {
        setBusy(feature.key);

        try {
            await api.setCafeFeatures(cafe.id, { [feature.key]: enabled });
            onChanged();
        } catch (err) {
            onError(err);
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className="mt-4 border-t border-slate-200 pt-3 dark:border-slate-800">
            <p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                Modules
            </p>

            <ul className="space-y-1.5">
                {cafe.features.map((feature) => (
                    <li key={feature.key} className="flex items-start justify-between gap-3">
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">{feature.label}</span>
                            <span className="block text-xs text-slate-500">{feature.blurb}</span>
                        </span>

                        <button
                            type="button"
                            role="switch"
                            aria-checked={feature.enabled}
                            aria-label={`${feature.label} for ${cafe.name}`}
                            disabled={busy === feature.key}
                            onClick={() => toggle(feature, !feature.enabled)}
                            className={`relative mt-0.5 h-5 w-9 shrink-0 rounded-full transition disabled:opacity-50 ${
                                feature.enabled
                                    ? 'bg-indigo-600 shadow-[0_0_14px_-4px_var(--color-indigo-500)]'
                                    : 'bg-slate-300 dark:bg-slate-700'
                            }`}
                        >
                            <span
                                className={`absolute top-0.5 h-4 w-4 rounded-full bg-white transition-all ${
                                    feature.enabled ? 'left-[1.125rem]' : 'left-0.5'
                                }`}
                            />
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function NewCafeModal({ open, onClose, onDone }) {
    const [form, setForm] = useState({ name: '', admin_email: '', admin_password: '', contact_email: '' });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.createCafe(form);
            onDone();
            close();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    function close() {
        setForm({ name: '', admin_email: '', admin_password: '', contact_email: '' });
        setError(null);
        onClose();
    }

    return (
        <Modal open={open} title="Onboard a cafe" onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-slate-600 dark:text-slate-400">
                    Creates the cafe and its first admin account in one go. That admin can then add their own staff.
                </p>

                <Field label="Cafe name">
                    <Input required maxLength={150} value={form.name} onChange={set('name')} autoFocus />
                </Field>

                <Field label="Admin email" hint="Must be unused across the whole platform.">
                    <Input required type="email" maxLength={150} value={form.admin_email} onChange={set('admin_email')} />
                </Field>

                <Field label="Admin password" hint="At least 6 characters.">
                    <Input
                        required
                        type="password"
                        minLength={6}
                        value={form.admin_password}
                        onChange={set('admin_password')}
                        autoComplete="new-password"
                    />
                </Field>

                <Field label="Contact email">
                    <Input type="email" maxLength={150} value={form.contact_email} onChange={set('contact_email')} />
                </Field>

                {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" busy={busy}>
                        Create
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
