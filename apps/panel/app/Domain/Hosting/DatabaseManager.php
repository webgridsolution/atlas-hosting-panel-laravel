<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\HostingDatabase;
use Illuminate\Support\Str;

class DatabaseManager
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * Creates a database with its own user, so two accounts never share MySQL
     * credentials. The plaintext password is returned once for display and
     * never stored.
     *
     * @return array{0: HostingDatabase, 1: string}
     */
    public function create(Account $account, string $name, ?string $password = null): array
    {
        $password ??= Str::password(20, symbols: false);
        $dbUser = Str::limit($name, 24, '');

        $this->audit->dispatch('db.create', [
            'name' => $name,
            'username' => $dbUser,
            'password' => $password,
        ], $account);

        $row = $account->databases()->create([
            'name' => $name,
            'db_user' => $dbUser,
        ]);

        return [$row, $password];
    }

    public function drop(HostingDatabase $database): void
    {
        $this->audit->dispatch('db.drop', [
            'name' => $database->name,
            'username' => $database->db_user,
        ], $database->account);

        $database->delete();
    }

    /** Resets the password of the database's dedicated user. */
    public function resetPassword(HostingDatabase $database): string
    {
        $password = Str::password(20, symbols: false);

        $this->audit->dispatch('db.create', [
            'name' => $database->name,
            'username' => $database->db_user,
            'password' => $password,
        ], $database->account);

        return $password;
    }
}
