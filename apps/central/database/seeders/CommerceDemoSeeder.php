<?php

namespace Database\Seeders;

use App\Domain\Billing\CheckoutService;
use App\Domain\Billing\DemoPaymentGateway;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PaymentRecorder;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development fixtures for the commerce, support and release screens.
 *
 * Every row is produced by the real services — an order goes through checkout,
 * a payment through the recorder (invoice, ledger, licence), a ticket through the
 * customer-facing model. Nothing is hand-written into the tables, so the demo
 * data cannot drift away from the code paths that create it.
 */
class CommerceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        Setting::put('company.name', 'Atlas Hosting Systems Pvt Ltd', 'company', 'string', 'Legal entity name');
        Setting::put('company.gstin', '20AABCA1234M1ZP', 'company', 'string', 'Seller GSTIN');
        Setting::put('company.address', '2nd Floor, Saraidhela, Dhanbad, Jharkhand 828127, India', 'company', 'string', 'Registered address');
        Setting::put('billing.seller_state', 'JH', 'billing', 'string', 'Seller state code');
        Setting::put('license.grace_days', '14', 'licensing', 'integer', 'Default licence grace (days)');
        Setting::put('support.email', 'support@atlas.example', 'support', 'string', 'Support inbox');

        /** @var DemoPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        if (! $gateway instanceof DemoPaymentGateway) {
            $this->command?->warn('CommerceDemoSeeder needs ATLAS_PAYMENTS_DRIVER=demo; skipping.');
        }

        $checkout = app(CheckoutService::class);
        $recorder = app(PaymentRecorder::class);

        $purchases = [
            ['email' => 'owner@atlas.test', 'plan' => 'professional', 'interval' => 'annual'],
            ['email' => 'priya@example.com', 'plan' => 'business', 'interval' => 'monthly'],
            ['email' => 'daniel@example.com', 'plan' => 'personal', 'interval' => 'monthly'],
        ];

        foreach ($purchases as $row) {
            $customer = Customer::whereHas('user', fn ($q) => $q->where('email', $row['email']))->first();

            if (! $customer || $customer->orders()->exists()) {
                continue;
            }

            $plan = Plan::with('prices')->where('slug', $row['plan'])->firstOrFail();

            $order = $checkout->start($customer, $plan, $row['interval'], 'INR');
            $payment = $gateway->simulateCapture((string) $order->gateway_order_id);
            $recorder->capture($order, $payment);
        }

        $owner = Customer::whereHas('user', fn ($q) => $q->where('email', 'owner@atlas.test'))->first();

        if ($owner && ! $owner->tickets()->exists()) {
            $ticket = SupportTicket::create([
                'customer_id' => $owner->id,
                'license_id' => $owner->licenses()->value('id'),
                'subject' => 'Move my licence to a larger VPS',
                'category' => 'licensing',
                'priority' => 'normal',
                'status' => 'pending',
                'last_reply_at' => now()->subHours(3),
            ]);

            $ticket->replies()->create([
                'user_id' => $owner->user_id,
                'is_staff' => false,
                'body' => 'I am migrating from a 2 vCPU Hetzner box to a 4 vCPU one. What is the correct way to move the licence without downtime?',
            ]);

            $ticket->replies()->create([
                'user_id' => User::where('email', 'admin@atlas.test')->value('id'),
                'is_staff' => true,
                'body' => 'Install on the new server first, then run `atlas-install license transfer --to <new-fingerprint>` from the old box. The old server stays fully online until the new one activates, and the licence never enters a suspended state during the move.',
            ]);
        }

        if (! Release::where('component', 'panel')->exists()) {
            $release = Release::create([
                'component' => 'panel',
                'version' => '0.9.0',
                'channel' => 'stable',
                'notes' => "Sample release row, deliberately left unpublished.\nPublish a real build with: php artisan atlas:release:publish --file=… --version=… --publish",
                // Unpublished on purpose: a demo row must never advertise a
                // checksum that no file on disk matches. The download page
                // shows "No builds published yet" until an operator runs
                // atlas:release:publish against a real archive.
                'is_published' => false,
                'published_at' => now()->subDays(2),
            ]);

            $release->artifacts()->create([
                'os' => 'ubuntu-24.04',
                'arch' => 'amd64',
                'filename' => 'atlas-panel-0.9.0-linux-amd64.tar.gz',
                'size_bytes' => 41_287_680,
                'sha256' => str_repeat('0', 64),
                'signature' => null,
                'url' => null,
            ]);
        }
    }
}
