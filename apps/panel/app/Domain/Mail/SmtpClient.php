<?php

namespace App\Domain\Mail;

use RuntimeException;

/**
 * Minimal SMTP submission against the local MTA. Header injection is refused
 * rather than sanitised: a newline in a subject is an attack, not a typo.
 */
class SmtpClient
{
    /** @var resource */
    private $stream;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 25,
        private readonly int $timeout = 20,
    ) {}

    public function send(string $from, string $to, string $subject, string $body): void
    {
        foreach ([$from, $to, $subject] as $field) {
            if (preg_match('/[\r\n]/', $field) === 1) {
                throw new RuntimeException('Mail headers may not contain line breaks.');
            }
        }

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client('tcp://'.$this->host.':'.$this->port, $errno, $errstr, 5);

        if ($stream === false) {
            throw new RuntimeException('Cannot reach the mail server: '.($errstr ?: 'connection refused'));
        }

        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $this->expect('220');
        $this->say('EHLO '.gethostname());
        $this->expect('250');
        $this->say('MAIL FROM:<'.$from.'>');
        $this->expect('250');
        $this->say('RCPT TO:<'.$to.'>');
        $this->expect('250');
        $this->say('DATA');
        $this->expect('354');

        $message = 'From: '.$from."\r\n"
            .'To: '.$to."\r\n"
            .'Subject: '.$this->encodeHeader($subject)."\r\n"
            .'Date: '.date('r')."\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: 8bit\r\n\r\n"
            // Dot-stuffing: a line that starts with "." must be doubled.
            .preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body))
            ."\r\n.";

        $this->say($message, false);
        $this->expect('250');
        $this->say('QUIT');
        fclose($stream);
    }

    private function say(string $line, bool $crlf = true): void
    {
        fwrite($this->stream, $line.($crlf ? "\r\n" : "\r\n"));
    }

    private function expect(string $code): void
    {
        $response = '';

        while (($line = fgets($this->stream)) !== false) {
            $response .= $line;

            // Multiline replies end when the fourth character is a space.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if (! str_starts_with($response, $code)) {
            throw new RuntimeException('The mail server refused the message: '.trim($response));
        }
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value) === 1
            ? '=?UTF-8?B?'.base64_encode($value).'?='
            : $value;
    }
}
