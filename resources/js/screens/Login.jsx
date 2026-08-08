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
                                <p className="ct-brand ct-onphoto-mark text-2xl font-bold tracking-tight">CAFETRACK</p>
                                <p className="ct-onphoto text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-200">
                                    Run the floor
                                </p>
                            </div>
                        </div>

                        <div className="ct-auth-panel ct-rise" style={{ animationDelay: '160ms' }}>
                            {/* A hairline along the top edge, with a light
                                travelling along it — the one moving thing on
                                the page once the drift is too slow to notice. */}
                            <span
                                className="absolute inset-x-0 top-0 h-px overflow-hidden bg-white/10"
                                aria-hidden="true"
                            >
                                <span className="ct-sweep block h-px w-1/2 bg-gradient-to-r from-transparent via-live-400 to-transparent" />
                            </span>

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

                        <p
                            className="ct-rise ct-onphoto mt-5 text-center text-[11px] text-slate-300 lg:text-left"
                            style={{ animationDelay: '280ms' }}
                        >
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
 * The darkening is deliberately LOCAL rather than a wash across the whole
 * frame. A full-width scrim protects the text but flattens the picture, and
 * whatever the owner uploaded almost certainly has its subject in the middle —
 * exactly the part a left-to-right gradient throws away. So two soft pools of
 * shadow sit under the two things that need one, the pitch column and the
 * panel, and the centre of the image is left alone.
 */
function Backdrop({ url }) {
    if (!url) {
        return (
            <div className="absolute inset-0" aria-hidden="true">
                <div
                    className="ct-drift absolute inset-0"
                    style={{
                        backgroundImage: `
                            radial-gradient(60rem 42rem at 10% -12%, rgb(106 65 245 / 0.55), transparent 60%),
                            radial-gradient(48rem 36rem at 96% 4%, rgb(34 207 230 / 0.32), transparent 60%),
                            radial-gradient(55rem 42rem at 72% 112%, rgb(106 65 245 / 0.30), transparent 60%)
                        `,
                    }}
                />
                <div className="ct-grain absolute inset-0" />
            </div>
        );
    }

    return (
        <div className="absolute inset-0" aria-hidden="true">
            <div
                className="ct-drift absolute inset-0 bg-cover bg-center"
                style={{ backgroundImage: `url(${JSON.stringify(url)})` }}
            />

            {/* The two pools of shadow. Wide and heavily feathered, so they
                read as depth in the picture rather than as panels laid on it. */}
            <div
                className="absolute inset-0"
                style={{
                    backgroundImage: `
                        radial-gradient(42rem 46rem at 16% 50%, rgb(2 6 23 / 0.72), rgb(2 6 23 / 0.26) 46%, transparent 74%),
                        radial-gradient(38rem 44rem at 86% 50%, rgb(2 6 23 / 0.74), rgb(2 6 23 / 0.24) 48%, transparent 76%)
                    `,
                }}
            />

            {/* Top and bottom only — enough to seat the frame without touching
                the middle of the picture. */}
            <div className="absolute inset-0 bg-gradient-to-b from-slate-950/45 via-transparent to-slate-950/60" />

            {/* Brand light leaking in from the corners, so an image we did not
                choose still reads as part of this product. */}
            <div
                className="absolute inset-0"
                style={{
                    backgroundImage: `
                        radial-gradient(40rem 30rem at 102% -5%, rgb(34 207 230 / 0.16), transparent 62%),
                        radial-gradient(44rem 32rem at -6% 105%, rgb(106 65 245 / 0.22), transparent 62%)
                    `,
                }}
            />

            <div className="ct-grain absolute inset-0" />
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
            <div className="ct-rise flex items-center gap-3.5">
                <BrandMark size="lg" />
                <div>
                    <p className="ct-brand ct-onphoto-mark text-4xl font-bold tracking-tight">CAFETRACK</p>
                    <p className="ct-onphoto mt-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-200">
                        Press start to run the floor
                    </p>
                </div>
            </div>

            <ul className="mt-10 space-y-4">
                {lines.map((line, index) => (
                    <li
                        key={line}
                        className="ct-rise ct-onphoto flex items-start gap-3 text-sm text-white"
                        style={{ animationDelay: `${220 + index * 90}ms` }}
                    >
                        <span
                            className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-gradient-to-br from-indigo-400 to-live-500 shadow-[0_0_10px_0_var(--color-live-500)]"
                            aria-hidden="true"
                        />
                        {line}
                    </li>
                ))}
            </ul>
        </div>
    );
}
