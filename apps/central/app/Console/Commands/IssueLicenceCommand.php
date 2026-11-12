<?php

namespace App\Console\Commands;

use App\Domain\Licensing\IssueLicense;
use App\Domain\Licensing\TokenService;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Console\Command;

/**
 * Issues a licence key from the console, for trials, support hand-overs and
 * end-to-end verification of a freshly installed panel.
 *
 * This is the same path the paid flow takes after a verified payment — it does
 * not bypass anything, and the key it prints is a real key the vendor API will
 * honour.
 */
class IssueLicenceCommand extends Command
{
    protected $signature = 'atlas:licence:issue
                            {email : Customer email address}
                            {--plan=professional : Plan slug}
                            {--interval=monthly : monthly|annual}
                            {--json : Print a single JSON object for scripts}';

    protected $description = 'Issue a licence key for a customer and print it with the active signing key';

    public function handle(IssueLicense $issue, TokenService $tokens): int
    {
        $customer = Customer::whereHas('user', fn ($q) => $q->where('email', $this->argument('email')))->first();

        if (! $customer) {
            $this->error('No customer with that email address.');

            return self::FAILURE;
        }

        $plan = Plan::where('slug', $this->option('plan'))->first();

        if (! $plan) {
            $this->error('Unknown plan slug: '.$this->option('plan'));

            return self::FAILURE;
        }

        $licence = $issue->handle($customer, $plan, (string) $this->option('interval'));
        $key = $tokens->activeKey();

        $payload = [
            'key' => $licence->key,
            'license_id' => $licence->id,
            'public_id' => $licence->public_id,
            'kid' => $key->kid,
            'public_key' => $key->public_key,
            'plan' => $plan->slug,
            'expires_at' => $licence->expires_at?->toIso8601String(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['Licence key', $payload['key']],
            ['Licence', $payload['public_id']],
            ['Plan', $payload['plan']],
            ['Expires', $payload['expires_at']],
            ['Signing key id', $payload['kid']],
            ['Public key', $payload['public_key']],
        ]);

        $this->newLine();
        $this->line('Give the panel: ATLAS_LICENSE_KEYS=\'{"'.$payload['kid'].'":"'.$payload['public_key'].'"}\'');
        $this->line('It is a real key: activation is recorded against the customer and appears in the admin console.');

        return self::SUCCESS;
    }
}
