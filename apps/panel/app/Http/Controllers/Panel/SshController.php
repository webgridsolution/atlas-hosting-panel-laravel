<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\SshManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * SSH access.
 *
 * Eight actions: the screen, generate-or-import, authorise, deauthorise,
 * delete, download the private key once, and the on/off switch.
 *
 * The panel is deliberately not able to see a private key it did not just hand
 * over: `download` is the only path that reads one, it reads through the
 * agent's jailed file.download, and it is the customer's own key on their own
 * account. Nothing here stores what it reads.
 */
class SshController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly SshManager $ssh) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        $keys = [];
        $problem = null;

        try {
            $keys = $this->ssh->keys($account);
        } catch (Throwable $e) {
            // A missing ~/.ssh is not an error — it is an account that has never
            // used SSH. A real failure is shown, but the screen still renders so
            // the toggle and the explanation stay reachable.
            $problem = $e->getMessage();
        }

        $info = $this->ssh->serverInfo();

        // The exact line to paste into a terminal, built from the account and
        // the port the server is actually listening on.
        $port = (int) ($info['port'] ?? 22);
        $host = (string) (config('atlas.server.ip') ?: request()->getHost());

        $command = 'ssh -p '.$port.' '.$account->username.'@'.$host;

        return view('panel.ssh.index', [
            'account' => $account,
            'keys' => $keys,
            'info' => $info,
            'enabled' => (bool) ($account->ssh_enabled ?? false),
            'problem' => $problem,
            'command' => $command,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'type' => ['nullable', 'in:ed25519,rsa'],
            'bits' => ['nullable', 'integer', 'min:2048', 'max:8192'],
            'comment' => ['nullable', 'string', 'max:128'],
            'public' => ['nullable', 'string', 'max:8192'],
        ]);

        // The reserved names and the .pub suffix are refused in SshManager as
        // well, because a FormRequest is not a security boundary — the view can
        // be bypassed and so can this controller.
        try {
            $this->ssh->store($account, $data);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The key was not added: '.$e->getMessage());
        }

        $imported = trim((string) ($data['public'] ?? '')) !== '';

        return back()->with(
            'status',
            $imported
                ? 'Key imported. Authorise it below to allow it to log in.'
                : 'Keypair generated. Download the private key now — it is not shown again.'
        );
    }

    public function authorizeKey(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $name = (string) $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ])['name'];

        try {
            $this->ssh->authorize($account, $name);
        } catch (Throwable $e) {
            return back()->with('error', 'That key was not authorised: '.$e->getMessage());
        }

        return back()->with('status', 'Key authorised. It can log in as '.$account->username.'.');
    }

    public function deauthorizeKey(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $name = (string) $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ])['name'];

        try {
            $this->ssh->deauthorize($account, $name);
        } catch (Throwable $e) {
            return back()->with('error', 'That key was not deauthorised: '.$e->getMessage());
        }

        return back()->with('status', 'Key deauthorised. It can no longer log in.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $name = (string) $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ])['name'];

        try {
            $this->ssh->remove($account, $name);
        } catch (Throwable $e) {
            return back()->with('error', 'The key was not deleted: '.$e->getMessage());
        }

        return back()->with('status', 'Key deleted.');
    }

    public function download(Request $request): StreamedResponse|RedirectResponse
    {
        $account = $this->requireAccount();
        $name = (string) $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ])['name'];

        try {
            $bytes = $this->ssh->privateKey($account, $name);
        } catch (Throwable $e) {
            return redirect()->route('panel.ssh.index')
                ->with('error', 'That key could not be downloaded: '.$e->getMessage());
        }

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            // A private key must not survive in a cache anywhere.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function access(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];

        try {
            $this->ssh->setAccess($account, $enabled);
        } catch (Throwable $e) {
            return back()->with('error', 'Shell access was not changed: '.$e->getMessage());
        }

        return back()->with(
            'status',
            $enabled
                ? 'Shell access enabled. Only authorised keys can log in.'
                : 'Shell access disabled. Existing keys are kept.'
        );
    }
}
