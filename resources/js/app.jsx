import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { AuthProvider, useAuth } from './lib/auth';
import { BrandingProvider } from './lib/branding';
import { RouterProvider, matchPath, useRouter } from './lib/router';
import { Shell } from './components/Shell';
import { Tour, hasSeenTour } from './components/Tour';

import Login from './screens/Login';
import Checkin from './screens/Checkin';
import Dashboard from './screens/Dashboard';
import Stations from './screens/Stations';
import Sessions from './screens/Sessions';
import Invoices from './screens/Invoices';
import Products from './screens/Products';
import Bookings from './screens/Bookings';
import Customers from './screens/Customers';
import Shifts from './screens/Shifts';
import Loyalty from './screens/Loyalty';
import Analytics from './screens/Analytics';
import Logs from './screens/Logs';
import Staff from './screens/Staff';
import Settings from './screens/Settings';
import Cafes from './screens/Cafes';

const ROUTES = [
    ['/', Dashboard],
    ['/stations', Stations],
    ['/sessions', Sessions],
    ['/invoices', Invoices],
    ['/products', Products],
    ['/bookings', Bookings],
    ['/customers', Customers],
    ['/shifts', Shifts],
    ['/loyalty', Loyalty],
    ['/analytics', Analytics],
    ['/logs', Logs],
    ['/staff', Staff],
    ['/settings', Settings],
    ['/cafes', Cafes],
];

function App() {
    const { session } = useAuth();
    const { path } = useRouter();
    const [tourOpen, setTourOpen] = useState(false);

    // The public check-in page is the whole point of the QR sticker: it opens
    // on a player's own phone, and they have no account.
    const checkin = matchPath('/checkin/:id', path);
    const signedIn = Boolean(session) && !checkin;

    // Auto-start once per browser, after the sidebar has rendered so the tour
    // can find its targets. Declared before any early return — hooks must run
    // in the same order on every render.
    useEffect(() => {
        if (!signedIn || hasSeenTour()) return;
        const timer = setTimeout(() => setTourOpen(true), 600);
        return () => clearTimeout(timer);
    }, [signedIn]);

    if (checkin) return <Checkin stationId={checkin.id} />;

    if (!session) return <Login />;

    const Screen = ROUTES.find(([route]) => route === path)?.[1] ?? NotFound;

    return (
        <>
            <Shell onStartTour={() => setTourOpen(true)}>
                <Screen />
            </Shell>
            <Tour open={tourOpen} onClose={() => setTourOpen(false)} />
        </>
    );
}

function NotFound() {
    const { navigate } = useRouter();

    return (
        <div className="py-24 text-center">
            <p className="text-sm text-slate-500">That screen does not exist.</p>
            <button
                onClick={() => navigate('/')}
                className="mt-3 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
            >
                Back to the dashboard
            </button>
        </div>
    );
}

createRoot(document.getElementById('cafetrack')).render(
    <StrictMode>
        <RouterProvider>
            <BrandingProvider>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </BrandingProvider>
        </RouterProvider>
    </StrictMode>,
);
