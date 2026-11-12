<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\MailboxManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\StoreMailboxRequest;
use App\Models\Mailbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class MailboxController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly MailboxManager $mailboxes) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.email.index', [
            'account' => $account,
            'mailboxes' => $account->mailboxes()->orderBy('address')->get(),
            'domains' => $account->domains()->pluck('domain'),
            'limit' => $account->package?->max_mailboxes,
        ]);
    }

    public function store(StoreMailboxRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $limit = $account->package?->max_mailboxes;

        if ($limit !== null && $account->mailboxes()->count() >= $limit) {
            return back()->with('error', "Your package allows {$limit} mailbox(es).");
        }

        $domain = $request->input('domain');

        if ($domain !== null && ! $account->domains()->where('domain', $domain)->exists()) {
            return back()->withInput()->with('error', 'Choose one of your own domains.');
        }

        try {
            $mailbox = $this->mailboxes->create(
                $account,
                (string) $request->input('local_part'),
                (string) $request->input('password'),
                (int) $request->input('quota_mb'),
                $domain,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The mailbox was not created: '.$e->getMessage());
        }

        return back()->with('status', "Mailbox {$mailbox->address} created. Sign in at /webmail with this address and password.");
    }

    public function password(Mailbox $mailbox): RedirectResponse
    {
        $this->authorize('delete', $mailbox);

        $password = Str::password(14, symbols: false);

        try {
            $this->mailboxes->resetPassword($mailbox, $password);
        } catch (Throwable $e) {
            return back()->with('error', 'The password was not changed: '.$e->getMessage());
        }

        return back()->with('status', "New password for {$mailbox->address}: {$password}");
    }

    public function destroy(Mailbox $mailbox): RedirectResponse
    {
        $this->authorize('delete', $mailbox);

        try {
            $this->mailboxes->destroy($mailbox);
        } catch (Throwable $e) {
            return back()->with('error', 'The mailbox was not deleted: '.$e->getMessage());
        }

        return back()->with('status', "{$mailbox->address} deleted.");
    }
}
