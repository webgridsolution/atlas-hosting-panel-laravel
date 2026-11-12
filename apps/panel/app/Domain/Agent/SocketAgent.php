<?php

namespace App\Domain\Agent;

use JsonException;

/**
 * Talks to the privileged agent over its Unix socket.
 *
 * The wire format is the same envelope the Go agent verifies:
 *
 *   atlas-envelope-v1 \n op \n nonce \n iat \n exp \n sha256(args as sent)
 *
 * hashed with HMAC-SHA256 under the shared key. Signing a digest of the raw
 * argument bytes (rather than a re-encoded document) keeps PHP and Go byte
 * compatible without either side having to imitate the other's JSON encoder.
 */
class SocketAgent implements Agent
{
    public function __construct(
        private readonly string $socket,
        private readonly string $keyPath,
        private readonly int $timeout = 60,
    ) {}

    public function call(string $op, array $args = []): array
    {
        $body = $this->envelope($op, $args);
        [$status, $payload] = $this->post($body, $op);

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new AgentException('The agent returned a malformed reply.', $op, $status);
        }

        if ($status !== 200 || ($decoded['ok'] ?? false) !== true) {
            throw new AgentException(
                (string) ($decoded['error'] ?? 'The agent refused the operation.'),
                $op,
                $status,
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded['result'] ?? [];

        return $result;
    }

    public function healthy(): bool
    {
        try {
            [$status, $body] = $this->post(null, 'health', '/health');

            return $status === 200 && str_contains($body, '"ok":true');
        } catch (AgentException) {
            return false;
        }
    }

    /**
     * Build the signed envelope. Exposed for the conformance test that checks
     * the exact bytes against the Go implementation.
     *
     * @param  array<string, mixed>  $args
     */
    public function envelope(string $op, array $args, ?int $now = null): string
    {
        try {
            $argsJson = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AgentException('Arguments could not be encoded: '.$e->getMessage(), $op);
        }

        $now ??= time();
        $nonce = bin2hex(random_bytes(16));
        $iat = $now;
        $exp = $now + 30;

        $canonical = implode("\n", [
            'atlas-envelope-v1',
            $op,
            $nonce,
            (string) $iat,
            (string) $exp,
            hash('sha256', $argsJson),
        ]);

        $sig = hash_hmac('sha256', $canonical, $this->key());

        return '{"request":{"op":'.json_encode($op, JSON_THROW_ON_ERROR)
            .',"args":'.$argsJson
            .',"nonce":"'.$nonce.'"'
            .',"iat":'.$iat
            .',"exp":'.$exp
            .'},"sig":"'.$sig.'"}';
    }

    /** @return array{0:int,1:string} */
    private function post(?string $body, string $op, string $path = '/dispatch'): array
    {
        $key = $this->key();

        if ($body !== null && ! str_starts_with($body, '{"request"')) {
            throw new AgentException('Refusing to send an unsigned request.', $op);
        }

        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client('unix://'.$this->socket, $errno, $errstr, 5);

        if ($stream === false) {
            throw AgentException::unreachable($op, $errstr ?: 'no such socket '.$this->socket);
        }

        stream_set_timeout($stream, $this->timeout);

        $body ??= '';
        $headers = "POST {$path} HTTP/1.1\r\n"
            ."Host: localhost\r\n"
            ."Content-Type: application/json\r\n"
            .'Content-Length: '.strlen($body)."\r\n"
            ."Connection: close\r\n\r\n";

        fwrite($stream, $headers.$body);
        fflush($stream);

        $response = stream_get_contents($stream);
        fclose($stream);

        if ($response === false || $response === '') {
            throw AgentException::unreachable($op, 'the agent closed the connection');
        }

        $split = strpos($response, "\r\n\r\n");

        if ($split === false) {
            throw new AgentException('The agent sent a reply without headers.', $op);
        }

        $head = substr($response, 0, $split);
        $payload = substr($response, $split + 4);

        $status = preg_match('#^HTTP/\d\.\d (\d{3})#', $head, $m) === 1 ? (int) $m[1] : 0;

        // Unused here but kept so the linter sees the key is required before
        // any request leaves this object.
        unset($key);

        return [$status, $payload];
    }

    private function key(): string
    {
        if (! is_readable($this->keyPath)) {
            throw AgentException::unreachable('', 'the agent key at '.$this->keyPath.' is not readable by the panel');
        }

        $key = trim((string) file_get_contents($this->keyPath));

        if (strlen($key) < 32) {
            throw AgentException::unreachable('', 'the agent key is too short');
        }

        return $key;
    }
}
