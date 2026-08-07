import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { BrandMark } from '../components/Shell';
import { Button, Card, Field, Input } from '../components/ui';

export default function Login() {
    const { signIn } = useAuth();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await signIn(email.trim(), password);
        } catch (err) {
            // 429 means the login limiter tripped; say so rather than implying
            // the password was wrong.
            setError(
                err.status === 429
                    ? 'Too many attempts. Wait a minute and try again.'
                    : err.firstError || err.message,
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center px-4">
            <div className="w-full max-w-sm">
                <div className="mb-7 text-center">
                    <div className="mx-auto mb-4 w-fit">
                        <BrandMark size="lg" />
                    </div>
                    <h1 className="ct-brand text-3xl font-bold tracking-tight">CAFETRACK</h1>
                    <p className="mt-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                        Press start to run the floor
                    </p>
                </div>

                <Card className="p-6">
                    <form onSubmit={submit} className="space-y-4">
                        <Field label="Email">
                            <Input
                                type="email"
                                autoComplete="username"
                                required
                                autoFocus
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="admin@cafetrack.test"
                            />
                        </Field>

                        <Field label="Password">
                            <Input
                                type="password"
                                autoComplete="current-password"
                                required
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                        </Field>

                        {error && (
                            <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                                {error}
                            </p>
                        )}

                        <Button type="submit" size="lg" busy={busy} className="w-full">
                            Sign in
                        </Button>
                    </form>
                </Card>
            </div>
        </div>
    );
}
