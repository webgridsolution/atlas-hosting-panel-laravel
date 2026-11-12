<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\EmailPolicyManager;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\AutoresponderRequest;
use App\Http\Requests\Hosting\CatchallRequest;
use App\Http\Requests\Hosting\EmailSettingsRequest;
use App\Http\Requests\Hosting\ForwarderRequest;
use App\Http\Requests\Hosting\MailingListRequest;
use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\EmailCatchall;
use App\Models\EmailForwarder;
use App\Models\EmailSetting;
use App\Models\MailingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * Routing and policy for one domain's mail: where a message is forwarded, who
 * auto-replies, what happens to unmatched addresses, how spam is scored and
 * which lists exist.
 */
class EmailPolicyController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly EmailPolicyManager $policies) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.email.policy', [
            'account' => $account,
            'domains' => $account->domains()->orderBy('domain')->get(),
            'forwarders' => $account->forwarders()->with('domain')->orderBy('source')->get(),
            'autoresponders' => $account->autoresponders()->with('domain')->orderBy('mailbox')->get(),
            'lists' => $account->mailingLists()->with('domain')->orderBy('name')->get(),
            'catchalls' => EmailCatchall::whereIn('domain_id', $account->domains()->pluck('id'))
                ->get()->keyBy('domain_id'),
            'settings' => EmailSetting::whereIn('domain_id', $account->domains()->pluck('id'))
                ->get()->keyBy('domain_id'),
        ]);
    }

    /* ---- forwarders ---------------------------------------------------- */

    public function storeForwarder(ForwarderRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $this->policies->createForwarder(
                $account,
                $domain,
                $request->string('source')->value(),
                (array) $request->input('destinations', []),
                $request->boolean('keep_copy'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The forwarder was not created'));
        }

        return back()->with('status', 'Forwarder saved. Postfix was reloaded.');
    }

    public function destroyForwarder(EmailForwarder $forwarder): RedirectResponse
    {
        $this->authorize('delete', $forwarder);

        try {
            $this->policies->deleteForwarder($forwarder);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The forwarder was not removed'));
        }

        return back()->with('status', 'Forwarder removed.');
    }

    /* ---- autoresponders ------------------------------------------------ */

    public function storeAutoresponder(AutoresponderRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $this->policies->saveAutoresponder($account, $domain, [
                'mailbox' => $request->string('mailbox')->value(),
                'subject' => $request->string('subject')->value(),
                'body' => $request->string('body')->value(),
                'starts_at' => $request->input('starts_at'),
                'ends_at' => $request->input('ends_at'),
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The autoresponder was not saved'));
        }

        return back()->with('status', 'Autoresponder installed.');
    }

    public function destroyAutoresponder(Autoresponder $autoresponder): RedirectResponse
    {
        $this->authorize('delete', $autoresponder);

        try {
            $this->policies->deleteAutoresponder($autoresponder);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The autoresponder was not removed'));
        }

        return back()->with('status', 'Autoresponder removed.');
    }

    /* ---- catch-all ----------------------------------------------------- */

    public function storeCatchall(CatchallRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $this->policies->setCatchall($account, $domain, $request->string('destination')->value());
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The catch-all was not saved'));
        }

        return back()->with('status', "Catch-all set for {$domain->domain}.");
    }

    public function destroyCatchall(int $domainId): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail($domainId);

        try {
            $this->policies->removeCatchall($domain);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The catch-all was not removed'));
        }

        return back()->with('status', "Catch-all removed for {$domain->domain}.");
    }

    /* ---- policy, DKIM, lists ------------------------------------------- */

    public function storeSettings(EmailSettingsRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $this->policies->saveSettings($account, $domain, $request->validated());
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The policy was not saved'));
        }

        return back()->with('status', "Mail policy saved for {$domain->domain}.");
    }

    public function dkim(int $domainId): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail($domainId);

        try {
            $record = $this->policies->ensureDkim($domain);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The DKIM key was not generated'));
        }

        return back()
            ->with('status', 'DKIM key generated. Publish the TXT record shown below.')
            ->with('dkim', [
                'record_name' => $record['record_name'] ?? 'atlas._domainkey.'.$domain->domain,
                'record_value' => $record['record_value'] ?? '',
            ]);
    }

    public function storeList(MailingListRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $domain = $account->domains()->findOrFail((int) $request->input('domain_id'));

        try {
            $list = $this->policies->createList(
                $account,
                $domain,
                $request->string('name')->value(),
                $request->input('title'),
                (array) $request->input('member_list', []),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The list was not created'));
        }

        return back()->with('status', "List {$list->address()} created.");
    }

    public function destroyList(MailingList $list): RedirectResponse
    {
        $this->authorize('delete', $list);

        try {
            $this->policies->deleteList($list);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The list was not removed'));
        }

        return back()->with('status', 'List removed.');
    }
}
