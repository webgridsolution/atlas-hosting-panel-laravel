<?php

namespace App\Domain\Agent;

use RuntimeException;

class AgentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $op = '',
        public readonly int $status = 0,
        public readonly bool $transport = false,
    ) {
        parent::__construct($message);
    }

    /** The agent could not be reached at all. */
    public static function unreachable(string $op, string $why): self
    {
        return new self("The server agent is not reachable: {$why}", $op, 0, true);
    }
}
