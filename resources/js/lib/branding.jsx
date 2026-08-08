import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api } from './api';

/**
 * The platform owner's logo and login background.
 *
 * Fetched once, before sign-in, because the login screen is the first thing
 * that needs them. A failure here is deliberately silent: branding is
 * decoration, and nobody should be locked out of the app because a logo would
 * not load.
 */
const BrandingContext = createContext({ logoUrl: null, loginBackgroundUrl: null, reload: () => {} });

export function BrandingProvider({ children }) {
    const [branding, setBranding] = useState({ logoUrl: null, loginBackgroundUrl: null });

    const reload = useCallback(async () => {
        try {
            const data = await api.branding();
            setBranding({
                logoUrl: data?.logo_url ?? null,
                loginBackgroundUrl: data?.login_background_url ?? null,
            });
        } catch {
            setBranding({ logoUrl: null, loginBackgroundUrl: null });
        }
    }, []);

    useEffect(() => {
        reload();
    }, [reload]);

    return (
        <BrandingContext.Provider value={{ ...branding, reload }}>{children}</BrandingContext.Provider>
    );
}

export function useBranding() {
    return useContext(BrandingContext);
}
