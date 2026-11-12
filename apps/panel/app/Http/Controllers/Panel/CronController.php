<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\CronManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\StoreCronRequest;
use App\Models\CronJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class CronController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly CronManager $cron) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.cron.index', [
            'account' => $account,
            'jobs' => $account->cronJobs()->orderBy('id')->get(),
        ]);
    }

    public function store(StoreCronRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $this->cron->add(
                $account,
                (string) $request->input('schedule'),
                (string) $request->input('command'),
                $request->input('description'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The cron job was not saved: '.$e->getMessage());
        }

        return back()->with('status', 'Cron job installed.');
    }

    public function destroy(CronJob $job): RedirectResponse
    {
        $this->authorize('delete', $job);

        try {
            $this->cron->delete($job);
        } catch (Throwable $e) {
            return back()->with('error', 'The cron job was not removed: '.$e->getMessage());
        }

        return back()->with('status', 'Cron job removed.');
    }
}
