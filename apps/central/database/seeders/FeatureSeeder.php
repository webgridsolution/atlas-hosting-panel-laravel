<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /** Canonical registry. Panel modules gate on these keys — never on plan names. */
    public const FEATURES = [
        ['key' => 'max_accounts', 'label' => 'Hosting accounts', 'category' => 'Capacity', 'type' => 'limit', 'sort_order' => 10],
        ['key' => 'max_domains', 'label' => 'Domains', 'category' => 'Capacity', 'type' => 'limit', 'sort_order' => 20],
        ['key' => 'max_databases', 'label' => 'Databases', 'category' => 'Capacity', 'type' => 'limit', 'sort_order' => 30],
        ['key' => 'max_mailboxes', 'label' => 'Mailboxes', 'category' => 'Capacity', 'type' => 'limit', 'sort_order' => 40],
        ['key' => 'max_ftp_accounts', 'label' => 'FTP accounts', 'category' => 'Capacity', 'type' => 'limit', 'sort_order' => 50],
        ['key' => 'max_cron_jobs', 'label' => 'Cron jobs', 'category' => 'Capacity', 'type' => 'limit', 'sort_order' => 60],
        ['key' => 'reseller_access', 'label' => 'Reseller management', 'category' => 'Platform', 'type' => 'boolean', 'sort_order' => 70],
        ['key' => 'api_access', 'label' => 'Panel API access', 'category' => 'Platform', 'type' => 'boolean', 'sort_order' => 80],
        ['key' => 'white_label', 'label' => 'White-label branding', 'category' => 'Platform', 'type' => 'boolean', 'sort_order' => 90],
        ['key' => 'backup_schedules', 'label' => 'Scheduled backup jobs', 'category' => 'Backup & security', 'type' => 'limit', 'sort_order' => 100],
        ['key' => 'remote_backup', 'label' => 'Remote backup targets', 'category' => 'Backup & security', 'type' => 'boolean', 'sort_order' => 110],
        ['key' => 'security_scanner', 'label' => 'Malware scanning', 'category' => 'Backup & security', 'type' => 'boolean', 'sort_order' => 120],
        ['key' => 'app_installer', 'label' => 'One-click app installer', 'category' => 'Apps & developer', 'type' => 'boolean', 'sort_order' => 130],
        ['key' => 'git_deploy', 'label' => 'Git deployment', 'category' => 'Apps & developer', 'type' => 'boolean', 'sort_order' => 140],
    ];

    public function run(): void
    {
        foreach (self::FEATURES as $feature) {
            Feature::updateOrCreate(['key' => $feature['key']], $feature);
        }
    }
}
