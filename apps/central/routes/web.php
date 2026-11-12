<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Customer;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\Webhooks\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
*/
Route::controller(PublicPageController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/pricing', 'pricing')->name('pricing');
    Route::get('/docs', 'docs')->name('docs');
    Route::get('/legal', 'legal')->name('legal');

    /*
    | The bootstrap installer is served from here so the command printed on the
    | marketing pages is a real one: the download origin is this site unless an
    | operator points ATLAS_DOWNLOAD_BASE at a CDN mirror.
    */
    Route::get('/install.sh', 'installer')->name('installer');
});

/*
|--------------------------------------------------------------------------
| Payment provider webhooks (no session, no CSRF, raw body)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.razorpay');

/*
|--------------------------------------------------------------------------
| Customer portal
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'customer'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Licensing
    Route::get('/licenses', [Customer\LicenseController::class, 'index'])->name('customer.licenses.index');
    Route::get('/licenses/{license:public_id}', [Customer\LicenseController::class, 'show'])->name('customer.licenses.show');

    // Servers
    Route::get('/servers', [Customer\ServerController::class, 'index'])->name('customer.servers.index');
    Route::get('/servers/create', [Customer\ServerController::class, 'create'])->name('customer.servers.create');
    Route::post('/servers', [Customer\ServerController::class, 'store'])->name('customer.servers.store');
    Route::delete('/servers/{server:public_id}', [Customer\ServerController::class, 'destroy'])->name('customer.servers.destroy');

    // Billing
    Route::get('/billing/checkout', [Customer\BillingController::class, 'create'])->name('billing.checkout');
    Route::post('/billing/checkout', [Customer\BillingController::class, 'store'])->middleware('throttle:checkout')->name('billing.store');
    Route::get('/billing/pay/{order:public_id}', [Customer\BillingController::class, 'pay'])->name('billing.pay');
    Route::post('/billing/pay/{order:public_id}/verify', [Customer\BillingController::class, 'verify'])->name('billing.verify');
    Route::post('/billing/pay/{order:public_id}/simulate', [Customer\BillingController::class, 'simulate'])->name('billing.simulate');
    Route::post('/billing/renew/{license:public_id}', [Customer\BillingController::class, 'renew'])->name('billing.renew');
    Route::get('/billing/invoices', [Customer\BillingController::class, 'invoices'])->name('billing.invoices');
    Route::get('/billing/invoices/{invoice:public_id}', [Customer\BillingController::class, 'invoice'])->name('billing.invoice');
    Route::get('/billing/subscriptions', [Customer\BillingController::class, 'subscriptions'])->name('billing.subscriptions');

    // Support
    Route::get('/support', [Customer\SupportController::class, 'index'])->name('support.index');
    Route::post('/support', [Customer\SupportController::class, 'store'])->name('support.store');
    Route::get('/support/{ticket:public_id}', [Customer\SupportController::class, 'show'])->name('support.show');
    Route::post('/support/{ticket:public_id}/reply', [Customer\SupportController::class, 'reply'])->name('support.reply');

    // Downloads
    Route::get('/downloads', [Customer\DownloadController::class, 'index'])->name('downloads.index');
    Route::get('/downloads/{artifact}', [Customer\DownloadController::class, 'download'])->name('downloads.get');
});

/*
|--------------------------------------------------------------------------
| Own profile — every signed-in user, staff included
|--------------------------------------------------------------------------
| This sits outside the customer group on purpose: a support engineer must be
| able to change their own password without holding a customer record.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Staff console
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    // Customers & licences
    Route::get('/customers', [Admin\CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [Admin\CustomerController::class, 'show'])->name('customers.show');

    Route::get('/licenses', [Admin\LicenseController::class, 'index'])->name('licenses.index');
    Route::get('/licenses/create', [Admin\LicenseController::class, 'create'])->name('licenses.create');
    Route::post('/licenses', [Admin\LicenseController::class, 'store'])->name('licenses.store');
    Route::get('/licenses/{license:public_id}', [Admin\LicenseController::class, 'show'])->name('licenses.show');
    Route::patch('/licenses/{license:public_id}/status', [Admin\LicenseController::class, 'updateStatus'])->name('licenses.status');

    // Catalogue
    Route::get('/plans', [Admin\PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [Admin\PlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [Admin\PlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [Admin\PlanController::class, 'edit'])->name('plans.edit');
    Route::patch('/plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');

    Route::get('/coupons', [Admin\CouponAdminController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [Admin\CouponAdminController::class, 'store'])->name('coupons.store');
    Route::patch('/coupons/{coupon}/toggle', [Admin\CouponAdminController::class, 'toggle'])->name('coupons.toggle');

    // Billing
    Route::get('/orders', [Admin\BillingAdminController::class, 'orders'])->name('orders.index');
    Route::get('/invoices', [Admin\BillingAdminController::class, 'invoices'])->name('invoices.index');
    Route::get('/invoices/{invoice:public_id}', [Admin\BillingAdminController::class, 'invoice'])->name('invoices.show');
    Route::get('/subscriptions', [Admin\BillingAdminController::class, 'subscriptions'])->name('subscriptions.index');
    Route::post('/payments/{payment}/refund', [Admin\BillingAdminController::class, 'storeRefund'])->name('payments.refund');

    // Infrastructure & operations
    Route::get('/servers', [Admin\ServerController::class, 'index'])->name('servers.index');
    Route::get('/releases', [Admin\ReleaseAdminController::class, 'index'])->name('releases.index');
    Route::post('/releases', [Admin\ReleaseAdminController::class, 'store'])->name('releases.store');
    Route::patch('/releases/{release}/publish', [Admin\ReleaseAdminController::class, 'publish'])->name('releases.publish');

    // Support
    Route::get('/tickets', [Admin\SupportAdminController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket:public_id}', [Admin\SupportAdminController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket:public_id}/reply', [Admin\SupportAdminController::class, 'reply'])->name('tickets.reply');
    Route::patch('/tickets/{ticket:public_id}', [Admin\SupportAdminController::class, 'update'])->name('tickets.update');

    // Governance
    Route::get('/audit', [Admin\AuditController::class, 'index'])->name('audit.index');
    Route::patch('/audit/security/{event}/resolve', [Admin\AuditController::class, 'resolve'])->name('audit.resolve');
    Route::get('/settings', [Admin\SettingsAdminController::class, 'index'])->name('settings.index');
    Route::patch('/settings', [Admin\SettingsAdminController::class, 'update'])->name('settings.update');
});

require __DIR__.'/auth.php';
