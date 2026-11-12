<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\DatabaseManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\StoreDatabaseRequest;
use App\Models\HostingDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class DatabaseController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly DatabaseManager $databases) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.databases.index', [
            'account' => $account,
            'databases' => $account->databases()->latest()->get(),
            'limit' => $account->package?->max_databases,
        ]);
    }

    public function store(StoreDatabaseRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $limit = $account->package?->max_databases;

        if ($limit !== null && $account->databases()->count() >= $limit) {
            return back()->with('error', "Your package allows {$limit} database(s).");
        }

        try {
            [$database, $password] = $this->databases->create($account, $request->string('name')->value());
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The database was not created: '.$e->getMessage());
        }

        return back()->with('status', "Database {$database->name} created. User: {$database->db_user} · Password: {$password} — copy it now, it is not shown again.");
    }

    public function password(HostingDatabase $database): RedirectResponse
    {
        $this->authorize('delete', $database); // same ownership rule

        try {
            $password = $this->databases->resetPassword($database);
        } catch (Throwable $e) {
            return back()->with('error', 'The password was not changed: '.$e->getMessage());
        }

        return back()->with('status', "New password for {$database->db_user}: {$password} — copy it now.");
    }

    public function destroy(HostingDatabase $database): RedirectResponse
    {
        $this->authorize('delete', $database);

        try {
            $this->databases->drop($database);
        } catch (Throwable $e) {
            return back()->with('error', 'The database was not dropped: '.$e->getMessage());
        }

        return back()->with('status', "Database {$database->name} dropped.");
    }
}
