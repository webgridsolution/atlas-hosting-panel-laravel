<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\ApiTokenRequest;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Personal API tokens. The plaintext is shown once and only its SHA-256 is
 * stored, so a database dump cannot be replayed against the API.
 */
class ApiTokenController extends Controller
{
    use ResolvesAccount;

    public function index(): View
    {
        return view('panel.security.tokens', [
            'account' => $this->requireAccount(),
            'tokens' => ApiToken::where('user_id', auth()->id())->latest()->get(),
        ]);
    }

    public function store(ApiTokenRequest $request): RedirectResponse
    {
        $plain = 'atlas_'.bin2hex(random_bytes(24));

        ApiToken::create([
            'user_id' => auth()->id(),
            'account_id' => $this->account()?->id,
            'name' => $request->string('name')->value(),
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 12),
            'abilities' => array_values((array) $request->input('abilities', ['read'])),
            'expires_at' => now()->addDays((int) $request->input('expires_in_days', 90)),
        ]);

        return back()->with('status', "Token created. Copy it now, it is not shown again: {$plain}");
    }

    public function destroy(ApiToken $token): RedirectResponse
    {
        abort_unless($token->user_id === auth()->id(), 403);

        $token->delete();

        return back()->with('status', 'Token revoked. Scripts using it will stop working.');
    }
}
