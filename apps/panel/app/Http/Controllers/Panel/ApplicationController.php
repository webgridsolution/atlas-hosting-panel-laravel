<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\ApplicationManager;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\ApplicationRequest;
use App\Models\InstalledApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class ApplicationController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly ApplicationManager $apps) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.apps.index', [
            'account' => $account,
            'installed' => $account->applications()->with('domain')->latest()->get(),
            'catalogue' => $this->apps->catalogue(),
            'domains' => $account->sites()->orderBy('domain')->get(),
        ]);
    }

    public function store(ApplicationRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->sites()->findOrFail((int) $request->input('domain_id'));

        try {
            $app = $this->apps->install(
                $account,
                $domain,
                $request->string('slug')->value(),
                (string) $request->input('path', ''),
                $request->string('admin_user')->value(),
                $request->string('admin_email')->value(),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The application was not installed'));
        }

        return back()->with('status', "{$app->label()} installed at {$app->url}. An admin password was generated; it is in the install log.");
    }

    public function check(InstalledApplication $application): RedirectResponse
    {
        $this->authorize('update', $application);

        try {
            $info = $this->apps->check($application);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The version check failed'));
        }

        return back()->with('status', empty($info['up_to_date'])
            ? "{$application->label()} has an update available: {$info['available']}."
            : "{$application->label()} is up to date.");
    }

    public function destroy(InstalledApplication $application): RedirectResponse
    {
        $this->authorize('delete', $application);

        try {
            $this->apps->remove($application);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The application was not removed'));
        }

        return back()->with('status', 'Application removed. Its database was kept.');
    }
}
