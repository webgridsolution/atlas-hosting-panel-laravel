<?php

namespace App\Policies;

use App\Models\SslCertificate;
use App\Models\User;

class SslCertificatePolicy
{
    use ChecksAccountAccess;

    public function view(User $user, SslCertificate $certificate): bool
    {
        return $this->ownsAccount($user, $certificate->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, SslCertificate $certificate): bool
    {
        return $this->ownsAccount($user, $certificate->account, true);
    }

    public function delete(User $user, SslCertificate $certificate): bool
    {
        return $this->ownsAccount($user, $certificate->account, true);
    }
}
