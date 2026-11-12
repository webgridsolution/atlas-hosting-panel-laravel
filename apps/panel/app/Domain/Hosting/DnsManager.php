<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class DnsManager
{
    public function __construct(private readonly Audit $audit) {}

    public function ensureZone(Account $account, string $domain): DnsZone
    {
        $zone = DnsZone::firstOrCreate(
            ['domain' => $domain],
            ['account_id' => $account->id, 'status' => 'active'],
        );

        if ($zone->records()->doesntExist()) {
            // A new zone starts with what a website actually needs: the apex
            // and www pointing at the server, plus mail routing if enabled.
            $ip = $this->serverIp();
            $ns = config('atlas.dns.nameservers');

            foreach (array_values($ns) as $i => $nameserver) {
                $zone->records()->create(['name' => '@', 'type' => 'NS', 'content' => $nameserver, 'ttl' => 86400, 'priority' => $i]);
            }

            if ($ip) {
                $zone->records()->create(['name' => '@', 'type' => 'A', 'content' => $ip, 'ttl' => 3600]);
                $zone->records()->create(['name' => 'www', 'type' => 'CNAME', 'content' => $domain, 'ttl' => 3600]);
            }

            $zone->records()->create(['name' => '@', 'type' => 'MX', 'content' => 'mail.'.$domain, 'ttl' => 3600, 'priority' => 10]);

            $this->push($zone);
        }

        return $zone;
    }

    public function addRecord(DnsZone $zone, string $name, string $type, string $content, int $ttl = 3600, ?int $priority = null): DnsRecord
    {
        $record = DB::transaction(fn () => $zone->records()->create([
            'name' => $name, 'type' => $type, 'content' => $content,
            'ttl' => $ttl, 'priority' => $priority,
        ]));

        $this->push($zone);

        return $record;
    }

    public function deleteRecord(DnsRecord $record): void
    {
        $zone = $record->zone;
        $record->delete();
        $this->push($zone);
    }

    /** Replaces the managed records of the zone on the DNS server. */
    public function push(DnsZone $zone): void
    {
        $this->audit->dispatch('dns.zone.write', [
            'domain' => $zone->domain,
            'records' => $zone->fresh()->toZonePayload(),
        ], $zone->account);
    }

    private function serverIp(): ?string
    {
        return Setting::get('server_ip') ?: null;
    }
}
