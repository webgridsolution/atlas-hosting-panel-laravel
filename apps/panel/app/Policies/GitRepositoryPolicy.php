<?php

namespace App\Policies;

use App\Models\GitRepository;
use App\Models\User;

class GitRepositoryPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, GitRepository $repository): bool
    {
        return $this->ownsAccount($user, $repository->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, GitRepository $repository): bool
    {
        return $this->ownsAccount($user, $repository->account, true);
    }

    public function delete(User $user, GitRepository $repository): bool
    {
        return $this->ownsAccount($user, $repository->account, true);
    }
}
