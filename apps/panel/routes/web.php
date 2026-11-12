<?php

use App\Domain\Agent\Agent;
use App\Domain\Licensing\LicenseManager;
use App\Http\Controllers\Panel\ApiTokenController;
use App\Http\Controllers\Panel\ApplicationController;
use App\Http\Controllers\Panel\BackupController;
use App\Http\Controllers\Panel\CronController;
use App\Http\Controllers\Panel\DashboardController as PanelDashboard;
use App\Http\Controllers\Panel\DatabaseController;
use App\Http\Controllers\Panel\DatabaseUserController;
use App\Http\Controllers\Panel\DeveloperController;
use App\Http\Controllers\Panel\DnsController;
use App\Http\Controllers\Panel\DomainController;
use App\Http\Controllers\Panel\EmailFilterController;
use App\Http\Controllers\Panel\EmailPolicyController;
use App\Http\Controllers\Panel\FileController;
use App\Http\Controllers\Panel\FtpController;
use App\Http\Controllers\Panel\LogController;
use App\Http\Controllers\Panel\MailboxController;
use App\Http\Controllers\Panel\ObservabilityController;
use App\Http\Controllers\Panel\ProfileController;
use App\Http\Controllers\Panel\RedirectController;
use App\Http\Controllers\Panel\SecurityController;
use App\Http\Controllers\Panel\SshController;
use App\Http\Controllers\Panel\SslController;
use App\Http\Controllers\Panel\StatsController;
use App\Http\Controllers\Panel\TwoFactorController;
use App\Http\Controllers\Panel\WebConfigController;
use App\Http\Controllers\Panel\WebsiteController;
use App\Http\Controllers\Reseller\AccountController as ResellerAccountController;
use App\Http\Controllers\Reseller\DashboardController as ResellerDashboard;
use App\Http\Controllers\Webmail\InboxController;
use App\Http\Controllers\Whm\AccountController as WhmAccountController;
use App\Http\Controllers\Whm\AuditController;
use App\Http\Controllers\Whm\DashboardController as WhmDashboard;
use App\Http\Controllers\Whm\LicenseController;
use App\Http\Controllers\Whm\PackageController;
use App\Http\Controllers\Whm\ServiceController;
use App\Http\Controllers\Whm\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(auth()->user()->homeRoute());
});

/*
| Health endpoint used by the installer and the load balancer. It reports the
| two things that decide whether the panel can work at all: the agent socket
| and the licence state.
*/
Route::get('/health', function (LicenseManager $licences) {
    $agent = app(Agent::class);

    return response()->json([
        'ok' => $agent->healthy(),
        'agent' => $agent->healthy() ? 'up' : 'down',
        'licence' => $licences->evaluate()['state'],
        'version' => config('atlas.version', '0.9.0'),
    ], $agent->healthy() ? 200 : 503);
})->name('health');

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
});

// ---- WHM: server administration -----------------------------------------
Route::middleware(['auth', 'role:admin'])
    ->prefix('whm')->name('whm.')->group(function () {
        Route::get('/', [WhmDashboard::class, 'index'])->name('dashboard');

        Route::get('accounts', [WhmAccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [WhmAccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [WhmAccountController::class, 'store'])->middleware('licence.writes')->name('accounts.store');
        Route::get('accounts/{account}', [WhmAccountController::class, 'show'])->name('accounts.show');
        Route::post('accounts/{account}/suspend', [WhmAccountController::class, 'suspend'])->middleware('licence.writes')->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [WhmAccountController::class, 'unsuspend'])->middleware('licence.writes')->name('accounts.unsuspend');
        Route::patch('accounts/{account}/quota', [WhmAccountController::class, 'quota'])->middleware('licence.writes')->name('accounts.quota');
        Route::delete('accounts/{account}', [WhmAccountController::class, 'destroy'])->middleware('licence.writes')->name('accounts.destroy');

        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::post('packages', [PackageController::class, 'store'])->middleware('licence.writes')->name('packages.store');
        Route::patch('packages/{package}', [PackageController::class, 'update'])->middleware('licence.writes')->name('packages.update');
        Route::delete('packages/{package}', [PackageController::class, 'destroy'])->middleware('licence.writes')->name('packages.destroy');

        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('services/{unit}/restart', [ServiceController::class, 'restart'])->middleware('licence.writes')->name('services.restart');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        Route::get('licence', [LicenseController::class, 'index'])->name('licence.index');
        Route::post('licence', [LicenseController::class, 'store'])->name('licence.store');
        // Deliberately *not* behind licence.writes: an unlicensed or read-only
        // panel must still be able to install a working licence, otherwise
        // there is no way out of the state.
        Route::post('licence/activate', [LicenseController::class, 'activate'])->name('licence.activate');
        Route::post('licence/refresh', [LicenseController::class, 'refresh'])->name('licence.refresh');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::patch('settings', [SettingsController::class, 'update'])->middleware('licence.writes')->name('settings.update');
    });

// ---- Reseller ------------------------------------------------------------
Route::middleware(['auth', 'role:reseller'])
    ->prefix('reseller')->name('reseller.')->group(function () {
        Route::get('/', [ResellerDashboard::class, 'index'])->name('dashboard');

        Route::get('accounts', [ResellerAccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [ResellerAccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [ResellerAccountController::class, 'store'])->middleware('licence.writes')->name('accounts.store');
        Route::get('accounts/{account}', [ResellerAccountController::class, 'show'])->name('accounts.show');
        Route::post('accounts/{account}/suspend', [ResellerAccountController::class, 'suspend'])->middleware('licence.writes')->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [ResellerAccountController::class, 'unsuspend'])->middleware('licence.writes')->name('accounts.unsuspend');
        Route::delete('accounts/{account}', [ResellerAccountController::class, 'destroy'])->middleware('licence.writes')->name('accounts.destroy');
    });

// ---- cPanel-style hosting panel -----------------------------------------
Route::middleware(['auth', 'role:customer'])
    ->prefix('panel')->name('panel.')->group(function () {
        Route::get('/', [PanelDashboard::class, 'index'])->name('dashboard');

        Route::get('websites', [WebsiteController::class, 'index'])->name('websites.index');
        Route::post('websites', [WebsiteController::class, 'store'])->middleware('licence.writes')->name('websites.store');
        Route::delete('websites/{domain}', [WebsiteController::class, 'destroy'])->middleware('licence.writes')->name('websites.destroy');
        Route::post('websites/{domain}/certificate', [WebsiteController::class, 'certificate'])->middleware('licence.writes')->name('websites.certificate');

        Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::post('databases', [DatabaseController::class, 'store'])->middleware('licence.writes')->name('databases.store');
        Route::post('databases/{database}/password', [DatabaseController::class, 'password'])->middleware('licence.writes')->name('databases.password');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->middleware('licence.writes')->name('databases.destroy');

        Route::get('email', [MailboxController::class, 'index'])->middleware('mail.on')->name('email.index');
        Route::post('email', [MailboxController::class, 'store'])->middleware('licence.writes')->name('email.store');
        Route::post('email/{mailbox}/password', [MailboxController::class, 'password'])->middleware('licence.writes')->name('email.password');
        Route::delete('email/{mailbox}', [MailboxController::class, 'destroy'])->middleware('licence.writes')->name('email.destroy');

        Route::get('dns', [DnsController::class, 'index'])->name('dns.index');
        Route::post('dns/records', [DnsController::class, 'store'])->middleware('licence.writes')->name('dns.store');
        Route::delete('dns/records/{record}', [DnsController::class, 'destroy'])->middleware('licence.writes')->name('dns.destroy');

        Route::get('cron', [CronController::class, 'index'])->name('cron.index');
        Route::post('cron', [CronController::class, 'store'])->middleware('licence.writes')->name('cron.store');
        Route::delete('cron/{job}', [CronController::class, 'destroy'])->middleware('licence.writes')->name('cron.destroy');

        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->middleware('licence.writes')->name('backups.store');
        Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('licence.writes')->name('backups.restore');

        // ---- file manager ---------------------------------------------
        Route::get('files', [FileController::class, 'index'])->name('files.index');
        Route::get('files/edit', [FileController::class, 'edit'])->name('files.edit');
        Route::get('files/view', [FileController::class, 'view'])->name('files.view');
        Route::get('files/download', [FileController::class, 'download'])->name('files.download');
        Route::get('files/search', [FileController::class, 'search'])->name('files.search');
        Route::put('files', [FileController::class, 'update'])->middleware('licence.writes')->name('files.update');
        Route::post('files/upload', [FileController::class, 'upload'])->middleware('licence.writes')->name('files.upload');
        Route::post('files/mkdir', [FileController::class, 'mkdir'])->middleware('licence.writes')->name('files.mkdir');
        Route::post('files/rename', [FileController::class, 'rename'])->middleware('licence.writes')->name('files.rename');
        Route::post('files/copy', [FileController::class, 'copy'])->middleware('licence.writes')->name('files.copy');
        Route::post('files/move', [FileController::class, 'move'])->middleware('licence.writes')->name('files.move');
        Route::post('files/chmod', [FileController::class, 'chmod'])->middleware('licence.writes')->name('files.chmod');
        Route::post('files/compress', [FileController::class, 'compress'])->middleware('licence.writes')->name('files.compress');
        Route::post('files/extract', [FileController::class, 'extract'])->middleware('licence.writes')->name('files.extract');
        Route::post('files/restore', [FileController::class, 'restore'])->middleware('licence.writes')->name('files.restore');
        Route::post('files/trash/empty', [FileController::class, 'emptyTrash'])->middleware('licence.writes')->name('files.trash.empty');
        Route::delete('files', [FileController::class, 'destroy'])->middleware('licence.writes')->name('files.destroy');

        // ---- ssh ------------------------------------------------------
        // ---- two-factor --------------------------------------------------
        // Enrollment only. The login challenge lives in routes/auth.php, because
        // it runs before a session exists.
        Route::get('security/two-factor', [TwoFactorController::class, 'index'])->name('security.twofactor');
        Route::post('security/two-factor', [TwoFactorController::class, 'start'])->name('security.twofactor.start');
        Route::post('security/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('security.twofactor.confirm');
        Route::delete('security/two-factor', [TwoFactorController::class, 'destroy'])->name('security.twofactor.destroy');

        // ---- logs --------------------------------------------------------
        Route::get('logs', [LogController::class, 'index'])->name('logs.index');

        Route::get('ssh', [SshController::class, 'index'])->name('ssh.index');
        Route::post('ssh/keys', [SshController::class, 'store'])->middleware('licence.writes')->name('ssh.keys.store');
        Route::post('ssh/keys/authorize', [SshController::class, 'authorizeKey'])->middleware('licence.writes')->name('ssh.keys.authorize');
        Route::post('ssh/keys/deauthorize', [SshController::class, 'deauthorizeKey'])->middleware('licence.writes')->name('ssh.keys.deauthorize');
        Route::delete('ssh/keys', [SshController::class, 'destroy'])->middleware('licence.writes')->name('ssh.keys.destroy');
        Route::get('ssh/keys/download', [SshController::class, 'download'])->name('ssh.keys.download');
        Route::post('ssh/access', [SshController::class, 'access'])->middleware('licence.writes')->name('ssh.access');

        Route::get('stats', [StatsController::class, 'index'])->name('stats.index');

        // ---- Domain: addon, subdomain, alias, redirect ----------------------
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::post('domains/subdomain', [DomainController::class, 'storeSubdomain'])
            ->middleware('licence.writes')->name('domains.subdomain');
        Route::post('domains/addon', [DomainController::class, 'storeAddon'])
            ->middleware('licence.writes')->name('domains.addon');
        Route::post('domains/parked', [DomainController::class, 'storeParked'])
            ->middleware('licence.writes')->name('domains.parked');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])
            ->middleware('licence.writes')->name('domains.destroy');

        Route::get('redirects', [RedirectController::class, 'index'])->name('redirects.index');
        Route::post('redirects', [RedirectController::class, 'store'])
            ->middleware('licence.writes')->name('redirects.store');
        Route::delete('redirects/{redirect}', [RedirectController::class, 'destroy'])
            ->middleware('licence.writes')->name('redirects.destroy');

        // ---- Database users and remote access ------------------------------
        Route::get('databases/users', [DatabaseUserController::class, 'index'])->name('databases.users');
        Route::post('databases/users', [DatabaseUserController::class, 'store'])
            ->middleware('licence.writes')->name('databases.users.store');
        Route::post('databases/users/{dbUser}/password', [DatabaseUserController::class, 'password'])
            ->middleware('licence.writes')->name('databases.users.password');
        Route::delete('databases/users/{dbUser}', [DatabaseUserController::class, 'destroy'])
            ->middleware('licence.writes')->name('databases.users.destroy');
        Route::post('databases/remote', [DatabaseUserController::class, 'storeRemote'])
            ->middleware('licence.writes')->name('databases.remote.store');
        Route::delete('databases/remote/{remote}', [DatabaseUserController::class, 'destroyRemote'])
            ->middleware('licence.writes')->name('databases.remote.destroy');

        // ---- SSL -----------------------------------------------------------
        Route::get('ssl', [SslController::class, 'index'])->name('ssl.index');
        Route::post('ssl/auto/{domain}', [SslController::class, 'autoIssue'])
            ->middleware('licence.writes')->name('ssl.auto');
        Route::post('ssl/csr/{domain}', [SslController::class, 'csr'])
            ->middleware('licence.writes')->name('ssl.csr');
        Route::post('ssl/install', [SslController::class, 'install'])
            ->middleware('licence.writes')->name('ssl.install');
        Route::post('ssl/inspect/{domain}', [SslController::class, 'inspect'])->name('ssl.inspect');
        Route::delete('ssl/{certificate}', [SslController::class, 'destroy'])
            ->middleware('licence.writes')->name('ssl.destroy');

        // ---- FTP -----------------------------------------------------------
        Route::get('ftp', [FtpController::class, 'index'])->name('ftp.index');
        Route::post('ftp', [FtpController::class, 'store'])
            ->middleware('licence.writes')->name('ftp.store');
        Route::post('ftp/{ftp}/password', [FtpController::class, 'password'])
            ->middleware('licence.writes')->name('ftp.password');
        Route::delete('ftp/{ftp}', [FtpController::class, 'destroy'])
            ->middleware('licence.writes')->name('ftp.destroy');

        // ---- Email policy --------------------------------------------------
        Route::middleware('mail.on')->group(function () {
            Route::get('email/policy', [EmailPolicyController::class, 'index'])->name('email.policy');
            Route::post('email/forwarders', [EmailPolicyController::class, 'storeForwarder'])
                ->middleware('licence.writes')->name('email.forwarders.store');
            Route::delete('email/forwarders/{forwarder}', [EmailPolicyController::class, 'destroyForwarder'])
                ->middleware('licence.writes')->name('email.forwarders.destroy');
            Route::post('email/autoresponders', [EmailPolicyController::class, 'storeAutoresponder'])
                ->middleware('licence.writes')->name('email.autoresponders.store');
            Route::delete('email/autoresponders/{autoresponder}', [EmailPolicyController::class, 'destroyAutoresponder'])
                ->middleware('licence.writes')->name('email.autoresponders.destroy');
            Route::post('email/catchall', [EmailPolicyController::class, 'storeCatchall'])
                ->middleware('licence.writes')->name('email.catchall.store');
            Route::delete('email/catchall/{domainId}', [EmailPolicyController::class, 'destroyCatchall'])
                ->middleware('licence.writes')->name('email.catchall.destroy');
            Route::post('email/lists', [EmailPolicyController::class, 'storeList'])
                ->middleware('licence.writes')->name('email.lists.store');
            Route::delete('email/lists/{list}', [EmailPolicyController::class, 'destroyList'])
                ->middleware('licence.writes')->name('email.lists.destroy');
            Route::post('email/settings', [EmailPolicyController::class, 'storeSettings'])
                ->middleware('licence.writes')->name('email.settings.store');
            Route::post('email/dkim/{domainId}', [EmailPolicyController::class, 'dkim'])
                ->middleware('licence.writes')->name('email.dkim');

            Route::get('email/filters', [EmailFilterController::class, 'index'])->name('email.filters');
            Route::post('email/filters', [EmailFilterController::class, 'store'])
                ->middleware('licence.writes')->name('email.filters.store');
            Route::delete('email/filters/{filter}', [EmailFilterController::class, 'destroy'])
                ->middleware('licence.writes')->name('email.filters.destroy');
        });

        // ---- Security ------------------------------------------------------
        Route::get('security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('security/block', [SecurityController::class, 'storeBlock'])
            ->middleware('licence.writes')->name('security.block');
        Route::delete('security/block/{block}', [SecurityController::class, 'destroyBlock'])
            ->middleware('licence.writes')->name('security.block.destroy');
        Route::post('security/protect', [SecurityController::class, 'storeProtection'])
            ->middleware('licence.writes')->name('security.protect');
        Route::delete('security/protect/{protection}', [SecurityController::class, 'destroyProtection'])
            ->middleware('licence.writes')->name('security.protect.destroy');
        Route::get('security/tokens', [ApiTokenController::class, 'index'])->name('security.tokens');
        Route::post('security/tokens', [ApiTokenController::class, 'store'])
            ->middleware('licence.writes')->name('security.tokens.store');
        Route::delete('security/tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->middleware('licence.writes')->name('security.tokens.destroy');

        // ---- Web configuration ---------------------------------------------
        Route::get('config/mime', [WebConfigController::class, 'mime'])->name('config.mime');
        Route::post('config/mime', [WebConfigController::class, 'storeMime'])
            ->middleware('licence.writes')->name('config.mime.store');
        Route::delete('config/mime/{mime}', [WebConfigController::class, 'destroyMime'])
            ->middleware('licence.writes')->name('config.mime.destroy');
        Route::get('config/errors', [WebConfigController::class, 'errorPages'])->name('config.errors');
        Route::post('config/errors', [WebConfigController::class, 'storeErrorPage'])
            ->middleware('licence.writes')->name('config.errors.store');
        Route::get('config/php', [WebConfigController::class, 'php'])->name('config.php');
        Route::post('config/php', [WebConfigController::class, 'storePhp'])
            ->middleware('licence.writes')->name('config.php.store');
        // Account-wide, so the menu can link to it without picking a domain.
        Route::get('config/extensions', [WebConfigController::class, 'allExtensions'])
            ->name('config.extensions.all');

        Route::get('config/php/{domain}/extensions', [WebConfigController::class, 'extensions'])
            ->name('config.extensions');

        // ---- Applications ---------------------------------------------------
        Route::get('apps', [ApplicationController::class, 'index'])->name('apps.index');
        Route::post('apps', [ApplicationController::class, 'store'])
            ->middleware('licence.writes')->name('apps.store');
        Route::post('apps/{application}/check', [ApplicationController::class, 'check'])
            ->name('apps.check');
        Route::delete('apps/{application}', [ApplicationController::class, 'destroy'])
            ->middleware('licence.writes')->name('apps.destroy');

        // ---- Developer -------------------------------------------------------
        Route::get('dev', [DeveloperController::class, 'index'])->name('dev.index');
        Route::post('dev', [DeveloperController::class, 'store'])
            ->middleware('licence.writes')->name('dev.store');
        Route::post('dev/{repository}/deploy', [DeveloperController::class, 'deploy'])
            ->middleware('licence.writes')->name('dev.deploy');
        Route::delete('dev/{repository}', [DeveloperController::class, 'destroy'])
            ->middleware('licence.writes')->name('dev.destroy');

        // ---- Usage and logs ---------------------------------------------------
        Route::get('usage/bandwidth', [ObservabilityController::class, 'bandwidth'])->name('usage.bandwidth');
        Route::get('usage/disk', [ObservabilityController::class, 'disk'])->name('usage.disk');
        Route::get('usage/logins', [ObservabilityController::class, 'logins'])->name('usage.logins');
    });

// ---- Webmail -------------------------------------------------------------
Route::middleware(['auth', 'role:customer', 'mail.on'])
    ->prefix('webmail')->name('webmail.')->group(function () {
        Route::get('/', [InboxController::class, 'index'])->name('inbox');
        Route::post('login', [InboxController::class, 'login'])->name('login');
        Route::post('logout', [InboxController::class, 'logout'])->name('logout');
        Route::get('messages/{uid}', [InboxController::class, 'show'])->name('show');
        Route::get('compose', [InboxController::class, 'compose'])->name('compose');
        Route::post('compose', [InboxController::class, 'send'])->name('send');
    });
