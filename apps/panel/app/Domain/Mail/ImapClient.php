<?php

namespace App\Domain\Mail;

use RuntimeException;

/**
 * A small IMAP4rev1 client for webmail — no extension required, no Composer
 * dependency, and no surprises about which internal classes the hosting image
 * happens to ship.
 *
 * Only the commands webmail needs are implemented: LOGIN, SELECT, SEARCH,
 * FETCH and LOGOUT.
 */
class ImapClient
{
    /** @var resource */
    private $stream;

    private int $tag = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 143,
        private readonly int $timeout = 15,
    ) {}

    public function connect(): self
    {
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            'tcp://'.$this->host.':'.$this->port,
            $errno,
            $errstr,
            5,
        );

        if ($stream === false) {
            throw new RuntimeException('Cannot reach the mail server: '.($errstr ?: 'connection refused'));
        }

        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $greeting = fgets($this->stream);

        if ($greeting === false || ! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('The mail server did not greet us as an IMAP server.');
        }

        return $this;
    }

    public function login(string $user, string $password): self
    {
        $response = $this->command('LOGIN '.$this->quote($user).' '.$this->quote($password));

        if (! str_starts_with($response, 'OK')) {
            throw new RuntimeException('Mail login failed. Check the address and password.');
        }

        return $this;
    }

    /** Selects INBOX and returns the message count. */
    public function inboxCount(): int
    {
        $response = $this->command('SELECT INBOX');

        if (! str_starts_with($response, 'OK')) {
            throw new RuntimeException('INBOX is not available for this mailbox.');
        }

        if (preg_match('/\* (\d+) EXISTS/', implode("\n", $response['lines'] ?? []), $m) === 1) {
            return (int) $m[1];
        }

        return (int) ($response['exists'] ?? 0);
    }

    /**
     * The most recent $limit messages, newest first.
     *
     * @return array<int, array{uid: string, subject: string, from: string, date: string, size: int, seen: bool}>
     */
    public function recent(int $limit = 25): array
    {
        $search = $this->command('UID SEARCH ALL');
        $uids = [];

        foreach ($search['lines'] as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $uids = preg_split('/\s+/', trim(substr($line, 8))) ?: [];
            }
        }

        $uids = array_slice(array_filter($uids, 'ctype_digit'), -$limit);
        $messages = [];

        foreach (array_reverse($uids) as $uid) {
            $fetch = $this->command(
                'UID FETCH '.$uid.' (FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])',
            );

            $raw = $fetch['literals'][0] ?? '';
            $meta = implode("\n", $fetch['lines']);

            $messages[] = [
                'uid' => (string) $uid,
                'from' => $this->header($raw, 'From'),
                'subject' => $this->header($raw, 'Subject') ?: '(no subject)',
                'date' => $this->header($raw, 'Date'),
                'size' => preg_match('/RFC822\.SIZE (\d+)/', $meta, $m) === 1 ? (int) $m[1] : 0,
                'seen' => str_contains($meta, '\\Seen'),
            ];
        }

        return $messages;
    }

    /** @return array{headers: string, body: string} */
    public function message(string $uid): array
    {
        $fetch = $this->command('UID FETCH '.$uid.' (BODY.PEEK[])');
        $raw = $fetch['literals'][0] ?? '';

        if ($raw === '') {
            throw new RuntimeException('That message is no longer on the server.');
        }

        $split = strpos($raw, "\r\n\r\n");
        $headers = $split === false ? '' : substr($raw, 0, $split);
        $body = $split === false ? $raw : substr($raw, $split + 4);

        return ['headers' => $headers, 'body' => $body];
    }

    public function delete(string $uid): void
    {
        $this->command('UID STORE '.$uid.' +FLAGS (\Deleted)');
        $this->command('EXPUNGE');
    }

    public function logout(): void
    {
        if (isset($this->stream) && is_resource($this->stream)) {
            $this->command('LOGOUT');
            fclose($this->stream);
        }
    }

    /**
     * Sends one command and reads until the tagged completion.
     *
     * @return array{status: string, lines: array<int, string>, literals: array<int, string>}
     */
    private function command(string $command): array
    {
        $tag = 'A'.(++$this->tag);

        fwrite($this->stream, $tag.' '.$command."\r\n");

        $lines = [];
        $literals = [];
        $status = '';

        while (($line = fgets($this->stream)) !== false) {
            $line = rtrim($line, "\r\n");

            // A literal is announced as {123} at the end of a line; the next
            // 123 bytes are data, not protocol.
            if (preg_match('/\{(\d+)\}$/', $line, $m) === 1) {
                $length = (int) $m[1];
                $data = '';

                while (strlen($data) < $length) {
                    $chunk = fread($this->stream, $length - strlen($data));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $data .= $chunk;
                }

                $literals[] = $data;
                $lines[] = $line;
                fgets($this->stream); // trailing CRLF after the literal

                continue;
            }

            if (str_starts_with($line, $tag.' ')) {
                $status = substr($line, strlen($tag) + 1);

                break;
            }

            $lines[] = $line;
        }

        if ($status === '') {
            throw new RuntimeException('The mail server stopped responding mid-command.');
        }

        return ['status' => $status, 'lines' => $lines, 'literals' => $literals];
    }

    private function header(string $raw, string $name): string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.*(?:\r\n[ \t].*)*)$/mi', $raw, $m) === 1) {
            return trim(preg_replace('/\r\n[ \t]+/', ' ', $m[1]) ?? '');
        }

        return '';
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
