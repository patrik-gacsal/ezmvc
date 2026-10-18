<?php
namespace EzMvc;

/**
 * A pluggable email backend, swapped in with Mail::setSender() (PhpMail, the
 * default, Smtp, SendGrid, or your own). $message is always HTML. $to is one MailAddress or
 * an array of them — normalize it with MailAddress::listFrom().
 */
interface MailSender {
    /** @param MailAttachment[] $attachments */
    public function send(MailAddress|array $to, string $subject, string $message, ?MailAddress $from = null, array $attachments = []): bool;
}
