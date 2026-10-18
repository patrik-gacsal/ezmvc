<?php
namespace EzMvc;

/**
 * Send email directly via SMTP. Implements MailSender directly — an
 * instantiated class configured through the constructor (Mail::setSender()
 * takes an object).
 *
 * The transport is inferred from the port unless $secure is passed explicitly:
 * 465 = implicit TLS ("SSL", encrypted from connect), 587 = STARTTLS (upgrade
 * after EHLO), anything else (25, or a local relay) = plaintext. It uses
 * stream_socket_client because ext/sockets can't do TLS, so AUTH credentials
 * would cross the wire in the clear.
 */
class Smtp implements MailSender {
    const SECURE_NONE = 'none'; // plaintext (local relay / port 25)
    const SECURE_SSL  = 'ssl';  // implicit TLS from connect (typically port 465)
    const SECURE_TLS  = 'tls';  // STARTTLS upgrade after EHLO (typically port 587)

    /** @var MailAddress[] */
    private array $bcc;
    private string $secure;

    /**
     * @param string $from sender address used when send() is given none
     * @param string $fromName sender name used when send() is given none
     * @param string|MailAddress|array $bcc added to every message
     * @param ?string $user AUTH LOGIN user; null skips AUTH
     * @param ?string $ehlo EHLO domain; defaults to the sender's domain
     * @param ?string $secure a SECURE_* constant; inferred from $port when null
     */
    public function __construct(
        private string $from,
        private string $fromName,
        string|MailAddress|array $bcc,
        private string $server,
        private ?string $user = null,
        #[\SensitiveParameter] private ?string $pass = null,
        private int $port = 587,
        private ?string $ehlo = null,
        ?string $secure = null
    ) {
        $this->bcc    = MailAddress::listFrom($bcc);
        $this->secure = $secure ?? match ($port) {
            465     => self::SECURE_SSL,
            587     => self::SECURE_TLS,
            default => self::SECURE_NONE,
        };
    }

    /**
     * Runs the SMTP conversation and returns whether the message was accepted —
     * the final response (to the DATA payload) starts with a 2xx code.
     * @param MailAddress[] $to
     */
    private function sendMail(array $to, $subject, $message, $from, $headers): bool {
        $domain = $this->ehlo ?: substr($from, strpos($from, '@') + 1);
        $socket = $this->initSocket();

        $this->read($socket);                 // server greeting
        $this->cmd($socket, 'EHLO ' . $domain);

        if ($this->secure === self::SECURE_TLS) {
            $this->cmd($socket, 'STARTTLS');
            if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new \RuntimeException('SMTP STARTTLS negotiation failed with ' . $this->server);
            }
            $this->cmd($socket, 'EHLO ' . $domain); // re-greet over the encrypted channel
        }

        if ($this->user) {
            $this->cmd($socket, 'AUTH LOGIN');
            $this->cmd($socket, base64_encode($this->user));
            $this->cmd($socket, base64_encode((string) $this->pass));
        }

        $this->cmd($socket, "MAIL FROM: <$from>");
        foreach ($to as $recipient) {
            $this->cmd($socket, 'RCPT TO: <' . MimeBuilder::singleLine($recipient->email) . '>');
        }
        $this->cmd($socket, 'DATA');          // server replies 354, then wants the body

        $data  = $headers;
        $data .= 'Subject: =?utf-8?B?' . base64_encode($subject) . '?=' . "\r\n\r\n";
        $data .= self::dotStuff($message) . "\r\n.\r\n";

        fwrite($socket, $data);
        $final = $this->read($socket);
        fclose($socket);

        return (bool) preg_match('~^2\d\d~', ltrim($final));
    }

    /** Opens the stream (TLS for SSL mode, plain TCP otherwise). */
    private function initSocket() {
        $transport = $this->secure === self::SECURE_SSL ? 'ssl' : 'tcp';
        $socket    = @stream_socket_client(
            $transport . '://' . $this->server . ':' . $this->port,
            $errno, $errstr, 30
        );
        if ($socket === false) {
            throw new \RuntimeException("SMTP connection to {$this->server}:{$this->port} failed: $errstr ($errno)");
        }
        stream_set_timeout($socket, 30);
        return $socket;
    }

    /** Writes a CRLF-terminated command and returns the server's response. */
    private function cmd($socket, string $command): string {
        fwrite($socket, $command . "\r\n");
        return $this->read($socket);
    }

    /** Reads a (possibly multi-line) SMTP response. */
    private function read($socket): string {
        $data = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $data .= $line;
            // "250-…" is a continuation; "250 …" (space at index 3) is the last line.
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    /** RFC 5321 dot-stuffing: a body line that starts with '.' is escaped to '..'. */
    private static function dotStuff(string $message): string {
        return preg_replace('/^\./m', '..', $message);
    }

    /** @param MailAttachment[] $attachments */
    public function send(MailAddress|array $to, string $subject, string $message, ?MailAddress $from = null, array $attachments = []): bool {
        $toList    = MailAddress::listFrom($to);
        $fromEmail = MimeBuilder::singleLine($from?->email ?? $this->from);
        $fromName  = MimeBuilder::singleLine($from?->name ?? $this->fromName);

        $headers = 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n"
            . 'To: ' . MimeBuilder::singleLine(MailAddress::joinAll($toList)) . "\r\n"
            . 'Reply-to: ' . $fromEmail . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n";
        if ($this->bcc) {
            $headers .= 'Bcc: ' . MimeBuilder::singleLine(MailAddress::joinAll($this->bcc)) . "\r\n";
        }
        [$contentType, $body] = MimeBuilder::build($message, $attachments);
        $headers              .= 'Content-Type: ' . $contentType . "\r\n";
        return $this->sendMail($toList, $subject, $body, $fromEmail, $headers);
    }
}
