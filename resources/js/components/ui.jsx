import { useEffect, useState } from 'react';

/* ------------------------------------------------------------------ button */

const VARIANTS = {
    // The lit primary is the one call to action on a screen; everything else
    // stays quiet so it keeps its weight.
    primary:
        'bg-indigo-600 text-white hover:bg-indigo-500 disabled:bg-indigo-400 shadow-[0_0_20px_-6px_var(--color-indigo-500)]',
    subtle: 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700',
    outline:
        'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800',
    danger: 'bg-rose-600 text-white hover:bg-rose-500 disabled:bg-rose-400',
    success:
        'bg-emerald-600 text-white hover:bg-emerald-500 disabled:bg-emerald-400 shadow-[0_0_20px_-6px_var(--color-emerald-500)]',
    ghost: 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
};

const SIZES = {
    sm: 'px-2.5 py-1.5 text-xs',
    md: 'px-3.5 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
};

export function Button({ variant = 'primary', size = 'md', className = '', busy, children, ...rest }) {
    return (
        <button
            className={`inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition
                        active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60
                        ${VARIANTS[variant]} ${SIZES[size]} ${className}`}
            disabled={busy || rest.disabled}
            {...rest}
        >
            {busy && <Spinner className="h-3.5 w-3.5" />}
            {children}
        </button>
    );
}

export function Spinner({ className = 'h-5 w-5' }) {
    return (
        <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path
                className="opacity-90"
                fill="currentColor"
                d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"
            />
        </svg>
    );
}

/* --------------------------------------------------------------- structure */

export function Card({ className = '', children }) {
    return <div className={`ct-card ${className}`}>{children}</div>;
}

export function PageHeader({ title, subtitle, children }) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

export function Pill({ tone = 'slate', children, className = '', ...rest }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700 ring-slate-300/60 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
        green: 'bg-emerald-100 text-emerald-800 ring-emerald-400/40 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30',
        amber: 'bg-amber-100 text-amber-800 ring-amber-400/40 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30',
        red: 'bg-rose-100 text-rose-800 ring-rose-400/40 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/30',
        indigo: 'bg-indigo-100 text-indigo-800 ring-indigo-400/40 dark:bg-indigo-500/15 dark:text-indigo-200 dark:ring-indigo-500/30',
        live: 'bg-live-500/15 text-live-600 ring-live-500/40 dark:text-live-400',
    };

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold
                        uppercase tracking-[0.06em] ring-1 ring-inset ${tones[tone]} ${className}`}
            {...rest}
        >
            {children}
        </span>
    );
}

export function Table({ head, children, empty, colSpan = 1 }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-full border-collapse">
                <thead className="border-b border-slate-200 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-950/40">
                    <tr>{head}</tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {children}
                    {empty && (
                        <tr>
                            <td colSpan={colSpan} className="px-4 py-10 text-center text-sm text-slate-500">
                                {empty}
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

/* ------------------------------------------------------------------ fields */

export function Field({ label, hint, error, children }) {
    return (
        <label className="block">
            {label && <span className="ct-label">{label}</span>}
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-rose-600 dark:text-rose-400">{error}</span>}
        </label>
    );
}

export function Input(props) {
    return <input className="ct-input" {...props} />;
}

export function Select({ children, ...props }) {
    return (
        <select className="ct-input" {...props}>
            {children}
        </select>
    );
}

/* ------------------------------------------------------------------- modal */

export function Modal({ open, title, onClose, children, footer, wide }) {
    useEffect(() => {
        if (!open) return;

        const onKey = (event) => event.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', onKey);

        // Stop the page behind from scrolling under the dialog.
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previous;
        };
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/50 p-0 sm:items-center sm:p-4">
            <div
                className="absolute inset-0"
                onClick={onClose}
                aria-hidden="true"
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className={`relative w-full ${wide ? 'sm:max-w-3xl' : 'sm:max-w-md'} rounded-t-2xl bg-white
                            shadow-xl sm:rounded-2xl dark:bg-slate-900`}
            >
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h2 className="text-base font-semibold">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                    >
                        <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.3 6.3a1 1 0 0 1 1.4 0L10 8.6l2.3-2.3a1 1 0 1 1 1.4 1.4L11.4 10l2.3 2.3a1 1 0 0 1-1.4 1.4L10 11.4l-2.3 2.3a1 1 0 0 1-1.4-1.4L8.6 10 6.3 7.7a1 1 0 0 1 0-1.4z" />
                        </svg>
                    </button>
                </div>

                <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>

                {footer && (
                    <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}

/** Confirm before something irreversible. */
export function ConfirmButton({ onConfirm, title, message, confirmLabel = 'Confirm', children, ...rest }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <>
            <Button onClick={() => setOpen(true)} {...rest}>
                {children}
            </Button>
            <Modal
                open={open}
                title={title}
                onClose={() => !busy && setOpen(false)}
                footer={
                    <>
                        <Button variant="outline" onClick={() => setOpen(false)} disabled={busy}>
                            Cancel
                        </Button>
                        <Button
                            variant="danger"
                            busy={busy}
                            onClick={async () => {
                                setBusy(true);
                                try {
                                    await onConfirm();
                                    setOpen(false);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            {confirmLabel}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-slate-600 dark:text-slate-400">{message}</p>
            </Modal>
        </>
    );
}

/* ------------------------------------------------------------------ states */

export function Loading({ label = 'Loading…' }) {
    return (
        <div className="flex items-center justify-center gap-3 py-16 text-slate-500">
            <Spinner />
            <span className="text-sm">{label}</span>
        </div>
    );
}

export function ErrorNote({ error, onRetry }) {
    if (!error) return null;

    return (
        <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
            <div className="flex items-center justify-between gap-3">
                <span>{error.firstError || error.message}</span>
                {onRetry && (
                    <Button size="sm" variant="outline" onClick={onRetry}>
                        Retry
                    </Button>
                )}
            </div>
        </div>
    );
}

export function EmptyNote({ children }) {
    return <p className="py-10 text-center text-sm text-slate-500">{children}</p>;
}

/** A labelled figure — the analytics tiles and the shift drawer both use these. */
export function Stat({ label, value, tone = 'default', hint }) {
    const tones = {
        default: 'text-slate-900 dark:text-slate-50',
        good: 'text-emerald-600 dark:text-emerald-400',
        bad: 'text-rose-600 dark:text-rose-400',
    };

    return (
        <Card className="overflow-hidden p-4">
            {/* A lit top edge, so a row of tiles reads as instrumentation. */}
            <span className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent" />
            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">
                {label}
            </p>
            <p className={`mt-1.5 text-2xl font-bold tabular-nums ${tones[tone]}`}>{value}</p>
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </Card>
    );
}
