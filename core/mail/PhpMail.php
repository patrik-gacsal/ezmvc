<?php
namespace EzMvc;

/**
 * Sends through PHP's built-in mail() — the backend Mail uses until
 * Mail::setSender() installs another. Without a sender address, the From
 * header is left to php.ini's sendmail_from.
 */
class PhpMail implements MailSender {

    private ?MailAddress $from;
    /** @var MailAddress[] */
    private array $bcc;

    /**
     * @param string|MailAddress|null $from sender used when send() is given none
     * @param string|MailAddress|array $bcc added to every message
     */
    public function __construct(string|MailAddress|null $from = null, string|MailAddress|array $bcc = []) {
        $this->from = $from === null ? null : MailAddress::from($from);
        $this->bcc  = MailAddress::listFrom($bcc);
    }

    /** @param MailAttachment[] $attachments */
    public function send(MailAddress|array $to, string $subject, string $message, ?MailAddress $from = null, array $attachments = []): bool {
        $from ??= $this->from;

        // Strip CR/LF from every header value and the subject so a crafted
        // address or subject (e.g. "victim@x.com\r\nBcc: spam@evil.com") can't
        // inject extra headers into the message. The body is left untouched —
        // newlines there are legitimate content, not headers.
        $headers = '';
        if ($from) {
            $headers .= 'From: ' . MimeBuilder::singleLine((string) $from) . "\n"
                . 'Reply-to: ' . MimeBuilder::singleLine($from->email) . "\n";
        }
        if ($this->bcc) {
            $headers .= 'Bcc: ' . MimeBuilder::singleLine(MailAddress::joinAll($this->bcc)) . "\n";
        }
        [$contentType, $body] = MimeBuilder::build($message, $attachments);
        $headers             .= "MIME-Version: 1.0\nContent-Type: {$contentType}\n";

        return mail(MimeBuilder::singleLine(MailAddress::joinAll(MailAddress::listFrom($to))), MimeBuilder::singleLine($subject), $body, $headers);
    }
}
