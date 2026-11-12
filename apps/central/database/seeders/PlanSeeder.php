<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Default catalogue. These are seed values, not constants — the admin console
     * edits the same rows and nothing in the application hardcodes them.
     */
    public const PLANS = [
        [
            'slug' => 'personal', 'name' => 'Personal', 'edition' => 'personal',
            'tagline' => 'One VPS, one site owner. Everything you need to self-host.',
            'is_featured' => false, 'sort_order' => 10,
            'inr' => 499, 'usd' => 6,
            'entitlements' => [
                'max_accounts' => 1, 'max_domains' => 5, 'max_databases' => 5, 'max_mailboxes' => 10,
                'max_ftp_accounts' => 3, 'max_cron_jobs' => 10, 'reseller_access' => 0, 'api_access' => 0,
                'white_label' => 0, 'backup_schedules' => 1, 'remote_backup' => 0, 'security_scanner' => 0,
                'app_installer' => 1, 'git_deploy' => 0,
            ],
        ],
        [
            'slug' => 'professional', 'name' => 'Professional', 'edition' => 'professional',
            'tagline' => 'Small agencies and multi-site owners on a single server.',
            'is_featured' => true, 'sort_order' => 20,
            'inr' => 1499, 'usd' => 18,
            'entitlements' => [
                'max_accounts' => 50, 'max_domains' => 250, 'max_databases' => 250, 'max_mailboxes' => 500,
                'max_ftp_accounts' => 25, 'max_cron_jobs' => 100, 'reseller_access' => 0, 'api_access' => 1,
                'white_label' => 0, 'backup_schedules' => 3, 'remote_backup' => 1, 'security_scanner' => 1,
                'app_installer' => 1, 'git_deploy' => 1,
            ],
        ],
        [
            'slug' => 'business', 'name' => 'Business', 'edition' => 'business',
            'tagline' => 'Denser hosting on a bigger box, with API automation.',
            'is_featured' => false, 'sort_order' => 30,
            'inr' => 3999, 'usd' => 49,
            'entitlements' => [
                'max_accounts' => 250, 'max_domains' => 1000, 'max_databases' => 1000, 'max_mailboxes' => 2500,
                'max_ftp_accounts' => 100, 'max_cron_jobs' => 500, 'reseller_access' => 0, 'api_access' => 1,
                'white_label' => 0, 'backup_schedules' => 5, 'remote_backup' => 1, 'security_scanner' => 1,
                'app_installer' => 1, 'git_deploy' => 1,
            ],
        ],
        [
            'slug' => 'reseller', 'name' => 'Reseller', 'edition' => 'reseller',
            'tagline' => 'Sell hosting from your own panel with your own branding.',
            'is_featured' => false, 'sort_order' => 40,
            'inr' => 6999, 'usd' => 85,
            'entitlements' => [
                'max_accounts' => 500, 'max_domains' => 2500, 'max_databases' => 2500, 'max_mailboxes' => 5000,
                'max_ftp_accounts' => 250, 'max_cron_jobs' => 1000, 'reseller_access' => 1, 'api_access' => 1,
                'white_label' => 1, 'backup_schedules' => 10, 'remote_backup' => 1, 'security_scanner' => 1,
                'app_installer' => 1, 'git_deploy' => 1,
            ],
        ],
        [
            'slug' => 'enterprise', 'name' => 'Enterprise', 'edition' => 'enterprise',
            'tagline' => 'Uncapped capacity, priority support and custom terms.',
            'is_featured' => false, 'sort_order' => 50,
            'inr' => 19999, 'usd' => 249,
            'entitlements' => [
                'max_accounts' => -1, 'max_domains' => -1, 'max_databases' => -1, 'max_mailboxes' => -1,
                'max_ftp_accounts' => -1, 'max_cron_jobs' => -1, 'reseller_access' => 1, 'api_access' => 1,
                'white_label' => 1, 'backup_schedules' => 20, 'remote_backup' => 1, 'security_scanner' => 1,
                'app_installer' => 1, 'git_deploy' => 1,
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $definition) {
            $plan = Plan::updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'edition' => $definition['edition'],
                    'tagline' => $definition['tagline'],
                    'is_featured' => $definition['is_featured'],
                    'is_active' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );

            foreach ([['INR', $definition['inr']], ['USD', $definition['usd']]] as [$currency, $amount]) {
                $plan->prices()->updateOrCreate(
                    ['interval' => 'monthly', 'currency' => $currency],
                    ['amount_minor' => (int) round($amount * 100), 'is_active' => true],
                );
            }

            foreach ($definition['entitlements'] as $key => $value) {
                $plan->entitlements()->updateOrCreate(['feature_key' => $key], ['value' => $value]);
            }
        }
    }
}
