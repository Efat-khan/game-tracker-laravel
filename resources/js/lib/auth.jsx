import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, getActiveCafe, getToken, setActiveCafe, setToken, setUnauthorizedHandler } from './api';

const AuthContext = createContext(null);
const SESSION_KEY = 'cafetrack.session';

function readSession() {
    try {
        const raw = localStorage.getItem(SESSION_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeSession(session) {
    try {
        session
            ? localStorage.setItem(SESSION_KEY, JSON.stringify(session))
            : localStorage.removeItem(SESSION_KEY);
    } catch {
        /* private browsing */
    }
}

export function AuthProvider({ children }) {
    const [session, setSession] = useState(() => (getToken() ? readSession() : null));
    const [cafe, setCafe] = useState(() => getActiveCafe());

    const signOut = useCallback(() => {
        setToken(null);
        setActiveCafe(null);
        writeSession(null);
        setSession(null);
        setCafe(null);
    }, []);

    // A 401 anywhere in the app lands here: the token has expired, or an admin
    // changed this user's password or role, or signed them out of every device.
    useEffect(() => {
        setUnauthorizedHandler(signOut);
    }, [signOut]);

    const signIn = useCallback(async (email, password) => {
        const result = await api.login(email, password);

        setToken(result.access_token);

        const next = {
            email: result.email,
            role: result.role,
            cafe_id: result.cafe_id,
            cafe_name: result.cafe_name,
        };

        writeSession(next);
        setSession(next);

        // A superadmin belongs to no cafe and must pick one before the
        // cafe-scoped screens will answer anything but a 400.
        setActiveCafe(null);
        setCafe(null);

        return next;
    }, []);

    /** A superadmin stepping into a tenant. Ignored by the server for others. */
    const workInCafe = useCallback((selected) => {
        const next = selected ? { id: selected.id, name: selected.name } : null;
        setActiveCafe(next);
        setCafe(next);
    }, []);

    const value = useMemo(() => {
        const isSuperadmin = session?.role === 'superadmin';

        return {
            session,
            signIn,
            signOut,
            cafe,
            workInCafe,
            isSuperadmin,
            // A superadmin passes every admin check once they have selected a
            // cafe, so the admin-only screens appear for them too.
            isAdmin: session?.role === 'admin' || isSuperadmin,
            // Which cafe name to show under the logo.
            cafeName: isSuperadmin ? (cafe?.name ?? 'No cafe selected') : session?.cafe_name,
            // A superadmin who has not picked a cafe cannot use scoped screens.
            needsCafe: isSuperadmin && !cafe,
        };
    }, [session, cafe, signIn, signOut, workInCafe]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (!context) throw new Error('useAuth must be used inside an AuthProvider');
    return context;
}
