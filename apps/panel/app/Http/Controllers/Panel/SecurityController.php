<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\HostingFailure;
use App\Domain\Hosting\SecurityManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\DirProtectRequest;
use App\Http\Requests\Hosting\IpBlockRequest;
use App\Models\DirectoryProtection;
use App\Models\IpBlock;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class SecurityController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly SecurityManager $security) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.security.index', [
            'account' => $account,
            'blocks' => $account->ipBlocks()->orderByDesc('created_at')->get(),
            'protections' => $account->directoryProtections()->orderBy('path')->get(),
        ]);
    }

    public function storeBlock(IpBlockRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $block = $this->security->blockIp(
                $account,
                $request->string('cidr')->value(),
                $request->input('reason'),
                $request->input('expires_at') ? now()->parse($request->input('expires_at')) : null,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The address was not blocked'));
        }

        return back()->with('status', "{$block->cidr} is now denied for this account.");
    }

    public function destroyBlock(IpBlock $block): RedirectResponse
    {
        $this->authorize('delete', $block);

        try {
            $this->security->unblockIp($block);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The address was not unblocked'));
        }

        return back()->with('status', 'Address unblocked.');
    }

    public function storeProtection(DirProtectRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $protection = $this->security->protect(
                $account,
                $request->string('path')->value(),
                $request->string('realm')->value(),
                $request->string('username')->value(),
                $request->string('password')->value(),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The directory was not protected'));
        }

        return back()->with('status', "{$protection->path} now requires a username and password.");
    }

    public function destroyProtection(DirectoryProtection $protection): RedirectResponse
    {
        $this->authorize('delete', $protection);

        try {
            $this->security->unprotect($protection);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The protection was not removed'));
        }

        return back()->with('status', 'Directory protection removed.');
    }
}
