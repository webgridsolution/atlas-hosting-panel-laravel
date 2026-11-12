<?php

namespace App\Http\Controllers\Reseller;

use App\Domain\Hosting\AccountProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Models\Account;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * A reseller sees and manages only their own accounts. The scoping lives in
 * Account::visibleTo and in AccountPolicy; both are applied here so a crafted
 * URL cannot reach another reseller's customer.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountProvisioner $provisioner) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status');

        return view('reseller.accounts.index', [
            'accounts' => Account::visibleTo($request->user())
                ->with(['package', 'domains'])
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($inner) => $inner->where('username', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%"),
                ))
                ->when(in_array($status, ['active', 'suspended', 'pending'], true),
                    fn ($query) => $query->where('status', $status))
                ->latest()
                ->paginate(20)
                // Filters must survive a page change, otherwise page 2 of a
                // filtered list silently shows a different list.
                ->withQueryString(),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('reseller.accounts.create', [
            'packages' => Package::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        try {
            $account = $this->provisioner->create(
                username: $request->string('username')->lower()->value(),
                domain: $request->string('domain')->lower()->value(),
                package: $request->package(),
                reseller: $request->user(),
                notes: $request->input('notes'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The account was not created: '.$e->getMessage());
        }

        return redirect()->route('reseller.accounts.show', $account)
            ->with('status', "Account {$account->username} created.");
    }

    public function show(Account $account): View
    {
        $this->authorize('view', $account);

        return view('reseller.accounts.show', [
            'account' => $account->load(['package', 'domains', 'databases', 'mailboxes']),
        ]);
    }

    public function suspend(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('manage', $account);
        $this->provisioner->suspend($account, (string) $request->input('reason', ''));

        return back()->with('status', "Suspended {$account->username}.");
    }

    public function unsuspend(Account $account): RedirectResponse
    {
        $this->authorize('manage', $account);
        $this->provisioner->unsuspend($account);

        return back()->with('status', "{$account->username} is active again.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        // A reseller can remove an account from their list; the data stays on
        // the machine and only an admin can purge it.
        $this->provisioner->destroy($account, false);

        return redirect()->route('reseller.accounts.index')
            ->with('status', "{$account->username} removed. Its data remains on the server.");
    }
}
