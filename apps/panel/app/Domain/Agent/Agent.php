<?php

namespace App\Domain\Agent;

interface Agent
{
    /**
     * Dispatch one allowlisted operation. Returns the agent's result document
     * or throws AgentException.
     *
     * There is deliberately no method to run a command: the panel cannot ask
     * for anything the agent does not already implement.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $op, array $args = []): array;

    /** True when the agent socket answers the health endpoint. */
    public function healthy(): bool;
}
