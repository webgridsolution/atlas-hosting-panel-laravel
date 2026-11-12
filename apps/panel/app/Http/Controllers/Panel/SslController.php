<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\HostingFailure;
use App\Domain\Hosting\SslManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\SslInstallRequest;
use App\Models\Domain;
use App\Models\SslCertificate;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class SslController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly SslManager $ssl) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.ssl.index', [
            'account' => $account,
            'domains' => $account->sites()->orderBy('domain')->get(),
            'certificates' => $account->certificates()->with('domain')->latest()->get(),
        ]);
    }

    public function autoIssue(Domain $domain): RedirectResponse
    {
        $this->authorize('update', $domain);

        try {
            $cert = $this->ssl->autoIssue($this->requireAccount(), $domain);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The certificate was not issued'));
        }

        return back()->with('status', "Certificate issued for {$domain->domain}, valid until {$cert->expires_at?->format('j M Y')}.");
    }

    public function csr(Domain $domain): RedirectResponse
    {
        $this->authorize('view', $domain);

        try {
            $result = $this->ssl->csr($domain);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The signing request was not generated'));
        }

        return back()
            ->with('status', "Certificate signing request generated for {$domain->domain}.")
            ->with('csr', $result['csr_pem'] ?? '');
    }

    public function inspect(Domain $domain): RedirectResponse
    {
        $this->authorize('view', $domain);

        try {
            $info = $this->ssl->inspect($domain);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The certificate could not be read'));
        }

        if (empty($info['present'])) {
            return back()->with('error', "No certificate is installed on the server for {$domain->domain}.");
        }

        return back()->with('status', sprintf(
            '%s: issued by %s, expires %s.',
            $domain->domain,
            $info['issuer'] ?? 'unknown issuer',
            isset($info['expires_at']) ? now()->parse($info['expires_at'])->format('j M Y') : 'unknown date',
        ));
    }

    public function install(SslInstallRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $cert = $this->ssl->install(
                $account,
                $domain,
                $request->string('cert_pem')->value(),
                $request->input('key_pem'),
                $request->input('ca_pem'),
                null,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The certificate was not installed'));
        }

        return back()->with('status', "Certificate installed for {$domain->domain}, valid until {$cert->expires_at?->format('j M Y')}.");
    }

    public function destroy(SslCertificate $certificate): RedirectResponse
    {
        $this->authorize('delete', $certificate);

        try {
            $this->ssl->remove($certificate);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The certificate was not removed'));
        }

        return back()->with('status', 'Certificate removed.');
    }
}
