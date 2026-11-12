<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Autoresponder;
use App\Models\BandwidthDay;
use App\Models\DatabaseUser;
use App\Models\DirectoryProtection;
use App\Models\Domain;
use App\Models\EmailCatchall;
use App\Models\EmailFilter;
use App\Models\EmailForwarder;
use App\Models\EmailSetting;
use App\Models\FtpAccount;
use App\Models\InstalledApplication;
use App\Models\IpBlock;
use App\Models\LoginEvent;
use App\Models\MailingList;
use App\Models\MimeType;
use App\Models\PhpSetting;
use App\Models\Redirect;
use App\Models\SslCertificate;
use Illuminate\Database\Seeder;

/**
 * Demo content for every hosting-panel screen that is otherwise empty.
 *
 * The point is that a reviewer can open each of the thirty-odd screens and see
 * a populated, coherent state rather than an empty table. Every row here would
 * be produced by the corresponding service on a real VPS; the seeder writes
 * them directly only because the sandbox agent has nothing to configure.
 */
class HostingFeatureSeeder extends Seeder
{
    public function run(): void
    {
        $acme = Account::where('username', 'acme')->first();

        if (! $acme) {
            return;
        }

        $primary = $acme->domains()->where('is_primary', true)->first()
            ?? $acme->domains()->first();

        if (! $primary) {
            return;
        }

        $this->domains($acme, $primary);
        $this->databases($acme);
        $this->mail($acme, $primary);
        $this->security($acme);
        $this->webconfig($acme, $primary);
        $this->ftp($acme, $primary);
        $this->apps($acme, $primary);
        $this->ssl($acme, $primary);
        $this->observability($acme);
    }

    private function domains(Account $acme, Domain $primary): void
    {
        $shop = Domain::firstOrCreate(
            ['domain' => 'shop.acme.test'],
            [
                'account_id' => $acme->id, 'type' => Domain::TYPE_ADDON,
                'document_root' => $acme->home().'/shop.acme.test',
                'is_primary' => false, 'php_version' => '8.3', 'status' => 'active',
                'mail_enabled' => true, 'provisioned_at' => now()->subDays(40),
                'ssl_status' => Domain::SSL_ACTIVE, 'ssl_expires_at' => now()->addDays(64),
                'ssl_issuer' => "Let's Encrypt",
            ],
        );

        Domain::firstOrCreate(
            ['domain' => 'blog.acme.test'],
            [
                'account_id' => $acme->id, 'type' => Domain::TYPE_SUBDOMAIN,
                'parent_id' => $primary->id, 'label' => 'blog',
                'document_root' => $acme->home().'/blog.acme.test',
                'is_primary' => false, 'php_version' => '8.3', 'status' => 'active',
                'provisioned_at' => now()->subDays(21),
                'ssl_status' => Domain::SSL_PENDING,
            ],
        );

        Domain::firstOrCreate(
            ['domain' => 'acme-store.test'],
            [
                'account_id' => $acme->id, 'type' => Domain::TYPE_PARKED,
                'parent_id' => $primary->id,
                'document_root' => $primary->document_root,
                'is_primary' => false, 'php_version' => '8.3', 'status' => 'active',
                'provisioned_at' => now()->subDays(12),
                'ssl_status' => Domain::SSL_ACTIVE, 'ssl_expires_at' => now()->addDays(58),
                'ssl_issuer' => "Let's Encrypt",
            ],
        );

        Redirect::firstOrCreate(
            ['domain_id' => $primary->id, 'source_path' => '/old-shop'],
            [
                'account_id' => $acme->id,
                'target_url' => 'https://shop.acme.test/',
                'status_code' => 301, 'hits' => 1240, 'is_active' => true,
            ],
        );

        Redirect::firstOrCreate(
            ['domain_id' => $shop->id, 'source_path' => '/sale'],
            [
                'account_id' => $acme->id,
                'target_url' => 'https://shop.acme.test/offers',
                'status_code' => 302, 'hits' => 88, 'is_active' => true,
            ],
        );
    }

    private function databases(Account $acme): void
    {
        $db = $acme->databases()->first();

        if (! $db) {
            return;
        }

        $user = DatabaseUser::firstOrCreate(
            ['username' => $acme->username.'_app'],
            [
                'account_id' => $acme->id,
                'host' => 'localhost',
                'privileges' => 'WRITE',
                'status' => 'active',
            ],
        );

        $user->databases()->syncWithoutDetaching([$db->id]);

        DatabaseUser::firstOrCreate(
            ['username' => $acme->username.'_ro'],
            [
                'account_id' => $acme->id,
                'host' => 'localhost',
                'privileges' => 'READ',
                'status' => 'active',
            ],
        );
    }

    private function mail(Account $acme, Domain $primary): void
    {
        EmailForwarder::firstOrCreate(
            ['domain_id' => $primary->id, 'source' => 'sales'],
            [
                'account_id' => $acme->id,
                'destination' => 'orders@acme.test, support@acme.test',
                'keep_copy' => true, 'is_active' => true,
            ],
        );

        Autoresponder::firstOrCreate(
            ['domain_id' => $primary->id, 'mailbox' => 'support'],
            [
                'account_id' => $acme->id,
                'subject' => 'We have your message',
                'body' => "Thanks for writing to Acme Traders.\nWe answer within one working day.\n\n— The support team",
                'starts_at' => now()->subDays(3),
                'ends_at' => null,
                'is_active' => true,
            ],
        );

        EmailCatchall::firstOrCreate(
            ['domain_id' => $primary->id],
            ['account_id' => $acme->id, 'destination' => 'orders@acme.test'],
        );

        EmailSetting::firstOrCreate(
            ['domain_id' => $primary->id],
            [
                'account_id' => $acme->id,
                'spam_threshold' => '5.0',
                'spam_action' => 'mark',
                'dkim_enabled' => true,
                'spf_enabled' => true,
                'greylisting' => false,
                'mailbox_quota_mb' => 2048,
            ],
        );

        if ($acme->emailFilters()->count() === 0) {
            $rules = [
                ['field' => 'from', 'operator' => 'contains', 'value' => 'no-reply@marketing', 'action' => 'discard', 'priority' => 10],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice', 'action' => 'move', 'action_arg' => 'Invoices', 'priority' => 20],
                ['field' => 'x-spam-flag', 'operator' => 'equals', 'value' => 'YES', 'action' => 'mark_spam', 'priority' => 5],
            ];

            foreach ($rules as $rule) {
                EmailFilter::create([
                    'account_id' => $acme->id,
                    'domain_id' => $primary->id,
                    'mailbox' => 'orders@acme.test',
                    'field' => $rule['field'],
                    'operator' => $rule['operator'],
                    'value' => $rule['value'],
                    'action' => $rule['action'],
                    'action_arg' => $rule['action_arg'] ?? null,
                    'priority' => $rule['priority'],
                    'is_active' => true,
                ]);
            }
        }

        MailingList::firstOrCreate(
            ['domain_id' => $primary->id, 'name' => 'announce'],
            [
                'account_id' => $acme->id,
                'title' => 'Customer announcements',
                'status' => 'active',
                'subscribers' => 148,
            ],
        );
    }

    private function security(Account $acme): void
    {
        IpBlock::firstOrCreate(
            ['account_id' => $acme->id, 'cidr' => '198.51.100.23'],
            ['reason' => 'repeated credential stuffing', 'expires_at' => now()->addDays(30)],
        );

        IpBlock::firstOrCreate(
            ['account_id' => $acme->id, 'cidr' => '203.0.113.0/24'],
            ['reason' => 'scraper range', 'expires_at' => null],
        );

        DirectoryProtection::firstOrCreate(
            ['account_id' => $acme->id, 'path' => '/public_html/admin'],
            [
                'realm' => 'Acme staff area',
                'username' => 'staff',
                'status' => 'active',
                'last_used_at' => now()->subHours(6),
            ],
        );
    }

    private function webconfig(Account $acme, Domain $primary): void
    {
        foreach ([
            ['extension' => 'webp', 'mime_type' => 'image/webp'],
            ['extension' => 'avif', 'mime_type' => 'image/avif'],
            ['extension' => 'woff2', 'mime_type' => 'font/woff2'],
        ] as $mime) {
            MimeType::firstOrCreate(
                ['account_id' => $acme->id, 'extension' => $mime['extension']],
                ['mime_type' => $mime['mime_type']],
            );
        }

        foreach ([
            ['key' => 'memory_limit', 'value' => '256M'],
            ['key' => 'upload_max_filesize', 'value' => '64M'],
            ['key' => 'post_max_size', 'value' => '64M'],
            ['key' => 'max_execution_time', 'value' => '120'],
        ] as $setting) {
            PhpSetting::updateOrCreate(
                ['account_id' => $acme->id, 'domain_id' => $primary->id, 'key' => $setting['key']],
                ['value' => $setting['value']],
            );
        }
    }

    private function ftp(Account $acme, Domain $primary): void
    {
        FtpAccount::firstOrCreate(
            ['username' => $acme->username.'_deploy'],
            [
                'account_id' => $acme->id,
                'domain_id' => $primary->id,
                'home_dir' => $primary->document_root,
                'quota_mb' => 2048,
                'status' => 'active',
                'read_only' => false,
                'last_login_at' => now()->subHours(9),
                'last_login_ip' => '203.0.113.44',
            ],
        );

        FtpAccount::firstOrCreate(
            ['username' => $acme->username.'_readonly'],
            [
                'account_id' => $acme->id,
                'domain_id' => $primary->id,
                'home_dir' => $acme->home().'/backups',
                'quota_mb' => 0,
                'status' => 'active',
                'read_only' => true,
                'last_login_at' => null,
            ],
        );
    }

    private function apps(Account $acme, Domain $primary): void
    {
        InstalledApplication::firstOrCreate(
            ['domain_id' => $primary->id, 'slug' => 'wordpress', 'path' => 'blog'],
            [
                'account_id' => $acme->id,
                'version' => '6.7',
                'url' => 'https://'.$primary->domain.'/blog',
                'admin_user' => 'acme_admin',
                'db_name' => $acme->databases()->first()?->name,
                'status' => 'installed',
                'installed_at' => now()->subDays(35),
                'checked_at' => now()->subHours(2),
            ],
        );
    }

    private function ssl(Account $acme, Domain $primary): void
    {
        SslCertificate::firstOrCreate(
            ['common_name' => $primary->domain, 'kind' => 'letsencrypt'],
            [
                'account_id' => $acme->id,
                'domain_id' => $primary->id,
                'issuer' => "Let's Encrypt R3",
                'issued_at' => now()->subDays(27),
                'expires_at' => now()->addDays(63),
                'status' => 'active',
                'san' => $primary->domain."\nwww.".$primary->domain,
            ],
        );
    }

    private function observability(Account $acme): void
    {
        // 30 days of plausible traffic so the chart and the breakdown table are
        // not empty on a fresh install. The shape is deterministic.
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $base = 40 + (int) (25 * sin($i / 3.4)) + ($i % 7) * 3;

            BandwidthDay::updateOrCreate(
                ['account_id' => $acme->id, 'day' => $day],
                [
                    'http_mb' => max(0, $base),
                    'mail_mb' => max(0, (int) ($base / 8)),
                    'ftp_mb' => max(0, (int) ($base / 20)),
                ],
            );
        }

        if (LoginEvent::where('account_id', $acme->id)->count() === 0) {
            $samples = [
                ['ip' => '203.0.113.44', 'ua' => 'Mozilla/5.0 (Macintosh) Firefox/131', 'ok' => true, 'hours' => 2],
                ['ip' => '203.0.113.44', 'ua' => 'Mozilla/5.0 (Macintosh) Firefox/131', 'ok' => true, 'hours' => 26],
                ['ip' => '198.51.100.23', 'ua' => 'curl/8.6.0', 'ok' => false, 'hours' => 40],
                ['ip' => '198.51.100.23', 'ua' => 'curl/8.6.0', 'ok' => false, 'hours' => 41],
                ['ip' => '203.0.113.44', 'ua' => 'Mozilla/5.0 (iPhone) Safari/605', 'ok' => true, 'hours' => 52],
            ];

            foreach ($samples as $sample) {
                LoginEvent::create([
                    'user_id' => $acme->user_id,
                    'account_id' => $acme->id,
                    'area' => 'panel',
                    'email' => 'acme@atlas.test',
                    'ip' => $sample['ip'],
                    'user_agent' => $sample['ua'],
                    'successful' => $sample['ok'],
                    'reason' => $sample['ok'] ? null : 'invalid password',
                    'created_at' => now()->subHours($sample['hours']),
                ]);
            }
        }
    }
}
