<?php

namespace App\Http\Controllers\Webmail;

use App\Domain\Mail\ImapClient;
use App\Domain\Mail\SmtpClient;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\SendMailRequest;
use App\Models\Mailbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use Throwable;

/**
 * Webmail for the account's own mailboxes.
 *
 * Credentials are the mailbox's, not the panel login's: a customer may have
 * five mailboxes and only the holder of each password can read it. The password
 * is kept encrypted in the session for the life of the login and never written
 * to the database.
 */
class InboxController extends Controller
{
    use ResolvesAccount;

    public function index(): View|RedirectResponse
    {
        $account = $this->requireAccount();
        $credentials = $this->credentials();

        if (! $credentials) {
            return view('webmail.login', [
                'account' => $account,
                'mailboxes' => $account->mailboxes()->orderBy('address')->get(),
            ]);
        }

        try {
            $client = $this->connect($credentials[0], $credentials[1]);
            $count = $client->inboxCount();
            $messages = $client->recent(25);
            $client->logout();
        } catch (Throwable $e) {
            $this->forget();

            return redirect()->route('webmail.inbox')->with('error', $e->getMessage());
        }

        return view('webmail.inbox', [
            'account' => $account,
            'address' => $credentials[0],
            'count' => $count,
            'messages' => $messages,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $data = $request->validate([
            'address' => ['required', 'email', 'max:320'],
            'password' => ['required', 'string', 'max:400'],
        ]);

        $owns = $account->mailboxes()->where('address', strtolower($data['address']))->exists();

        if (! $owns) {
            return back()->withErrors(['address' => 'That address is not one of your mailboxes.']);
        }

        try {
            $client = $this->connect(strtolower($data['address']), (string) $data['password']);
            $client->logout();
        } catch (Throwable $e) {
            return back()->withErrors(['address' => $e->getMessage()])->withInput($request->only('address'));
        }

        $request->session()->put('webmail.address', strtolower($data['address']));
        $request->session()->put('webmail.password', Crypt::encryptString((string) $data['password']));

        return redirect()->route('webmail.inbox');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->forget();

        return redirect()->route('webmail.inbox')->with('status', 'Signed out of webmail.');
    }

    public function show(string $uid): View|RedirectResponse
    {
        $credentials = $this->credentials();

        if (! $credentials) {
            return redirect()->route('webmail.inbox');
        }

        if (preg_match('/^\d+$/', $uid) !== 1) {
            abort(404);
        }

        try {
            $client = $this->connect($credentials[0], $credentials[1]);
            $message = $client->message($uid);
            $client->logout();
        } catch (Throwable $e) {
            return redirect()->route('webmail.inbox')->with('error', $e->getMessage());
        }

        return view('webmail.message', [
            'address' => $credentials[0],
            'uid' => $uid,
            'message' => $message,
        ]);
    }

    public function compose(): View|RedirectResponse
    {
        $credentials = $this->credentials();

        if (! $credentials) {
            return redirect()->route('webmail.inbox');
        }

        return view('webmail.compose', ['address' => $credentials[0]]);
    }

    public function send(SendMailRequest $request): RedirectResponse
    {
        $credentials = $this->credentials();

        if (! $credentials) {
            return redirect()->route('webmail.inbox');
        }

        try {
            (new SmtpClient(
                host: (string) config('atlas.mail.smtp_host'),
                port: (int) config('atlas.mail.smtp_port'),
            ))->send(
                $credentials[0],
                (string) $request->input('to'),
                (string) $request->input('subject'),
                (string) $request->input('body'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The message was not sent: '.$e->getMessage());
        }

        return redirect()->route('webmail.inbox')->with('status', 'Message accepted by the mail server.');
    }

    private function connect(string $address, string $password): ImapClient
    {
        return (new ImapClient(
            host: (string) config('atlas.mail.imap_host'),
            port: (int) config('atlas.mail.imap_port'),
        ))->connect()->login($address, $password);
    }

    /** @return array{0: string, 1: string}|null */
    private function credentials(): ?array
    {
        $address = session('webmail.address');
        $encrypted = session('webmail.password');

        if (! $address || ! $encrypted) {
            return null;
        }

        try {
            return [$address, Crypt::decryptString($encrypted)];
        } catch (Throwable) {
            $this->forget();

            return null;
        }
    }

    private function forget(): void
    {
        session()->forget(['webmail.address', 'webmail.password']);
    }
}
