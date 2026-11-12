<?php

namespace App\Http\Controllers\Whm;

use App\Domain\Licensing\LicenseManager;
use App\Http\Controllers\Controller;
use App\Models\Licence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function __construct(private readonly LicenseManager $licences) {}

    public function index(): View
    {
        return view('whm.licence.index', [
            'licence' => $this->licences->current(),
            'state' => $this->licences->evaluate(),
            'graceDays' => $this->licences->graceDays(),
        ]);
    }

    /**
     * Stores a token pasted by the operator. The token is verified before it
     * is saved: an invalid licence on disk would put the whole panel into
     * read-only mode by accident.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4000'],
        ]);

        $claim = $this->licences->verify(trim($data['token']));

        if (is_string($claim)) {
            return back()->with('error', $claim);
        }

        $this->licences->installVerifiedToken(trim($data['token']), $claim);

        return back()->with('status', 'Licence accepted. State: '.$this->licences->evaluate()['state']);
    }

    /**
     * Activates a raw key with the vendor, then stores the signed token it
     * returns. The key itself is never trusted locally: if the vendor is down
     * or refuses, nothing changes on this server.
     */
    public function activate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $result = $this->licences->activate(trim($data['key']));

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function refresh(): RedirectResponse
    {
        $ok = $this->licences->refresh();
        cache()->forget('licence:evaluate');

        if ($ok) {
            return back()->with('status', 'Checked with the central service. State: '.$this->licences->evaluate()['state']);
        }

        return back()->with(
            'error',
            $this->licences->current()->last_error
                ?: 'The central service could not be reached. The panel keeps running on the last known-good licence.',
        );
    }
}
