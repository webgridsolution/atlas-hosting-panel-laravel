<?php

namespace App\Http\Controllers\Whm;

use App\Domain\Hosting\AccountProvisioner;
use App\Domain\Hosting\Metrics;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Models\Account;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly Metrics $metrics,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status');

        $accounts = Account::query()
            ->with(['package', 'user', 'domains'])
            ->when($search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner->where('username', 'like', "%{$search}%")->orWhere('domain', 'like', "%{$search}%")
            ))
            ->when(in_array($status, ['active', 'suspended', 'pending'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('whm.accounts.index', compact('accounts', 'search', 'status'));
    }

    public function create(): View
    {
        return view('whm.accounts.create', [
            'packages' => Package::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $package = $request->package();
        $owner = null;
        $password = null;

        if ($request->filled('owner_email')) {
            $owner = User::firstOrNew(['email' => $request->string('owner_email')->lower()->value()]);

            if (! $owner->exists) {
                $password = Str::password(16);
                $owner->forceFill([
                    'name' => $request->input('owner_name') ?: Str::before($owner->email, '@'),
                    'password' => Hash::make($password),
                    'is_active' => true,
                ])->save();
                $owner->assignRole('customer');
            }
        }

        try {
            $account = $this->provisioner->create(
                username: $request->string('username')->lower()->value(),
                domain: $request->string('domain')->lower()->value(),
                package: $package,
                owner: $owner,
                reseller: $request->user()->hasRole('reseller') ? $request->user() : null,
                notes: $request->input('notes'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The account was not created: '.$e->getMessage());
        }

        $message = "Account {$account->username} created with {$account->domain}.";

        if ($password !== null) {
            // Shown once. There is no default password anywhere in this panel.
            $message .= " Panel login for {$owner->email}: {$password}";
        }

        return redirect()->route('whm.accounts.show', $account)->with('status', $message);
    }

    public function show(Account $account): View
    {
        $account->load(['package', 'user', 'domains', 'databases', 'mailboxes', 'cronJobs', 'backups']);

        return view('whm.accounts.show', [
            'account' => $account,
            'usage' => $this->metrics->accountUsage($account),
            'canPurge' => $account->status === Account::STATUS_SUSPENDED,
        ]);
    }

    public function suspend(Request $request, Account $account): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? '';

        $account->forceFill(['suspended_at' => now()])->save();
        $this->provisioner->suspend($account, $reason);

        return back()->with('status', "Suspended {$account->username}. Sites now serve a suspension page; data is untouched.");
    }

    public function unsuspend(Account $account): RedirectResponse
    {
        $this->provisioner->unsuspend($account);

        return back()->with('status', "{$account->username} is active again.");
    }

    public function quota(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'disk_mb' => ['required', 'integer', 'min:256', 'max:10485760'],
            'inodes' => ['required', 'integer', 'min:1000', 'max:50000000'],
        ]);

        $this->provisioner->setQuota($account, (int) $data['disk_mb'], (int) $data['inodes']);
        $this->metrics->flushAccount($account);

        return back()->with('status', 'Quota updated on the server.');
    }

    /**
     * Deleting an account never deletes data unless the account is already
     * suspended and the admin explicitly asks for it on a second screen.
     */
    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'delete_data' => ['boolean'],
            'confirm' => ['nullable', 'string'],
        ]);

        $deleteData = (bool) ($data['delete_data'] ?? false);

        if ($deleteData) {
            if ($account->status !== Account::STATUS_SUSPENDED) {
                return back()->with('error', 'Suspend the account before deleting its data.');
            }

            if (($data['confirm'] ?? '') !== $account->username) {
                return back()->with('error', 'Type the username exactly to confirm data deletion.');
            }
        }

        DB::transaction(fn () => $this->provisioner->destroy($account, $deleteData));

        return redirect()->route('whm.accounts.index')->with(
            'status',
            $deleteData
                ? "Account {$account->username} and its data were removed."
                : "Account {$account->username} was removed. Its files, databases and mail are still on the server.",
        );
    }
}
