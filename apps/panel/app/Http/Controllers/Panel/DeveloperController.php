<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\DeveloperManager;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\GitRequest;
use App\Models\GitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class DeveloperController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly DeveloperManager $git) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.dev.index', [
            'account' => $account,
            'repos' => $account->repositories()->with('domain')->latest()->get(),
            'domains' => $account->sites()->orderBy('domain')->get(),
        ]);
    }

    public function store(GitRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->sites()->findOrFail((int) $request->input('domain_id'));

        try {
            $repo = $this->git->create(
                $account,
                $domain,
                (string) $request->input('path', ''),
                $request->input('remote'),
                (string) $request->input('branch', 'main'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The repository was not created'));
        }

        return back()->with('status', "Repository created at /{$repo->path}.");
    }

    public function deploy(GitRepository $repository): RedirectResponse
    {
        $this->authorize('update', $repository);

        try {
            $result = $this->git->deploy($repository);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The deploy failed'));
        }

        return back()->with('status', 'Deployed: '.(trim((string) ($result['output'] ?? '')) ?: 'up to date'));
    }

    public function destroy(GitRepository $repository): RedirectResponse
    {
        $this->authorize('delete', $repository);

        $this->git->remove($repository);

        return back()->with('status', 'Repository entry removed. The files are still on disk.');
    }
}
