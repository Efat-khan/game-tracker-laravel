import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAsync } from '../lib/hooks';
import { money } from '../lib/format';
import { Button, Card, ErrorNote, Field, Input, Loading, PageHeader } from '../components/ui';

const BLOCK_PRESETS = [1, 5, 10, 15, 30, 60];
const STEP_PRESETS = [1, 5, 10, 50];

/**
 * The worked examples mirror the server's rules exactly (§5.1). They are shown
 * because "round to the nearest 5" is ambiguous until you see what it does to a
 * real number — and getting it wrong quietly mis-bills every customer.
 */
function billedMinutes(actual, block) {
    const size = Math.max(1, Number(block) || 1);
    return Math.max(1, Math.ceil(actual / size)) * size;
}

function roundedTotal(gross, step) {
    const size = Math.max(1, Number(step) || 1);
    // Half up, matching the server.
    const total = Math.floor(gross / size + 0.5) * size;
    return gross > 0 && total <= 0 ? size : total;
}

export default function Settings() {
    const { data, error, loading, reload } = useAsync(() => api.settings(), []);
    const [form, setForm] = useState(null);
    const [saved, setSaved] = useState(false);
    const [saveError, setSaveError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (data) setForm(data);
    }, [data]);

    if ((loading && !data) || !form) return <Loading />;

    const set = (key, value) => {
        setForm((f) => ({ ...f, [key]: value }));
        setSaved(false);
    };

    async function save() {
        setBusy(true);
        setSaveError(null);

        try {
            const next = await api.updateSettings({
                billing_round_minutes: Number(form.billing_round_minutes),
                round_amount_to: Number(form.round_amount_to),
                open_hour: Number(form.open_hour),
                close_hour: Number(form.close_hour),
            });
            setForm(next);
            setSaved(true);
            reload();
        } catch (err) {
            setSaveError(err);
        } finally {
            setBusy(false);
        }
    }

    const hoursInvalid = Number(form.close_hour) <= Number(form.open_hour);

    return (
        <>
            <PageHeader title="Settings" subtitle="Billing rules for this cafe. Other cafes keep their own.">
                <Button onClick={save} busy={busy}>
                    Save
                </Button>
            </PageHeader>

            <ErrorNote error={error || saveError} onRetry={reload} />

            {saved && (
                <p className="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                    Saved. New sessions bill by these rules; sessions already running keep the rate they started on.
                </p>
            )}

            <div className="grid gap-4 xl:grid-cols-2">
                <Card className="p-5">
                    <h2 className="text-sm font-semibold">Time rounds up to a block</h2>
                    <p className="mt-1 text-xs text-slate-500">
                        Play time is rounded up to a whole block, and every session bills for at least one.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {BLOCK_PRESETS.map((n) => (
                            <button
                                key={n}
                                onClick={() => set('billing_round_minutes', n)}
                                className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                                    Number(form.billing_round_minutes) === n
                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                        : 'border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'
                                }`}
                            >
                                {n} min
                            </button>
                        ))}
                    </div>

                    <div className="mt-3 max-w-40">
                        <Field label="Or set your own" hint="1–240 minutes.">
                            <Input
                                type="number"
                                min={1}
                                max={240}
                                value={form.billing_round_minutes}
                                onChange={(e) => set('billing_round_minutes', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Example>
                        A 22-minute session bills as{' '}
                        <strong>{billedMinutes(22, form.billing_round_minutes)} minutes</strong>, and a 3-minute one as{' '}
                        <strong>{billedMinutes(3, form.billing_round_minutes)}</strong>.
                        {Number(form.billing_round_minutes) === 1 && ' That is per-minute billing.'}
                    </Example>
                </Card>

                <Card className="p-5">
                    <h2 className="text-sm font-semibold">Amounts round to a step</h2>
                    <p className="mt-1 text-xs text-slate-500">
                        The final charge is rounded to the nearest step. A bill that is not zero never rounds down to
                        nothing.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {STEP_PRESETS.map((n) => (
                            <button
                                key={n}
                                onClick={() => set('round_amount_to', n)}
                                className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                                    Number(form.round_amount_to) === n
                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                        : 'border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'
                                }`}
                            >
                                ৳{n}
                            </button>
                        ))}
                    </div>

                    <div className="mt-3 max-w-40">
                        <Field label="Or set your own" hint="1–1000.">
                            <Input
                                type="number"
                                min={1}
                                max={1000}
                                value={form.round_amount_to}
                                onChange={(e) => set('round_amount_to', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Example>
                        A {money(202)} total becomes <strong>{money(roundedTotal(202, form.round_amount_to))}</strong>,
                        and {money(102.5)} becomes <strong>{money(roundedTotal(102.5, form.round_amount_to))}</strong>.
                    </Example>
                </Card>

                <Card className="p-5 xl:col-span-2">
                    <h2 className="text-sm font-semibold">Trading hours</h2>
                    <p className="mt-1 text-xs text-slate-500">
                        Used as the denominator for station utilization — capacity is the hours you are actually open,
                        not 24.
                    </p>

                    <div className="mt-4 grid max-w-md grid-cols-2 gap-3">
                        <Field label="Opens at" hint="0–23">
                            <Input
                                type="number"
                                min={0}
                                max={23}
                                value={form.open_hour}
                                onChange={(e) => set('open_hour', e.target.value)}
                            />
                        </Field>
                        <Field label="Closes at" hint="1–24">
                            <Input
                                type="number"
                                min={1}
                                max={24}
                                value={form.close_hour}
                                onChange={(e) => set('close_hour', e.target.value)}
                            />
                        </Field>
                    </div>

                    {hoursInvalid ? (
                        <Example tone="warn">
                            Closing must be later than opening. Until it is, utilization falls back to 10:00–23:00.
                        </Example>
                    ) : (
                        <Example>
                            Each station can be busy for{' '}
                            <strong>{Number(form.close_hour) - Number(form.open_hour)} hours</strong> a day, so that is
                            what 100% utilization means.
                        </Example>
                    )}
                </Card>
            </div>
        </>
    );
}

function Example({ children, tone = 'info' }) {
    const tones = {
        info: 'bg-slate-50 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300',
        warn: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
    };

    return <p className={`mt-4 rounded-lg px-3 py-2.5 text-sm ${tones[tone]}`}>{children}</p>;
}
