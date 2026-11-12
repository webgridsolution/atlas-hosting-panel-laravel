<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\WebsiteManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\StoreDomainRequest;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class WebsiteController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly WebsiteManager $websites) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.websites.index', [
            'account' => $account,
            'domains' => $account->domains()->orderByDesc('is_primary')->get(),
            'limit' => $account->package?->max_domains,
        ]);
    }

    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $limit = $account->package?->max_domains;

        if ($limit !== null && $account->domains()->count() >= $limit) {
            return back()->with('error', "Your package allows {$limit} website(s). Upgrade to add more.");
        }

        try {
            $domain = $this->websites->addDomain(
                $account,
                $request->string('domain')->lower()->value(),
                $request->input('document_root') ?: null,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The domain was not added: '.$e->getMessage());
        }

        return back()->with('status', "{$domain->domain} is live. Point its DNS at this server, then issue a certificate.");
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $this->authorize('delete', $domain);

        if ($domain->account->domains()->count() === 1) {
            return back()->with('error', 'An account needs at least one website. Add another domain first.');
        }

        $this->websites->removeDomain($domain);

        return back()->with('status', "{$domain->domain} removed.");
    }

    public function certificate(Domain $domain): RedirectResponse
    {
        $this->authorize('update', $domain);

        try {
            $this->websites->issueCertificate($domain);
        } catch (Throwable $e) {
            return back()->with('error', 'Certificate issuance failed: '.$e->getMessage());
        }

        return back()->with('status', "Certificate installed for {$domain->domain}. HTTPS is now on.");
    }
}
