import { useRef, useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranding } from '../lib/branding';
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
    Select,
} from '../components/ui';

export default function Cafes() {
    const { isSuperadmin, cafe, workInCafe } = useAuth();
    const { data, error, loading, reload } = useAsync(() => api.myCafes(), []);
    const [creating, setCreating] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [actionError, setActionError] = useState(null);

    const cafes = data ?? [];

    // Read the cafe being edited back out of the list rather than holding a
    // copy, so a rename is reflected in the dialog's own title the moment it
    // saves instead of leaving the old name sitting at the top.
    const editing = cafes.find((row) => row.id === editingId) ?? null;

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

            {isSuperadmin && <BrandingCard onError={setActionError} />}

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

                                    <Button size="sm" variant="outline" onClick={() => setEditingId(row.id)}>
                                        Edit
                                    </Button>

                                    {row.is_active ? (
                                        <Button size="sm" variant="ghost" onClick={() => setActive(row, false)}>
                                            Suspend
                                        </Button>
                                    ) : (
                                        <Button size="sm" variant="ghost" onClick={() => setActive(row, true)}>
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
            <EditCafeModal cafe={editing} onClose={() => setEditingId(null)} onDone={reload} />
        </>
    );
}

/* ------------------------------------------------------------------ branding */

const ASSETS = [
    {
        key: 'login-background',
        field: 'loginBackgroundUrl',
        title: 'Login background',
        hint: 'The photograph behind the sign-in form. Landscape, 1600×900 or larger.',
        // Wide, because that is the shape it is used at.
        preview: 'aspect-[16/9] bg-cover bg-center',
    },
    {
        key: 'logo',
        field: 'logoUrl',
        title: 'Logo',
        hint: 'Shown in the sidebar and on the sign-in page. A square with room around the mark works best.',
        // Boxed, because a square preview at column width dwarfs everything
        // else on the screen and tells you nothing extra.
        preview: 'aspect-square w-40 bg-contain bg-center bg-no-repeat',
    },
];

/**
 * Platform branding. Superadmin only, and platform-wide rather than per cafe —
 * these are what somebody sees before they have signed in, when the app does
 * not yet know which cafe they belong to.
 */
function BrandingCard({ onError }) {
    const branding = useBranding();

    return (
        <Card className="mb-4 p-5">
            <h2 className="text-sm font-semibold">Branding</h2>
            <p className="mt-0.5 text-xs text-slate-500">
                Applies to every cafe on the platform. Remove an image to go back to the built-in look.
            </p>

            <div className="mt-4 grid items-start gap-5 sm:grid-cols-2">
                {ASSETS.map((asset) => (
                    <AssetUpload
                        key={asset.key}
                        asset={asset}
                        url={branding[asset.field]}
                        onChanged={branding.reload}
                        onError={onError}
                    />
                ))}
            </div>
        </Card>
    );
}

function AssetUpload({ asset, url, onChanged, onError }) {
    const input = useRef(null);
    const [busy, setBusy] = useState(false);

    async function run(work) {
        setBusy(true);
        onError(null);

        try {
            await work();
            await onChanged();
        } catch (err) {
            onError(err);
        } finally {
            setBusy(false);
        }
    }

    async function choose(event) {
        const file = event.target.files?.[0];
        // Cleared straight away so picking the same file twice still fires a
        // change event — otherwise a failed upload cannot be retried as-is.
        event.target.value = '';
        if (file) await run(() => api.uploadBranding(asset.key, file));
    }

    return (
        <div>
            <p className="text-xs font-semibold">{asset.title}</p>
            <p className="mt-0.5 text-[11px] leading-relaxed text-slate-500">{asset.hint}</p>

            <div
                className={`mt-2.5 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800 ${asset.preview}`}
                style={url ? { backgroundImage: `url(${JSON.stringify(url)})` } : undefined}
            >
                {!url && (
                    <div className="flex h-full items-center justify-center bg-slate-100 dark:bg-slate-900">
                        <p className="text-[11px] text-slate-500">Using the built-in look</p>
                    </div>
                )}
            </div>

            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={choose}
                className="hidden"
            />

            <div className="mt-2.5 flex flex-wrap gap-2">
                <Button size="sm" variant="outline" busy={busy} onClick={() => input.current?.click()}>
                    {url ? 'Replace' : 'Upload'}
                </Button>

                {url && (
                    <Button
                        size="sm"
                        variant="ghost"
                        busy={busy}
                        onClick={() => run(() => api.removeBranding(asset.key))}
                    >
                        Remove
                    </Button>
                )}
            </div>

            <p className="mt-1.5 text-[10px] text-slate-500">JPEG, PNG or WebP, up to 5 MB.</p>
        </div>
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

/* ---------------------------------------------------------------- editing */

/**
 * Everything about a cafe that can change after it is opened: its details, and
 * the accounts that run it.
 *
 * The accounts half talks to the ordinary /staff routes with `inCafe` set,
 * which is how a superadmin acts inside a cafe for one request without
 * switching the whole app into it. Those routes already refuse to demote or
 * delete the last admin, so a cafe cannot be left with nobody able to
 * administer it.
 */
function EditCafeModal({ cafe, onClose, onDone }) {
    return (
        <Modal open={Boolean(cafe)} title={`Edit ${cafe?.name ?? ''}`} onClose={onClose} wide>
            {cafe && (
                // Keyed on the cafe so opening a different one starts from its
                // own values rather than inheriting the last one's form state.
                <div className="space-y-7" key={cafe.id}>
                    <CafeDetailsForm cafe={cafe} onDone={onDone} />
                    <CafeAccounts cafe={cafe} />
                </div>
            )}
        </Modal>
    );
}

function CafeDetailsForm({ cafe, onDone }) {
    const [form, setForm] = useState({ name: cafe.name, contact_email: cafe.contact_email ?? '' });
    const [error, setError] = useState(null);
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);

    const set = (key) => (event) => {
        setSaved(false);
        setForm((f) => ({ ...f, [key]: event.target.value }));
    };

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.updateCafe(cafe.id, { name: form.name, contact_email: form.contact_email || null });
            setSaved(true);
            onDone();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Details</h3>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Cafe name" hint="The web address under the name follows this.">
                    <Input required maxLength={150} value={form.name} onChange={set('name')} />
                </Field>

                <Field label="Contact email">
                    <Input type="email" maxLength={150} value={form.contact_email} onChange={set('contact_email')} />
                </Field>
            </div>

            {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

            <div className="flex items-center justify-end gap-3">
                {saved && !error && <span className="text-xs text-emerald-600 dark:text-emerald-400">Saved.</span>}
                <Button type="submit" size="sm" busy={busy}>
                    Save details
                </Button>
            </div>
        </form>
    );
}

function CafeAccounts({ cafe }) {
    const { data, error, loading, reload } = useAsync(() => api.staff(cafe.id), [cafe.id]);
    const [actionError, setActionError] = useState(null);
    const [adding, setAdding] = useState(false);

    const accounts = data ?? [];

    async function run(work) {
        setActionError(null);
        try {
            await work();
            reload();
        } catch (err) {
            setActionError(err);
        }
    }

    return (
        <div>
            <div className="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Accounts</h3>
                    <p className="mt-0.5 text-xs text-slate-500">
                        Who can sign in to this cafe. Changing an email, password or role signs that person out
                        everywhere.
                    </p>
                </div>
                <Button size="sm" variant="outline" onClick={() => setAdding((a) => !a)}>
                    {adding ? 'Cancel' : 'Add account'}
                </Button>
            </div>

            <ErrorNote error={error || actionError} onRetry={reload} />

            {adding && (
                <AddAccountForm
                    cafeId={cafe.id}
                    onDone={() => {
                        setAdding(false);
                        reload();
                    }}
                />
            )}

            {loading && !data ? (
                <Loading />
            ) : (
                <ul className="space-y-2">
                    {accounts.map((account) => (
                        <AccountRow
                            key={account.id}
                            account={account}
                            cafeId={cafe.id}
                            onRun={run}
                            isLastAdmin={
                                account.role === 'admin' && accounts.filter((a) => a.role === 'admin').length === 1
                            }
                        />
                    ))}

                    {accounts.length === 0 && (
                        <li className="rounded-xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-700">
                            This cafe has no accounts — nobody can sign in to it.
                        </li>
                    )}
                </ul>
            )}

        </div>
    );
}

function AccountRow({ account, cafeId, onRun, isLastAdmin }) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [email, setEmail] = useState(account.email);
    const [password, setPassword] = useState('');

    async function save(event) {
        event.preventDefault();

        const body = {};
        if (email.trim() && email.trim() !== account.email) body.email = email.trim();
        if (password) body.password = password;

        if (Object.keys(body).length === 0) {
            setEditing(false);
            return;
        }

        await onRun(() => api.updateStaff(account.id, body, cafeId));
        setPassword('');
        setEditing(false);
    }

    return (
        <li className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
            {editing ? (
                <form onSubmit={save} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Email">
                            <Input required type="email" maxLength={150} value={email} onChange={(e) => setEmail(e.target.value)} />
                        </Field>
                        <Field label="New password" hint="Leave blank to keep the current one.">
                            <Input
                                type="password"
                                minLength={6}
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                autoComplete="new-password"
                            />
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setEmail(account.email);
                                setPassword('');
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" size="sm">
                            Save
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{account.email}</p>
                        <p className="mt-0.5 text-xs text-slate-500">
                            {account.role === 'admin' ? 'Admin' : 'Staff'} · opened {dateTime(account.created_at)}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                            Change
                        </Button>

                        {/* The last admin cannot be demoted — the API refuses
                            it too, and a cafe with no admin is a cafe only the
                            platform owner can fix. */}
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={isLastAdmin}
                            title={isLastAdmin ? 'The last admin cannot be demoted.' : undefined}
                            onClick={() =>
                                onRun(() =>
                                    api.updateStaff(
                                        account.id,
                                        { role: account.role === 'admin' ? 'staff' : 'admin' },
                                        cafeId,
                                    ),
                                )
                            }
                        >
                            Make {account.role === 'admin' ? 'staff' : 'admin'}
                        </Button>

                        {confirming ? (
                            <>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={async () => {
                                        await onRun(() => api.deleteStaff(account.id, cafeId));
                                        setConfirming(false);
                                    }}
                                >
                                    Really delete
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setConfirming(false)}>
                                    Keep
                                </Button>
                            </>
                        ) : (
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={isLastAdmin}
                                onClick={() => setConfirming(true)}
                            >
                                Delete
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </li>
    );
}

/** Rendered inline inside the edit dialog — a modal on a modal would take two
 *  presses of Escape to get out of. */
function AddAccountForm({ cafeId, onDone }) {
    const [form, setForm] = useState({ email: '', password: '', role: 'admin' });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.createStaff(form, cafeId);
            onDone();
        } catch (err) {
            setError(err.firstError || err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            onSubmit={submit}
            className="mb-3 space-y-3 rounded-xl border border-indigo-300 bg-indigo-50/50 p-3 dark:border-indigo-500/40 dark:bg-indigo-500/5"
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Email" hint="Unused platform-wide.">
                    <Input required autoFocus type="email" maxLength={150} value={form.email} onChange={set('email')} />
                </Field>

                <Field label="Password" hint="At least 6 characters.">
                    <Input
                        required
                        type="password"
                        minLength={6}
                        value={form.password}
                        onChange={set('password')}
                        autoComplete="new-password"
                    />
                </Field>

                <Field label="Role">
                    <Select value={form.role} onChange={set('role')}>
                        <option value="admin">Admin</option>
                        <option value="staff">Staff</option>
                    </Select>
                </Field>
            </div>

            {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

            <div className="flex justify-end">
                <Button type="submit" size="sm" busy={busy}>
                    Add account
                </Button>
            </div>
        </form>
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
