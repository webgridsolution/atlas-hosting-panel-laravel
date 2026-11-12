<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\EmailPolicyManager;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\EmailFilterRequest;
use App\Models\EmailFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * Server-side rules. They are compiled into a Sieve script, so they keep
 * working whether or not the customer is signed in.
 */
class EmailFilterController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly EmailPolicyManager $policies) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.email.filters', [
            'account' => $account,
            'domains' => $account->domains()->orderBy('domain')->get(),
            'filters' => $account->emailFilters()->with('domain')->orderBy('priority')->get(),
            'mailboxes' => $account->mailboxes()->orderBy('address')->get(),
        ]);
    }

    public function store(EmailFilterRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $filter = $this->policies->addFilter($account, $domain, [
                'mailbox' => $request->string('mailbox')->value(),
                'field' => $request->string('field')->value(),
                'operator' => $request->string('operator')->value(),
                'value' => $request->string('value')->value(),
                'action' => $request->string('action')->value(),
                'action_arg' => $request->input('action_arg'),
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The filter was not added'));
        }

        return back()->with('status', "Filter added to {$filter->mailbox} (Sieve script rewritten).");
    }

    public function destroy(EmailFilter $filter): RedirectResponse
    {
        $this->authorize('delete', $filter);

        try {
            $this->policies->deleteFilter($filter);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The filter was not removed'));
        }

        return back()->with('status', 'Filter removed.');
    }
}
