<?php

namespace App\Domain\Agent;

use App\Models\Account;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the panel-side half of the audit trail. The agent keeps its own
 * hash-chained journal; this mirror exists so the WHM audit screen can show
 * who asked for what without reading the agent's file.
 */
class Audit
{
    public function __construct(private readonly Agent $agent) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function dispatch(string $op, array $args = [], ?Account $account = null, ?User $user = null): array
    {
        $user ??= Auth::user();
        $started = microtime(true);

        try {
            $result = $this->agent->call($op, $args);

            $this->write($op, $args, 'ok', null, $account, $user, $started);

            return $result;
        } catch (Throwable $e) {
            $status = $e instanceof AgentException && $e->transport ? 'denied' : 'error';
            $this->write($op, $args, $status, $e->getMessage(), $account, $user, $started);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function write(
        string $op,
        array $args,
        string $status,
        ?string $detail,
        ?Account $account,
        ?User $user,
        float $started,
    ): void {
        try {
            Operation::create([
                'user_id' => $user?->id,
                'account_id' => $account?->id,
                'op' => $op,
                'args_hash' => hash('sha256', json_encode($args, JSON_UNESCAPED_SLASHES) ?: ''),
                'status' => $status,
                'detail' => $detail,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable) {
            // An audit write must never take the control panel down; the agent
            // journal remains the authoritative record either way.
        }
    }
}
