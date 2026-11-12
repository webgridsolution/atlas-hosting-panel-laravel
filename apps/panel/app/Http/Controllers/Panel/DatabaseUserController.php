<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Agent\Agent;
use App\Domain\Hosting\HostingFailure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\Hosting\DatabaseUserRequest;
use App\Http\Requests\Hosting\RemoteMysqlRequest;
use App\Models\DatabaseUser;
use App\Models\RemoteMysqlHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Database users, kept separate from the account's own MySQL user so an
 * application can be given exactly the grants it needs — and nothing else.
 */
class DatabaseUserController extends Controller
{
    use ResolvesAccount;

    /** Panel choice => real grant. */
    private const PRIVILEGE_MAP = [
        'ALL' => 'ALL PRIVILEGES',
        'READ' => 'SELECT',
        'WRITE' => 'SELECT, INSERT, UPDATE, DELETE',
    ];

    public function __construct(private readonly Agent $agent) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.databases.users', [
            'account' => $account,
            'users' => $account->databaseUsers()->with('databases')->orderBy('username')->get(),
            'databases' => $account->databases()->orderBy('name')->get(),
            'remote' => $account->remoteMysqlHosts()->orderBy('host')->get(),
            'privileges' => self::PRIVILEGE_MAP,
        ]);
    }

    public function store(DatabaseUserRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $databases = $account->databases()
            ->whereIn('id', (array) $request->input('databases', []))
            ->pluck('name')
            ->all();

        if ($databases === []) {
            return back()->withInput()->with('error', 'Choose at least one of your databases.');
        }

        $name = strtolower($account->username).'_'.strtolower($request->string('name')->value());
        $name = substr(preg_replace('/[^a-z0-9_]/', '', $name) ?? '', 0, 32);

        // Refused before the agent touches MySQL. A duplicate would otherwise
        // create the grant server-side and then fail on our unique index,
        // leaving the two systems disagreeing.
        if ($account->databaseUsers()->where('username', $name)->exists()) {
            return back()->withInput()->withErrors([
                'name' => "{$name} already exists. Choose a different name.",
            ]);
        }

        $password = str()->password(20, symbols: false);
        $privileges = (string) $request->input('privileges', 'ALL');

        try {
            $this->agent->call('db.user.create', [
                'username' => $name,
                'password' => $password,
                'privileges' => self::PRIVILEGE_MAP[$privileges] ?? 'ALL PRIVILEGES',
                'databases' => $databases,
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'The database user was not created'));
        }

        DB::transaction(function () use ($account, $name, $privileges, $request) {
            $user = DatabaseUser::create([
                'account_id' => $account->id,
                'username' => $name,
                'host' => 'localhost',
                'privileges' => $privileges,
                'status' => 'active',
            ]);

            $user->databases()->sync((array) $request->input('databases', []));
        });

        return back()->with('status', "Database user {$name} created. Password: {$password} — copy it now.");
    }

    public function password(DatabaseUser $dbUser): RedirectResponse
    {
        $this->authorize('update', $dbUser);

        $password = str()->password(20, symbols: false);

        try {
            $this->agent->call('db.user.password', [
                'username' => $dbUser->username,
                'password' => $password,
            ]);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The password was not changed'));
        }

        return back()->with('status', "New password for {$dbUser->username}: {$password} — copy it now.");
    }

    public function destroy(DatabaseUser $dbUser): RedirectResponse
    {
        $this->authorize('delete', $dbUser);

        try {
            $this->agent->call('db.user.drop', ['username' => $dbUser->username]);
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'The database user was not removed'));
        }

        $dbUser->databases()->detach();
        $dbUser->delete();

        return back()->with('status', 'Database user removed.');
    }

    public function storeRemote(RemoteMysqlRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $host = $request->string('host')->value();

        // A remote rule is meaningless without a user to attach it to.
        $dbUser = $account->databaseUsers()->first();

        if (! $dbUser) {
            return back()->withInput()->with('error', 'Create a database user first — remote access is granted per user.');
        }

        try {
            $this->agent->call('db.remote.set', [
                'host' => $host,
                'db_user' => $dbUser->username,
                'allow' => true,
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', HostingFailure::message($e, 'Remote access was not granted'));
        }

        RemoteMysqlHost::updateOrCreate(
            ['account_id' => $account->id, 'host' => $host],
            ['label' => $request->input('label')],
        );

        return back()->with('status', "{$host} may now connect as {$dbUser->username}.");
    }

    public function destroyRemote(RemoteMysqlHost $remote): RedirectResponse
    {
        $this->authorize('delete', $remote);

        $dbUser = $remote->account?->databaseUsers()->first();

        try {
            if ($dbUser) {
                $this->agent->call('db.remote.set', [
                    'host' => $remote->host,
                    'db_user' => $dbUser->username,
                    'allow' => false,
                ]);
            }
        } catch (Throwable $e) {
            return back()->with('error', HostingFailure::message($e, 'Remote access was not revoked'));
        }

        $remote->delete();

        return back()->with('status', 'Remote host removed.');
    }
}
