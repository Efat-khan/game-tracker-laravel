import { useState } from 'react';
import { api, getToken } from '../lib/api';
import { useAsync, useNow, usePolling } from '../lib/hooks';
import { duration, money, parseUtc } from '../lib/format';
import { Button, Card, Field, Input, Loading, Spinner } from '../components/ui';

/**
 * The public check-in page, reached by scanning the sticker on the booth.
 *
 * Phone-shaped, no account, and deliberately NO STOP BUTTON — a player cannot
 * end their own timer. If a session is already running they see the clock and
 * the cost, and a line telling them to see staff.
 */
export default function Checkin({ stationId }) {
    // The signature from the QR code. Staff hitting this URL while signed in
    // are allowed through without one.
    const qrToken = new URLSearchParams(window.location.search).get('t');

    const { data: station, error, loading, reload } = useAsync(
        () => api.publicStation(stationId),
        [stationId],
    );

    // Keep the running cost honest while the player watches it.
    usePolling(reload, 15000);
    const now = useNow(1000);

    if (loading && !station) return <Loading label="Finding your station…" />;

    if (error) {
        return (
            <Frame>
                <Card className="p-6 text-center">
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        {error.status === 404
                            ? 'That station does not exist. Check the sticker and scan again.'
                            : error.message}
                    </p>
                </Card>
            </Frame>
        );
    }

    return (
        <Frame>
            <div className="mb-5 text-center">
                <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-600 text-base font-bold text-white">
                    CT
                </span>
                <h1 className="text-xl font-semibold tracking-tight">{station.name}</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {station.type} · {money(station.hourly_rate)}/hr
                </p>
            </div>

            {station.active_session ? (
                <InPlay session={station.active_session} now={now} />
            ) : station.maintenance ? (
                <Notice tone="amber">
                    This station is out of service right now. Please see a member of staff.
                </Notice>
            ) : !station.is_active ? (
                <Notice tone="slate">This station is not taking players.</Notice>
            ) : (
                <StartForm station={station} qrToken={qrToken} onStarted={reload} />
            )}
        </Frame>
    );
}

function Frame({ children }) {
    return (
        <div className="flex min-h-screen justify-center bg-slate-50 px-4 py-8 dark:bg-slate-950">
            <div className="w-full max-w-sm">{children}</div>
        </div>
    );
}

function Notice({ tone, children }) {
    const tones = {
        amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
        slate: 'border-slate-200 bg-white text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400',
    };

    return <div className={`rounded-xl border px-4 py-5 text-center text-sm ${tones[tone]}`}>{children}</div>;
}

function InPlay({ session, now }) {
    const started = parseUtc(session.start_time);
    const elapsed = started ? Math.max(0, Math.floor((now - started.getTime()) / 60000)) : session.elapsed_minutes;

    return (
        <Card className="p-6 text-center">
            <p className="text-xs font-medium uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                In play
            </p>
            <p className="mt-2 text-lg font-semibold">{session.customer_name}</p>

            <div className="mt-6 grid grid-cols-2 gap-4">
                <div>
                    <p className="text-xs text-slate-500">Time played</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">{duration(elapsed)}</p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Cost so far</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">{money(session.running_cost)}</p>
                </div>
            </div>

            <p className="mt-4 text-xs text-slate-500">
                {session.controllers} controller{session.controllers === 1 ? '' : 's'} ·{' '}
                {money(session.hourly_rate)}/hr
            </p>

            {/* No stop button, by design. */}
            <p className="mt-6 border-t border-slate-200 pt-4 text-sm text-slate-500 dark:border-slate-800">
                Finished playing? Please see a member of staff to end your session and pay.
            </p>
        </Card>
    );
}

function StartForm({ station, qrToken, onStarted }) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [controllers, setControllers] = useState(1);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    // The picker shows the rate changing as controllers are added, so nobody is
    // surprised by the bill.
    const effective =
        Number(station.hourly_rate) + Number(station.extra_controller_rate) * Math.max(0, controllers - 1);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api.checkin(
                station.id,
                { name: name.trim(), phone_or_id: phone.trim(), controllers },
                // Staff signed in on their own device don't need the signature.
                getToken() ? null : qrToken,
            );
            onStarted();
        } catch (err) {
            setError(
                err.status === 403
                    ? 'Please scan the QR code on the station to check in.'
                    : err.status === 429
                      ? 'Too many attempts just now. Wait a moment and try again.'
                      : err.firstError || err.message,
            );
            setBusy(false);
        }
    }

    return (
        <Card className="p-6">
            <form onSubmit={submit} className="space-y-4">
                <Field label="Your name">
                    <Input
                        required
                        autoFocus
                        maxLength={150}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Rafi Ahmed"
                    />
                </Field>

                <Field label="Phone number">
                    <Input
                        required
                        maxLength={100}
                        inputMode="tel"
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        placeholder="01700000000"
                    />
                </Field>

                <Field label="Controllers">
                    <div className="flex flex-wrap gap-2">
                        {Array.from({ length: station.max_controllers }, (_, i) => i + 1).map((n) => (
                            <button
                                key={n}
                                type="button"
                                onClick={() => setControllers(n)}
                                className={`h-12 w-12 rounded-xl border text-base font-medium transition ${
                                    controllers === n
                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                        : 'border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'
                                }`}
                            >
                                {n}
                            </button>
                        ))}
                    </div>
                    <p className="mt-2 text-sm font-medium text-indigo-600 dark:text-indigo-400">
                        {money(effective)} per hour
                    </p>
                </Field>

                {error && (
                    <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        {error}
                    </p>
                )}

                <Button type="submit" size="lg" busy={busy} className="w-full">
                    {busy ? <Spinner className="h-4 w-4" /> : 'Start session'}
                </Button>

                <p className="text-center text-xs text-slate-500">
                    Your timer starts as soon as you tap. Staff will end it and take payment when you finish.
                </p>
            </form>
        </Card>
    );
}
