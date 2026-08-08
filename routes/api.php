<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CafeController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\TierController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

/*
|--------------------------------------------------------------------------
| Public — no account, rate limited per client IP (§7.3)
|--------------------------------------------------------------------------
*/

Route::get('/stations/{id}/qrcode', [StationController::class, 'qrcode'])->whereNumber('id');

// The login screen needs these before anyone has signed in, so they are public.
Route::get('/branding', [BrandingController::class, 'show']);
Route::get('/branding/{asset}', [BrandingController::class, 'image'])
    ->whereIn('asset', ['login-background', 'logo']);

Route::get('/stations/{id}/public', [StationController::class, 'publicShow'])
    ->whereNumber('id')
    ->middleware('throttle:public_station');

// optional.auth so signed-in staff bypass the QR signature check (§7.2).
Route::post('/checkin/{station}', [CheckinController::class, 'store'])
    ->whereNumber('station')
    ->middleware(['optional.auth', 'throttle:checkin']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

/*
|--------------------------------------------------------------------------
| Cafes — the account's own cafe, or the whole platform for a superadmin
|--------------------------------------------------------------------------
| Deliberately outside the `cafe` middleware: a superadmin must be able to
| list cafes before they have selected one.
*/

Route::middleware('auth:cafetrack')->group(function () {
    Route::get('/cafes/mine', [CafeController::class, 'mine']);

    Route::middleware('superadmin')->group(function () {
        Route::post('/cafes', [CafeController::class, 'store']);
        Route::patch('/cafes/{id}', [CafeController::class, 'update'])->whereNumber('id');
        // The grant itself. Superadmin only, by design.
        Route::patch('/cafes/{id}/features', [FeatureController::class, 'update'])->whereNumber('id');

        // Branding is platform-wide, so only the platform owner may change it.
        Route::post('/branding/{asset}', [BrandingController::class, 'store'])
            ->whereIn('asset', ['login-background', 'logo']);
        Route::delete('/branding/{asset}', [BrandingController::class, 'destroy'])
            ->whereIn('asset', ['login-background', 'logo']);
    });
});

/*
|--------------------------------------------------------------------------
| Inside a cafe — every route below is scoped by ResolveCafeContext
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:cafetrack', 'cafe'])->group(function () {

    // Which optional modules this cafe may use — the sidebar reads this.
    Route::get('/features', [FeatureController::class, 'index']);

    /* ---- Stations ---------------------------------------------------- */
    Route::get('/stations', [StationController::class, 'index']);
    Route::post('/stations/{id}/maintenance', [StationController::class, 'maintenance'])->whereNumber('id');

    /* ---- Sessions ---------------------------------------------------- */
    Route::get('/sessions/active', [SessionController::class, 'active']);
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions/{id}/cancel', [SessionController::class, 'cancel'])->whereNumber('id');
    Route::post('/checkout/{session}', [SessionController::class, 'checkout'])->whereNumber('session');

    /* ---- Invoices ---------------------------------------------------- */
    Route::get('/invoices/export.csv', [InvoiceController::class, 'exportCsv']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('id');
    Route::patch('/invoices/{id}', [InvoiceController::class, 'update'])->whereNumber('id');
    Route::post('/invoices/{id}/items', [InvoiceController::class, 'addItem'])->whereNumber('id');
    Route::delete('/invoices/{id}/items/{itemId}', [InvoiceController::class, 'removeItem'])
        ->whereNumber(['id', 'itemId']);
    Route::post('/invoices/{id}/pay-wallet', [InvoiceController::class, 'payWallet'])->whereNumber('id');

    /* ---- Catalogue, customers, wallet -------------------------------- */
    Route::get('/products', [ProductController::class, 'index'])->middleware('feature:products');
    Route::get('/packages', [PackageController::class, 'index'])->middleware('feature:loyalty');
    Route::get('/tiers', [TierController::class, 'index'])->middleware('feature:loyalty');

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->whereNumber('id');
    Route::get('/customers/{id}/wallet', [CustomerController::class, 'wallet'])->whereNumber('id');
    Route::post('/customers/{id}/topup', [CustomerController::class, 'topup'])->whereNumber('id');

    /* ---- Bookings (optional module) ----------------------------------- */
    Route::middleware('feature:bookings')->group(function () {
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::patch('/bookings/{id}', [BookingController::class, 'update'])->whereNumber('id');
        Route::post('/bookings/{id}/start', [BookingController::class, 'start'])->whereNumber('id');
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->whereNumber('id');
    });

    /* ---- Shifts ------------------------------------------------------ */
    Route::get('/shifts/current', [ShiftController::class, 'current']);
    Route::get('/shifts', [ShiftController::class, 'index']);
    Route::get('/shifts/{id}', [ShiftController::class, 'show'])->whereNumber('id');
    Route::post('/shifts/open', [ShiftController::class, 'open']);
    Route::post('/shifts/{id}/close', [ShiftController::class, 'close'])->whereNumber('id');
    Route::post('/shifts/{id}/cash', [ShiftController::class, 'cash'])->whereNumber('id');

    /* ---- Expenses ---------------------------------------------------- */
    // Staff record them — they are the ones sent out for change and batteries.
    // Deleting one is admin, below, because it moves the drawer.
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);

    /* ---- Analytics --------------------------------------------------- */
    Route::get('/analytics/daily-income', [AnalyticsController::class, 'dailyIncome']);
    Route::get('/analytics/top-stations', [AnalyticsController::class, 'topStations']);
    Route::get('/analytics/top-customers', [AnalyticsController::class, 'topCustomers']);
    Route::get('/analytics/peak-hours', [AnalyticsController::class, 'peakHours']);
    Route::get('/analytics/utilization', [AnalyticsController::class, 'utilization']);
    Route::get('/analytics/profit', [AnalyticsController::class, 'profit']);

    /* ---- Settings (readable by staff, writable by admins) ------------ */
    Route::get('/settings', [SettingsController::class, 'show']);

    /*
    |----------------------------------------------------------------------
    | Admin only (§3). A superadmin passes these once they have picked a cafe.
    |----------------------------------------------------------------------
    */
    Route::middleware('admin')->group(function () {
        Route::post('/stations', [StationController::class, 'store']);
        Route::patch('/stations/{id}', [StationController::class, 'update'])->whereNumber('id');
        Route::delete('/stations/{id}', [StationController::class, 'destroy'])->whereNumber('id');

        Route::post('/invoices/{id}/discount', [InvoiceController::class, 'discount'])->whereNumber('id');
        Route::post('/invoices/{id}/void', [InvoiceController::class, 'void'])->whereNumber('id');

        Route::middleware('feature:products')->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::patch('/products/{id}', [ProductController::class, 'update'])->whereNumber('id');
            Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
        });

        Route::middleware('feature:loyalty')->group(function () {
            Route::post('/packages', [PackageController::class, 'store']);
            Route::patch('/packages/{id}', [PackageController::class, 'update'])->whereNumber('id');
            Route::delete('/packages/{id}', [PackageController::class, 'destroy'])->whereNumber('id');

            Route::post('/tiers', [TierController::class, 'store']);
            Route::patch('/tiers/{id}', [TierController::class, 'update'])->whereNumber('id');
            Route::delete('/tiers/{id}', [TierController::class, 'destroy'])->whereNumber('id');
        });

        Route::post('/customers/{id}/adjust', [CustomerController::class, 'adjust'])->whereNumber('id');

        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->whereNumber('id');

        Route::get('/analytics/staff', [AnalyticsController::class, 'staff']);

        // The two summary sheets. Admin only: they carry what went out of the
        // drawer and what is left, which is the owner's business.
        Route::get('/analytics/daily-summary', [AnalyticsController::class, 'dailySummary']);
        Route::get('/analytics/monthly-summary', [AnalyticsController::class, 'monthlySummary']);

        Route::get('/audit', [AuditController::class, 'index']);
        Route::patch('/settings', [SettingsController::class, 'update']);

        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::patch('/staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
        Route::post('/staff/{id}/revoke', [StaffController::class, 'revoke'])->whereNumber('id');
        Route::delete('/staff/{id}', [StaffController::class, 'destroy'])->whereNumber('id');
    });
});
