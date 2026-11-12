<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\FtpManager;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\FtpAccountRequest;
use App\Models\FtpAccount;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class FtpController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly FtpManager $ftp) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.ftp.index', [
            'account' => $account,
            'accounts' => $account->ftpAccounts()->with('domain')->orderBy('username')->get(),
            // What the customer types into their FTP client. The server's own
            // hostname is the honest answer; the domain would only work if the
            // A record already points here.
            'host' => Setting::get('server_hostname') ?: request()->getHost(),
        ]);
    }

    public function store(FtpAccountRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            [$row, $password] = $this->ftp->create(
                $account,
                $request->string('name')->value(),
                $request->input('directory') ?: null,
                (int) $request->input('quota_mb', 0),
                $request->boolean('read_only'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The FTP account was not created'));
        }

        return back()->with('status', "FTP account {$row->username} created. Password: {$password} — copy it now.");
    }

    public function password(FtpAccount $ftp): RedirectResponse
    {
        $this->authorize('update', $ftp);

        try {
            $password = $this->ftp->resetPassword($ftp);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The password was not changed'));
        }

        return back()->with('status', "New password for {$ftp->username}: {$password} — copy it now.");
    }

    public function destroy(FtpAccount $ftp): RedirectResponse
    {
        $this->authorize('delete', $ftp);

        try {
            $this->ftp->remove($ftp);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The FTP account was not removed'));
        }

        return back()->with('status', 'FTP account removed.');
    }
}
