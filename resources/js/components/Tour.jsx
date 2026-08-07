import { useEffect, useLayoutEffect, useState } from 'react';
import { NAV } from './Shell';
import { Button } from './ui';

const SEEN_KEY = 'cafetrack.tour.seen';

export function hasSeenTour() {
    try {
        return localStorage.getItem(SEEN_KEY) === '1';
    } catch {
        return true; // private browsing: don't nag on every load
    }
}

function markSeen() {
    try {
        localStorage.setItem(SEEN_KEY, '1');
    } catch {
        /* private browsing */
    }
}

/**
 * The guided tour: dims the page and spotlights each sidebar item in turn.
 *
 * Steps whose target is not on the page are dropped silently, which is how a
 * staff account never sees an explanation of an admin-only screen it cannot
 * open — the nav simply doesn't render those items, so their steps vanish.
 */
export function Tour({ open, onClose }) {
    const [steps, setSteps] = useState([]);
    const [index, setIndex] = useState(0);
    const [box, setBox] = useState(null);

    useEffect(() => {
        if (!open) return;

        const present = NAV.filter((item) => document.querySelector(`[data-tour="${item.to}"]`));

        setSteps(present);
        setIndex(0);
    }, [open]);

    const step = steps[index];

    // Measure after paint so the spotlight lands on the element's real position.
    useLayoutEffect(() => {
        if (!open || !step) {
            setBox(null);
            return;
        }

        const target = document.querySelector(`[data-tour="${step.to}"]`);
        if (!target) return;

        target.classList.add('ct-spotlight');
        target.scrollIntoView({ block: 'nearest' });
        setBox(target.getBoundingClientRect());

        const remeasure = () => setBox(target.getBoundingClientRect());
        window.addEventListener('resize', remeasure);
        window.addEventListener('scroll', remeasure, true);

        return () => {
            target.classList.remove('ct-spotlight');
            window.removeEventListener('resize', remeasure);
            window.removeEventListener('scroll', remeasure, true);
        };
    }, [open, step]);

    useEffect(() => {
        if (!open) return;

        const onKey = (event) => {
            if (event.key === 'Escape') finish();
            if (event.key === 'ArrowRight') next();
            if (event.key === 'ArrowLeft') back();
        };

        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    });

    function finish() {
        markSeen();
        onClose();
    }

    function next() {
        // Decided out here rather than inside the updater: a state updater must
        // stay pure, and StrictMode runs it twice.
        if (index + 1 < steps.length) {
            setIndex(index + 1);
        } else {
            finish();
        }
    }

    function back() {
        setIndex((i) => Math.max(0, i - 1));
    }

    if (!open || !step) return null;

    // Sit the card beside the highlighted nav item on desktop, and centred
    // near the bottom on narrow screens where the sidebar is a drawer.
    const narrow = window.innerWidth < 1024;
    const style = narrow
        ? { left: '50%', transform: 'translateX(-50%)', bottom: 24, maxWidth: 'min(92vw, 26rem)' }
        : {
              left: Math.min((box?.right ?? 0) + 20, window.innerWidth - 380),
              top: Math.min(Math.max(12, (box?.top ?? 0) - 8), window.innerHeight - 240),
              width: 340,
          };

    // The veil is four panels leaving a hole over the highlighted item. A
    // single overlay with a cut-out would need the item to escape its stacking
    // context, and the scrolling nav around it clips anything that tries.
    const hole = box ?? { top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 };
    const pad = 4;
    const veil = 'fixed bg-slate-900/60';

    return (
        <>
            <div aria-hidden="true">
                <div className={`${veil} left-0 right-0 top-0 z-40`} style={{ height: Math.max(0, hole.top - pad) }} />
                <div className={`${veil} left-0 right-0 bottom-0 z-40`} style={{ top: hole.bottom + pad }} />
                <div
                    className={`${veil} left-0 z-40`}
                    style={{ top: hole.top - pad, height: hole.height + pad * 2, width: Math.max(0, hole.left - pad) }}
                />
                <div
                    className={`${veil} right-0 z-40`}
                    style={{ top: hole.top - pad, height: hole.height + pad * 2, left: hole.right + pad }}
                />
            </div>

            <div
                role="dialog"
                aria-modal="true"
                aria-label={`Tour: ${step.label}`}
                className="fixed z-[70] rounded-xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                style={style}
            >
                <p className="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    {step.label}
                </p>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{step.tour}</p>

                <div className="mt-4 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-1.5" aria-hidden="true">
                        {steps.map((s, i) => (
                            <span
                                key={s.to}
                                className={`h-1.5 rounded-full transition-all ${
                                    i === index
                                        ? 'w-4 bg-indigo-600 dark:bg-indigo-400'
                                        : 'w-1.5 bg-slate-300 dark:bg-slate-600'
                                }`}
                            />
                        ))}
                    </div>

                    <div className="flex shrink-0 items-center gap-1.5">
                        <Button size="sm" variant="ghost" onClick={finish}>
                            Skip
                        </Button>
                        <Button size="sm" variant="outline" onClick={back} disabled={index === 0}>
                            Back
                        </Button>
                        <Button size="sm" onClick={next}>
                            {index + 1 === steps.length ? 'Done' : 'Next'}
                        </Button>
                    </div>
                </div>

                <p className="mt-2 text-right text-xs text-slate-400">
                    {index + 1} of {steps.length}
                </p>
            </div>
        </>
    );
}
