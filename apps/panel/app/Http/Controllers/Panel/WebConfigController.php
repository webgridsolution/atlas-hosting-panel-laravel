<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\HostingFailure;
use App\Domain\Hosting\WebConfigManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\ErrorPageRequest;
use App\Http\Requests\Hosting\MimeRequest;
use App\Http\Requests\Hosting\PhpSettingsRequest;
use App\Models\Domain;
use App\Models\ErrorPage;
use App\Models\MimeType;
use App\Models\PhpSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/** MIME overrides, error documents and PHP configuration, per site. */
class WebConfigController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly WebConfigManager $config) {}

    public function mime(): View
    {
        $account = $this->requireAccount();

        return view('panel.config.mime', [
            'account' => $account,
            'mimeTypes' => $account->mimeTypes()->orderBy('extension')->get(),
        ]);
    }

    public function storeMime(MimeRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $mime = $this->config->addMime(
                $account,
                $request->string('extension')->value(),
                $request->string('mime_type')->value(),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The MIME type was not added'));
        }

        return back()->with('status', ".{$mime->extension} is now served as {$mime->mime_type}.");
    }

    public function destroyMime(MimeType $mime): RedirectResponse
    {
        $this->authorize('delete', $mime);

        try {
            $this->config->removeMime($mime);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The MIME type was not removed'));
        }

        return back()->with('status', 'MIME override removed.');
    }

    public function errorPages(): View
    {
        $account = $this->requireAccount();

        return view('panel.config.errors', [
            'account' => $account,
            'domains' => $account->sites()->orderBy('domain')->get(),
            'pages' => ErrorPage::whereIn('domain_id', $account->sites()->pluck('id'))
                ->with('domain')->orderBy('status_code')->get(),
            'statuses' => [400, 401, 403, 404, 405, 408, 429, 500, 502, 503, 504],
        ]);
    }

    public function storeErrorPage(ErrorPageRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->sites()->findOrFail((int) $request->input('domain_id'));

        try {
            $page = $this->config->saveErrorPage(
                $account,
                $domain,
                (int) $request->input('status_code'),
                $request->string('body')->value(),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The error page was not saved'));
        }

        return back()->with('status', "Custom {$page->status_code} page saved for {$domain->domain}.");
    }

    public function php(): View
    {
        $account = $this->requireAccount();
        $domains = $account->sites()->orderBy('domain')->get();

        return view('panel.config.php', [
            'account' => $account,
            'domains' => $domains,
            'settings' => PhpSetting::where('account_id', $account->id)
                ->get()->groupBy('domain_id'),
            'directives' => PhpSetting::EDITABLE,
            'phpVersions' => config('atlas.php.versions'),
            'defaultVersion' => config('atlas.php.default', '8.3'),
        ]);
    }

    public function storePhp(PhpSettingsRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->sites()->findOrFail((int) $request->input('domain_id'));

        try {
            $this->config->savePhp(
                $account,
                $domain,
                (string) $request->input('php_version'),
                (array) $request->input('settings', []),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The PHP settings were not saved'));
        }

        return back()->with('status', "PHP configuration saved for {$domain->domain}.");
    }

    /**
     * PHP extensions across the account, one block per PHP version in use.
     *
     * The per-domain screen answers "what does this site have"; this one answers
     * "what am I running anywhere", which is the question a customer actually
     * asks when an extension they expected is missing. It is a different route
     * because it needs no domain, so the menu can link to it.
     */
    public function allExtensions(): View
    {
        $account = $this->requireAccount();

        $versions = $account->domains()
            ->get()
            ->map(fn (Domain $d) => $d->effectivePhpVersion())
            ->unique()
            ->sort()
            ->values();

        $byVersion = [];
        $error = null;

        foreach ($versions as $version) {
            try {
                $byVersion[$version] = $this->config->extensions((string) $version);
            } catch (Throwable $e) {
                $byVersion[$version] = [];
                $error ??= HostingFailure::message($e, 'The extension list could not be read from the PHP binary');
            }
        }

        return view('panel.config.extensions-all', [
            'account' => $account,
            'byVersion' => $byVersion,
            'error' => $error,
            'domains' => $account->domains()->orderBy('domain')->get(),
        ]);
    }

    public function extensions(Domain $domain): View
    {
        $this->authorize('view', $domain);

        try {
            $extensions = $this->config->extensions($domain->effectivePhpVersion());
            $error = null;
        } catch (Throwable $e) {
            $extensions = [];
            $error = HostingFailure::message($e, 'The extension list could not be read from the PHP binary');
        }

        return view('panel.config.extensions', [
            'account' => $this->requireAccount(),
            'domain' => $domain,
            'extensions' => $extensions,
            'error' => $error,
        ]);
    }
}
