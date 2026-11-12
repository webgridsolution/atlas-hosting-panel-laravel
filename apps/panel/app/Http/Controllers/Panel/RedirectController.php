<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Agent\Agent;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\RedirectRequest;
use App\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class RedirectController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly Agent $agent) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.redirects.index', [
            'account' => $account,
            'domains' => $account->domains()->orderBy('domain')->get(),
            'redirects' => $account->redirects()->with('domain')->latest()->get(),
        ]);
    }

    public function store(RedirectRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $this->agent->call('htaccess.redirect.write', [
                'username' => $account->username,
                'domain' => $domain->domain,
                'source' => $request->string('source_path')->value(),
                'target' => $request->string('target_url')->value(),
                'status' => (int) $request->input('status_code', 301),
                'remove' => false,
            ]);

            Redirect::updateOrCreate(
                ['domain_id' => $domain->id, 'source_path' => $request->string('source_path')->value()],
                [
                    'account_id' => $account->id,
                    'target_url' => $request->string('target_url')->value(),
                    'status_code' => (int) $request->input('status_code', 301),
                    'is_active' => true,
                ],
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The redirect was not created'));
        }

        return back()->with('status', 'Redirect created. Apache was reloaded.');
    }

    public function destroy(Redirect $redirect): RedirectResponse
    {
        $this->authorize('delete', $redirect);

        try {
            $this->agent->call('htaccess.redirect.write', [
                'username' => $redirect->account->username,
                'domain' => $redirect->domain->domain,
                'source' => $redirect->source_path,
                'target' => $redirect->target_url,
                'status' => $redirect->status_code,
                'remove' => true,
            ]);

            $redirect->delete();
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The redirect was not removed'));
        }

        return back()->with('status', 'Redirect removed.');
    }
}
