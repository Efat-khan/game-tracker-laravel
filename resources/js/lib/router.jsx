import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

/**
 * A ~50-line History API router.
 *
 * CafeTrack has a flat nav and one parameterised public route, so a routing
 * library would be all cost and no benefit here — every published version of
 * the obvious one currently carries open advisories, none of which apply to a
 * client-only SPA but all of which would show up in `npm audit`.
 */

const RouterContext = createContext(null);

export function RouterProvider({ children }) {
    const [path, setPath] = useState(() => window.location.pathname);

    useEffect(() => {
        const onPop = () => setPath(window.location.pathname);
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const navigate = useCallback((to, { replace = false } = {}) => {
        if (to === window.location.pathname) return;
        window.history[replace ? 'replaceState' : 'pushState']({}, '', to);
        setPath(to);
        window.scrollTo(0, 0);
    }, []);

    const value = useMemo(() => ({ path, navigate }), [path, navigate]);

    return <RouterContext.Provider value={value}>{children}</RouterContext.Provider>;
}

export function useRouter() {
    const context = useContext(RouterContext);
    if (!context) throw new Error('useRouter must be used inside a RouterProvider');
    return context;
}

/** An <a> that navigates without a full page load. */
export function Link({ to, className, children, onClick, ...rest }) {
    const { navigate } = useRouter();

    return (
        <a
            href={to}
            className={className}
            onClick={(event) => {
                // Let the browser handle new-tab and middle clicks.
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
                event.preventDefault();
                onClick?.(event);
                navigate(to);
            }}
            {...rest}
        >
            {children}
        </a>
    );
}

/** Matches "/checkin/:id" against the current path. */
export function matchPath(pattern, path) {
    const patternParts = pattern.split('/').filter(Boolean);
    const pathParts = path.split('/').filter(Boolean);

    if (patternParts.length !== pathParts.length) return null;

    const params = {};

    for (let i = 0; i < patternParts.length; i++) {
        const p = patternParts[i];
        if (p.startsWith(':')) {
            params[p.slice(1)] = decodeURIComponent(pathParts[i]);
        } else if (p !== pathParts[i]) {
            return null;
        }
    }

    return params;
}
