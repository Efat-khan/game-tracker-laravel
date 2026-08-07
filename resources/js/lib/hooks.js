import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Load something from the API, with the three states every screen needs.
 *
 * `deps` behaves like useEffect's. `reload()` re-runs without clearing the
 * current data, so a polling screen never flashes a spinner over live numbers.
 */
export function useAsync(loader, deps = []) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    // Guards against a slow response landing after the component unmounted or
    // after a newer request already resolved.
    const generation = useRef(0);

    const run = useCallback(
        async ({ quiet = false } = {}) => {
            const mine = ++generation.current;

            if (!quiet) setLoading(true);

            try {
                const result = await loader();
                if (mine !== generation.current) return;
                setData(result);
                setError(null);
            } catch (err) {
                if (mine !== generation.current) return;
                setError(err);
            } finally {
                if (mine === generation.current) setLoading(false);
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        deps,
    );

    useEffect(() => {
        run();
        return () => {
            generation.current++;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    return { data, error, loading, reload: () => run({ quiet: true }), setData };
}

/**
 * Re-run something on an interval. The dashboard uses 5s.
 *
 * Polling pauses while the tab is hidden — a cafe leaves this open all day and
 * there is no reason to keep hitting the API from a background tab.
 */
export function usePolling(callback, intervalMs) {
    const saved = useRef(callback);

    useEffect(() => {
        saved.current = callback;
    }, [callback]);

    useEffect(() => {
        if (!intervalMs) return;

        let timer = null;

        const start = () => {
            stop();
            timer = setInterval(() => saved.current?.(), intervalMs);
        };

        const stop = () => {
            if (timer) clearInterval(timer);
            timer = null;
        };

        const onVisibility = () => {
            if (document.hidden) {
                stop();
            } else {
                saved.current?.();
                start();
            }
        };

        start();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [intervalMs]);
}

/** A ticking clock, so elapsed timers advance between API refreshes. */
export function useNow(intervalMs = 1000) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), intervalMs);
        return () => clearInterval(timer);
    }, [intervalMs]);

    return now;
}

export function useTheme() {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));

    const toggle = useCallback(() => {
        setDark((current) => {
            const next = !current;
            document.documentElement.classList.toggle('dark', next);
            try {
                localStorage.setItem('cafetrack.theme', next ? 'dark' : 'light');
            } catch {
                /* private browsing */
            }
            return next;
        });
    }, []);

    return { dark, toggle };
}
