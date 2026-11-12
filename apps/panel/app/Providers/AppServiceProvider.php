<?php

namespace App\Providers;

use App\Domain\Agent\Agent;
use App\Domain\Agent\SandboxAgent;
use App\Domain\Agent\SocketAgent;
use App\Domain\Hosting\AccountProvisioner;
use App\Domain\Hosting\ApplicationManager;
use App\Domain\Hosting\BackupManager;
use App\Domain\Hosting\CronManager;
use App\Domain\Hosting\DatabaseManager;
use App\Domain\Hosting\DnsManager;
use App\Domain\Hosting\DomainManager;
use App\Domain\Hosting\EmailPolicyManager;
use App\Domain\Hosting\FileManager;
use App\Domain\Hosting\FtpManager;
use App\Domain\Hosting\GitManager;
use App\Domain\Hosting\MailboxManager;
use App\Domain\Hosting\SecurityManager;
use App\Domain\Hosting\SslManager;
use App\Domain\Hosting\WebConfigManager;
use App\Domain\Hosting\WebsiteManager;
use App\Domain\Licensing\LicenseManager;
use App\Http\View\Composers\PanelBarComposer;
use App\Models\Autoresponder;
use App\Models\DatabaseUser;
use App\Models\DirectoryProtection;
use App\Models\EmailFilter;
use App\Models\EmailForwarder;
use App\Models\FtpAccount;
use App\Models\GitRepository;
use App\Models\InstalledApplication;
use App\Models\IpBlock;
use App\Models\MailingList;
use App\Models\MimeType;
use App\Models\Redirect;
use App\Models\RemoteMysqlHost;
use App\Models\SslCertificate;
use App\Policies\AutoresponderPolicy;
use App\Policies\DatabaseUserPolicy;
use App\Policies\DirectoryProtectionPolicy;
use App\Policies\EmailFilterPolicy;
use App\Policies\EmailForwarderPolicy;
use App\Policies\FtpAccountPolicy;
use App\Policies\GitRepositoryPolicy;
use App\Policies\InstalledApplicationPolicy;
use App\Policies\IpBlockPolicy;
use App\Policies\MailingListPolicy;
use App\Policies\MimeTypePolicy;
use App\Policies\RedirectPolicy;
use App\Policies\RemoteMysqlHostPolicy;
use App\Policies\SslCertificatePolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One agent binding for the whole request. In sandbox mode the panel is
        // usable on a laptop with no root; the UI labels that clearly.
        $this->app->singleton(Agent::class, function () {
            if (config('atlas.agent.driver') === 'sandbox') {
                return new SandboxAgent;
            }

            return new SocketAgent(
                socket: (string) config('atlas.agent.socket'),
                keyPath: (string) config('atlas.agent.key'),
                timeout: (int) config('atlas.agent.timeout'),
            );
        });

        $this->app->singleton(LicenseManager::class);
        $this->app->singleton(AccountProvisioner::class);
        $this->app->singleton(WebsiteManager::class);
        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(MailboxManager::class);
        $this->app->singleton(DnsManager::class);
        $this->app->singleton(CronManager::class);
        $this->app->singleton(BackupManager::class);
        $this->app->singleton(FileManager::class);

        // Hosting feature set: one manager per screen area.
        $this->app->singleton(DomainManager::class);
        $this->app->singleton(FtpManager::class);
        $this->app->singleton(EmailPolicyManager::class);
        $this->app->singleton(SecurityManager::class);
        $this->app->singleton(WebConfigManager::class);
        $this->app->singleton(SslManager::class);
        $this->app->singleton(GitManager::class);
        $this->app->singleton(ApplicationManager::class);
    }

    public function boot(): void
    {
        // The customer panel's top strip renders on every screen, so its numbers
        // are collected once per request here rather than in each controller.
        View::composer('layouts.app', PanelBarComposer::class);

        // Explicit policy registration: an unregistered model would silently
        // fall back to "nobody may", which is safe but produces a 403 with no
        // explanation. One line per model keeps the mapping readable.
        Gate::policy(Redirect::class, RedirectPolicy::class);
        Gate::policy(FtpAccount::class, FtpAccountPolicy::class);
        Gate::policy(EmailForwarder::class, EmailForwarderPolicy::class);
        Gate::policy(Autoresponder::class, AutoresponderPolicy::class);
        Gate::policy(EmailFilter::class, EmailFilterPolicy::class);
        Gate::policy(MailingList::class, MailingListPolicy::class);
        Gate::policy(SslCertificate::class, SslCertificatePolicy::class);
        Gate::policy(DirectoryProtection::class, DirectoryProtectionPolicy::class);
        Gate::policy(IpBlock::class, IpBlockPolicy::class);
        Gate::policy(MimeType::class, MimeTypePolicy::class);
        Gate::policy(InstalledApplication::class, InstalledApplicationPolicy::class);
        Gate::policy(GitRepository::class, GitRepositoryPolicy::class);
        Gate::policy(DatabaseUser::class, DatabaseUserPolicy::class);
        Gate::policy(RemoteMysqlHost::class, RemoteMysqlHostPolicy::class);

        // Additional trusted licence keys can be dropped in by the vendor or by
        // an on-premise issuer without touching config or .env.
        $extraKeys = storage_path('app/licence/keys.json');

        if (is_file($extraKeys)) {
            $keys = json_decode((string) file_get_contents($extraKeys), true);

            if (is_array($keys)) {
                config(['atlas.central.keys' => $keys + (array) config('atlas.central.keys')]);
            }
        }

        Gate::before(fn ($user) => $user->hasRole('admin') ? true : null);

        // Only an active licence may create accounts — a reseller must not be
        // able to keep provisioning on an expired licence.
        Gate::define('provision', fn ($user) => app(LicenseManager::class)->allowsWrites());

        // Pagination is ours, not Laravel's Tailwind default. Every paginated
        // screen renders resources/views/pagination/atlas.blade.php.
        Paginator::defaultView('pagination.atlas');
        Paginator::defaultSimpleView('pagination.simple-atlas');

        Blade::if('sandbox', fn () => config('atlas.agent.driver') === 'sandbox');
        Blade::if('mailon', fn () => (bool) config('atlas.mail.enabled'));
        Blade::if('dnoon', fn () => (bool) config('atlas.dns.enabled'));
    }
}
