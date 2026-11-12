<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\FtpAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FTP/SFTP logins. Each one is confined to a directory inside the account home
 * and carries its own quota; the panel never stores the password, the agent
 * owns the credential and the panel returns it once.
 */
class FtpManager
{
    public function __construct(private readonly Agent $agent) {}

    /** @return array{0: FtpAccount, 1: string} */
    public function create(Account $account, string $name, ?string $directory, int $quotaMb, bool $readOnly): array
    {
        $username = $this->usernameFor($account, $name);

        if (FtpAccount::where('username', $username)->exists()) {
            throw new RuntimeException("An FTP account called {$username} already exists.");
        }

        $home = $directory ?: $account->home().'/public_html';
        $password = str()->password(20, symbols: false);

        $this->agent->call('ftp.account.create', [
            'username' => $account->username,
            'ftp_username' => $username,
            'password' => $password,
            'home_dir' => $home,
            'quota_mb' => $quotaMb,
            'read_only' => $readOnly,
        ]);

        $row = DB::transaction(fn () => FtpAccount::create([
            'account_id' => $account->id,
            'domain_id' => $account->domains()->where('document_root', $home)->value('id'),
            'username' => $username,
            'home_dir' => $home,
            'quota_mb' => $quotaMb,
            'read_only' => $readOnly,
            'status' => 'active',
        ]));

        return [$row, $password];
    }

    public function resetPassword(FtpAccount $ftp): string
    {
        $password = str()->password(20, symbols: false);

        $this->agent->call('ftp.account.password', [
            'username' => $ftp->account->username,
            'ftp_username' => $ftp->username,
            'password' => $password,
        ]);

        return $password;
    }

    public function remove(FtpAccount $ftp): void
    {
        $this->agent->call('ftp.account.drop', [
            'username' => $ftp->account->username,
            'ftp_username' => $ftp->username,
        ]);

        $ftp->delete();
    }

    private function usernameFor(Account $account, string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $name) ?? '');

        return substr($account->username.'_'.$slug, 0, 32);
    }
}
