import { useState } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/hooks';
import { dateTime } from '../lib/format';
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
    Select,
    Table,
} from '../components/ui';

export default function Staff() {
    const { session } = useAuth();
    const { data, error, loading, reload } = useAsync(() => api.staff(), []);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);
    const [actionError, setActionError] = useState(null);

    const people = data ?? [];

    async function act(fn) {
        setActionError(null);
        try {
            await fn();
            reload();
        } catch (err) {
            setActionError(err);
        }
    }

    if (loading && !data) return <Loading />;

    return (
        <>
            <PageHeader title="Staff" subtitle="Accounts inside this cafe.">
                <Button onClick={() => setCreating(true)}>New account</Button>
            </PageHeader>

            <ErrorNote error={error || actionError} onRetry={reload} />

            <Card className="mt-4 overflow-hidden">
                <Table
                    colSpan={4}
                    empty={people.length === 0 ? 'No accounts yet.' : null}
                    head={
                        <>
                            <th className="ct-th">Email</th>
                            <th className="ct-th">Role</th>
                            <th className="ct-th">Created</th>
                            <th className="ct-th text-right">Actions</th>
                        </>
                    }
                >
                    {people.map((person) => (
                        <tr key={person.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                            <td className="ct-td">
                                {person.email}
                                {person.email === session?.email && (
                                    <Pill className="ml-2">You</Pill>
                                )}
                            </td>
                            <td className="ct-td">
                                <Pill tone={person.role === 'admin' ? 'indigo' : 'slate'}>{person.role}</Pill>
                            </td>
                            <td className="ct-td whitespace-nowrap">{dateTime(person.created_at)}</td>
                            <td className="ct-td text-right">
                                <div className="flex justify-end gap-2">
                                    <Button size="sm" variant="outline" onClick={() => setEditing(person)}>
                                        Edit
                                    </Button>
                                    <ConfirmButton
                                        size="sm"
                                        variant="ghost"
                                        title={`Sign ${person.email} out everywhere?`}
                                        message="Every device holding a token for this account is signed out immediately. They can sign back in with the same password."
                                        confirmLabel="Sign out"
                                        onConfirm={() => act(() => api.revokeStaff(person.id))}
                                    >
                                        Revoke
                                    </ConfirmButton>
                                    <ConfirmButton
                                        size="sm"
                                        variant="ghost"
                                        title={`Delete ${person.email}?`}
                                        message="The account is removed. The last admin in a cafe cannot be deleted."
                                        confirmLabel="Delete"
                                        onConfirm={() => act(() => api.deleteStaff(person.id))}
                                    >
                                        Delete
                                    </ConfirmButton>
                                </div>
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <StaffModal
                open={creating || Boolean(editing)}
                person={editing}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                onSaved={reload}
            />
        </>
    );
}

function StaffModal({ open, person, onClose, onSaved }) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [role, setRole] = useState('staff');
    const [seeded, setSeeded] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    if (person && seeded !== person) {
        setSeeded(person);
        setEmail(person.email);
        setRole(person.role);
        setPassword('');
        setError(null);
    }

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            if (person) {
                // Only send what actually changed: any of these bumps the
                // token version and signs the user out everywhere.
                const body = {};
                if (email !== person.email) body.email = email;
                if (role !== person.role) body.role = role;
                if (password) body.password = password;

                await api.updateStaff(person.id, body);
            } else {
                await api.createStaff({ email, password, role });
            }

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
        setEmail('');
        setPassword('');
        setRole('staff');
        setError(null);
        onClose();
    }

    return (
        <Modal open={open} title={person ? `Edit ${person.email}` : 'New account'} onClose={close}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Email" hint="Unique across the whole platform.">
                    <Input required type="email" maxLength={150} value={email} onChange={(e) => setEmail(e.target.value)} />
                </Field>

                <Field
                    label={person ? 'New password' : 'Password'}
                    hint={person ? 'Leave blank to keep the current one.' : 'At least 6 characters.'}
                >
                    <Input
                        required={!person}
                        type="password"
                        minLength={6}
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        autoComplete="new-password"
                    />
                </Field>

                <Field label="Role">
                    <Select value={role} onChange={(e) => setRole(e.target.value)}>
                        <option value="staff">Staff — runs the floor</option>
                        <option value="admin">Admin — everything in this cafe</option>
                    </Select>
                </Field>

                {person && (
                    <p className="text-xs text-slate-500">
                        Changing the email, role or password signs this account out of every device it is open on.
                    </p>
                )}

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
