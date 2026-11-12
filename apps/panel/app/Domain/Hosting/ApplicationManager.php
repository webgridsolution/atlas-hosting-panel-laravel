<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\Domain;
use App\Models\InstalledApplication;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-click installs from the compiled-in catalogue.
 *
 * The same catalogue is enforced by the Go agent's registry, so a slug that is
 * not in it is refused on both sides. Each install gets its own database and
 * database user: two sites on one server never share credentials.
 */
class ApplicationManager
{
    public function __construct(private readonly Agent $agent) {}

    /** @return array<string, array<string, string>> */
    public function catalogue(): array
    {
        return (array) config('atlas.apps', []);
    }

    public function install(Account $account, Domain $domain, string $slug, string $path, string $adminUser, string $adminEmail): InstalledApplication
    {
        $catalogue = $this->catalogue();

        if (! isset($catalogue[$slug])) {
            throw new RuntimeException("{$slug} is not in the application catalog.");
        }

        $path = trim($path, '/');
        $target = $domain->document_root.($path === '' ? '' : '/'.$path);

        $dbName = $this->slugForDb($account, $slug, $path);
        $dbUser = substr($dbName, 0, 26).'_u';

        $result = $this->agent->call('app.install', [
            'username' => $account->username,
            'slug' => $slug,
            'domain' => $domain->domain,
            'document_root' => $domain->document_root,
            'path' => $path,
            'admin_user' => $adminUser,
            'admin_email' => $adminEmail,
            'db_name' => $dbName,
            'db_user' => $dbUser,
        ]);

        return DB::transaction(fn () => InstalledApplication::create([
            'account_id' => $account->id,
            'domain_id' => $domain->id,
            'slug' => $slug,
            'path' => $path,
            'version' => $result['version'] ?? ($catalogue[$slug]['version'] ?? null),
            'url' => $result['url'] ?? 'https://'.$domain->domain.($path === '' ? '' : '/'.$path),
            'admin_user' => $adminUser,
            'db_name' => $result['db_name'] ?? $dbName,
            'status' => 'installed',
            'installed_at' => now(),
            'checked_at' => now(),
        ]));
    }

    /** @return array<string, mixed> */
    public function check(InstalledApplication $application): array
    {
        $result = $this->agent->call('app.info', [
            'username' => $application->account->username,
            'slug' => $application->slug,
            'path' => $application->path,
        ]);

        $application->update([
            'checked_at' => now(),
            'version' => $result['version'] ?? $application->version,
        ]);

        return $result;
    }

    /** Removes the files. The database is kept, because it holds the content. */
    public function remove(InstalledApplication $application): void
    {
        $this->agent->call('app.remove', [
            'username' => $application->account->username,
            'slug' => $application->slug,
            'path' => $application->path,
        ]);

        $application->delete();
    }

    private function slugForDb(Account $account, string $slug, string $path): string
    {
        $tail = $path === '' ? '' : '_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($path));
        $name = strtolower($account->username.'_'.$slug.$tail);

        return substr(preg_replace('/[^a-z0-9_]/', '', $name) ?? '', 0, 32);
    }
}
