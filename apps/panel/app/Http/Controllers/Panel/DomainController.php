<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\DomainManager;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\AddonDomainRequest;
use App\Http\Requests\Hosting\ParkedDomainRequest;
use App\Http\Requests\Hosting\SubdomainRequest;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * Addon domains, subdomains and parked aliases in one place, because from the
 * customer's point of view they are three ways of saying "put this name on my
 * server".
 */
class DomainController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly DomainManager $domains) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        $domains = $account->domains()->orderBy('type')->orderBy('domain')->get();
        $limit = $account->package?->max_domains;

        return view('panel.domains.index', [
            'account' => $account,
            'domains' => $domains,
            'sites' => $domains->whereIn('type', [
                Domain::TYPE_PRIMARY, Domain::TYPE_ADDON, Domain::TYPE_SUBDOMAIN,
            ]),
            'limit' => $limit,
            'atLimit' => $limit !== null && $domains->count() >= $limit,
            'phpVersions' => config('atlas.php.versions'),
        ]);
    }

    public function storeSubdomain(SubdomainRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $parent = $account->domains()->findOrFail((int) $request->input('parent_id'));

        try {
            $domain = $this->domains->addSubdomain(
                $account,
                $parent,
                $request->string('label')->value(),
                $request->boolean('own_root'),
                $request->input('php_version'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The subdomain was not created'));
        }

        return back()->with('status', "{$domain->domain} created. Point its A record at this server, then add a certificate.");
    }

    public function storeAddon(AddonDomainRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $limit = $account->package?->max_domains;

        if ($limit !== null && $account->domains()->count() >= $limit) {
            return back()->withInput()->with('error', "Your package allows {$limit} domain(s).");
        }

        try {
            $domain = $this->domains->addAddonDomain(
                $account,
                strtolower($request->string('domain')->value()),
                $request->input('php_version'),
                $request->boolean('with_mail', true),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The domain was not added'));
        }

        return back()->with('status', "{$domain->domain} added with its own document root and vhost.");
    }

    public function storeParked(ParkedDomainRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $target = $account->domains()->findOrFail((int) $request->input('target_id'));

        try {
            $domain = $this->domains->parkDomain($account, $target, strtolower($request->string('domain')->value()));
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The alias was not created'));
        }

        return back()->with('status', "{$domain->domain} now serves the same site as {$target->domain}.");
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $this->authorize('delete', $domain);

        $account = $this->requireAccount();

        try {
            $this->domains->remove($account, $domain);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The domain was not removed'));
        }

        return back()->with('status', "{$domain->domain} removed. Its files are still on disk.");
    }
}
