<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\Domain;
use App\Models\GitRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Git repositories inside a document root, and their deploys. */
class DeveloperManager
{
    public function __construct(private readonly Agent $agent) {}

    public function create(Account $account, Domain $domain, string $path, ?string $remote, string $branch): GitRepository
    {
        $path = trim($path, '/');
        $absolute = $domain->document_root.($path === '' ? '' : '/'.$path);

        if (GitRepository::where('account_id', $account->id)->where('path', $absolute)->exists()) {
            throw new RuntimeException('There is already a repository at that path.');
        }

        $this->agent->call('git.init', [
            'username' => $account->username,
            'path' => $absolute,
            'remote' => $remote ?? '',
            'branch' => $branch,
        ]);

        return DB::transaction(fn () => GitRepository::create([
            'account_id' => $account->id,
            'domain_id' => $domain->id,
            'path' => $absolute,
            'remote' => $remote,
            'branch' => $branch,
            'status' => 'active',
        ]));
    }

    /** @return array<string, mixed> */
    public function deploy(GitRepository $repository): array
    {
        $result = $this->agent->call('git.pull', [
            'username' => $repository->account->username,
            'path' => $repository->path,
            'branch' => $repository->branch,
        ]);

        $repository->update(['last_deploy_at' => now()]);

        return $result;
    }

    public function remove(GitRepository $repository): void
    {
        $repository->delete();
    }
}
