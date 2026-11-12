<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\Domain;
use App\Models\ErrorPage;
use App\Models\MimeType;
use App\Models\PhpSetting;
use Illuminate\Support\Facades\DB;

/**
 * Per-site web server and PHP configuration: MIME overrides, error documents,
 * PHP directives and the PHP version itself.
 */
class WebConfigManager
{
    public function __construct(private readonly Agent $agent) {}

    public function addMime(Account $account, string $extension, string $mimeType): MimeType
    {
        $extension = strtolower(ltrim(trim($extension), '.'));

        $this->agent->call('mime.write', [
            'username' => $account->username,
            'extension' => $extension,
            'mime_type' => $mimeType,
        ]);

        return DB::transaction(fn () => MimeType::updateOrCreate(
            ['account_id' => $account->id, 'extension' => $extension],
            ['mime_type' => $mimeType],
        ));
    }

    public function removeMime(MimeType $mime): void
    {
        $this->agent->call('mime.remove', [
            'username' => $mime->account->username,
            'extension' => $mime->extension,
        ]);

        $mime->delete();
    }

    public function saveErrorPage(Account $account, Domain $domain, int $status, string $body): ErrorPage
    {
        $this->agent->call('errorpage.write', [
            'username' => $account->username,
            'domain' => $domain->domain,
            'status' => $status,
            'body' => $body,
        ]);

        return DB::transaction(fn () => ErrorPage::updateOrCreate(
            ['domain_id' => $domain->id, 'status_code' => $status],
            ['account_id' => $account->id, 'body' => $body],
        ));
    }

    /**
     * Switches the PHP version and saves only the directives a customer is
     * allowed to set. Anything else is dropped rather than passed through to a
     * pool that would refuse to start.
     *
     * @param  array<string, string>  $settings
     */
    public function savePhp(Account $account, Domain $domain, string $version, array $settings): void
    {
        $wanted = array_intersect_key($settings, array_flip(PhpSetting::EDITABLE));
        $wanted = array_filter($wanted, fn ($v) => $v !== null && $v !== '');

        $this->agent->call('php.settings.write', [
            'username' => $account->username,
            'domain' => $domain->domain,
            'version' => $version,
            'settings' => $wanted,
        ]);

        DB::transaction(function () use ($account, $domain, $version, $wanted) {
            if ($version !== $domain->effectivePhpVersion()) {
                // The certificate binding lives in the vhost, so it is carried
                // across the rewrite rather than dropped.
                $this->agent->call('vhost.php.set', [
                    'username' => $account->username,
                    'domain' => $domain->domain,
                    'php_version' => $version,
                    'keep_ssl' => $domain->ssl_status === Domain::SSL_ACTIVE,
                ]);

                $domain->update(['php_version' => $version]);
            }

            PhpSetting::where('account_id', $account->id)
                ->where('domain_id', $domain->id)
                ->whereNotIn('key', array_keys($wanted))
                ->delete();

            foreach ($wanted as $key => $value) {
                PhpSetting::updateOrCreate(
                    ['domain_id' => $domain->id, 'key' => $key],
                    ['account_id' => $account->id, 'value' => $value],
                );
            }
        });
    }

    /** @return list<array{name: string, enabled: bool}> */
    public function extensions(string $phpVersion): array
    {
        $result = $this->agent->call('php.extensions', ['version' => $phpVersion]);

        return array_map(
            fn ($row) => ['name' => (string) ($row['name'] ?? ''), 'enabled' => (bool) ($row['enabled'] ?? true)],
            (array) ($result['extensions'] ?? []),
        );
    }
}
