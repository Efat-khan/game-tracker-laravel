import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { useBranding } from '../lib/branding';
import { BrandMark } from '../components/Shell';
import { Button } from '../components/ui';

/**
 * Sign-in.
 *
 * The background is whatever the platform owner uploaded, or a built-in aurora
 * when they have not. Either way a scrim sits between it and the form: an
 * uploaded photograph is an unknown quantity, and the one thing that must never
 * fail here is being able to read the labels.
 */
export default function Login() {
    const { signIn } = useAuth();
    const { loginBackgroundUrl } = useBranding();
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
        <div className="relative min-h-screen overflow-hidden bg-slate-950">
            <Backdrop url={loginBackgroundUrl} />

            <div className="relative mx-auto flex min-h-screen w-full max-w-6xl items-center px-5 py-10">
                <div className="grid w-full gap-12 lg:grid-cols-[1fr_25rem] lg:items-center lg:gap-16">
                    <Pitch />

                    <div className="mx-auto w-full max-w-sm lg:mx-0 lg:max-w-none">
                        {/* The brand sits above the form on small screens,
                            where the pitch column is hidden. */}
                        <div className="mb-6 flex items-center gap-3 lg:hidden">
                            <BrandMark size="lg" />
                            <div>
                                <p className="ct-brand text-2xl font-bold tracking-tight">CAFETRACK</p>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">
                                    Run the floor
                                </p>
                            </div>
                        </div>

                        <div className="ct-auth-panel">
                            {/* A hairline of brand colour along the top edge. */}
                            <span
                                className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-400 to-transparent"
                                aria-hidden="true"
                            />

                            <h1 className="text-xl font-semibold tracking-tight text-white">Sign in</h1>
                            <p className="mt-1 text-sm text-slate-400">
                                Use the account your cafe gave you.
                            </p>

                            <form onSubmit={submit} className="mt-6 space-y-4">
                                <label className="block">
                                    <span className="ct-auth-label">Email</span>
                                    <input
                                        className="ct-auth-input"
                                        type="email"
                                        autoComplete="username"
                                        required
                                        autoFocus
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        placeholder="admin@cafetrack.test"
                                    />
                                </label>

                                <label className="block">
                                    <span className="ct-auth-label">Password</span>
                                    <input
                                        className="ct-auth-input"
                                        type="password"
                                        autoComplete="current-password"
                                        required
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        placeholder="••••••••"
                                    />
                                </label>

                                {error && (
                                    <p
                                        role="alert"
                                        className="rounded-xl border border-rose-400/30 bg-rose-500/12 px-3.5 py-2.5 text-sm text-rose-200"
                                    >
                                        {error}
                                    </p>
                                )}

                                <Button type="submit" size="lg" busy={busy} className="w-full">
                                    Sign in
                                </Button>
                            </form>
                        </div>

                        <p className="mt-5 text-center text-[11px] text-slate-400 lg:text-left">
                            Playing here? Scan the QR code on your station — no account needed.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * The owner's photograph, or the built-in aurora.
 *
 * The scrim only goes over the photograph. That image is an unknown — it could
 * be a bright shot of a lit-up arcade — so it gets two gradients between it and
 * the form: a horizontal one anchoring the pitch column and a vertical one
 * darkening top and bottom. The aurora needs none of that; it is ours, we know
 * it is dark, and laying the same scrim over it only flattened it to black.
 */
function Backdrop({ url }) {
    if (!url) {
        return (
            <div
                className="absolute inset-0"
                aria-hidden="true"
                style={{
                    backgroundImage: `
                        radial-gradient(60rem 42rem at 10% -12%, rgb(106 65 245 / 0.55), transparent 60%),
                        radial-gradient(48rem 36rem at 96% 4%, rgb(34 207 230 / 0.32), transparent 60%),
                        radial-gradient(55rem 42rem at 72% 112%, rgb(106 65 245 / 0.30), transparent 60%)
                    `,
                }}
            />
        );
    }

    return (
        <div className="absolute inset-0" aria-hidden="true">
            <div
                className="absolute inset-0 bg-cover bg-center"
                style={{ backgroundImage: `url(${JSON.stringify(url)})` }}
            />
            {/* Heavy on the left where white text sits directly on the image,
                lighter on the right where the panel carries its own contrast —
                so the owner's photograph is still visible rather than a
                uniformly black rectangle. */}
            <div className="absolute inset-0 bg-gradient-to-r from-slate-950/95 via-slate-950/70 to-slate-950/35" />
            <div className="absolute inset-0 bg-gradient-to-b from-slate-950/45 via-transparent to-slate-950/65" />
        </div>
    );
}

/** The left-hand column. Decorative, so it goes first on desktop and away on mobile. */
function Pitch() {
    const lines = [
        'Every device on the floor, live and to the minute.',
        'Bills that round the way you told them to, every time.',
        'Shifts that reconcile to the coin at closing.',
    ];

    return (
        <div className="hidden lg:block">
            <div className="flex items-center gap-3.5">
                <BrandMark size="lg" />
                <div>
                    <p className="ct-brand text-4xl font-bold tracking-tight">CAFETRACK</p>
                    <p className="mt-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">
                        Press start to run the floor
                    </p>
                </div>
            </div>

            <ul className="mt-10 space-y-4">
                {lines.map((line) => (
                    <li key={line} className="flex items-start gap-3 text-sm text-slate-300">
                        <span
                            className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-gradient-to-br from-indigo-400 to-live-500"
                            aria-hidden="true"
                        />
                        {line}
                    </li>
                ))}
            </ul>
        </div>
    );
}
