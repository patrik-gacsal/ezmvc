<?php
namespace EzMvc;

class Mail {

    private static ?MailSender $sender = null;

    /**
     * Installs the backend every mail goes through — new PhpMail($from, $bcc),
     * new Smtp(...), new SendGrid(...), or your own MailSender. Call once in
     * app.php. Each backend carries its own default sender and Bcc list.
     */
    public static function setSender(MailSender $sender): void {
        self::$sender = $sender;
    }

    /**
     * The backend in use: the one setSender() installed, else a PhpMail with
     * no default sender. The Container injects this same instance wherever a
     * MailSender is type-hinted.
     */
    public static function sender(): MailSender {
        return self::$sender ??= new PhpMail();
    }

    /**
     * $to accepts a bare email string, a MailAddress, or an array of either
     * for multiple recipients. $message is always HTML — there's no separate
     * plain-text method; a plain-text message renders identically whether
     * declared text/plain or text/html, so keeping two paths for that
     * distinction wasn't worth it.
     * @param MailAttachment[] $attachments
     */
    public static function send(string|MailAddress|array $to, string $subject, string $message, string|MailAddress|null $from = null, array $attachments = []): bool {
        $from = $from === null ? null : MailAddress::from($from);
        return self::sender()->send(MailAddress::listFrom($to), $subject, $message, $from, $attachments);
    }

    /** @param MailAttachment[] $attachments */
    public static function sendTemplate(string|MailAddress|array $to, string $subject, string $template, string|MailAddress|null $from = null, array $attachments = []): void {
        ob_start();
        include 'templates/' . $template . '.php';
        $message = ob_get_clean();
        self::send($to, $subject, $message, $from, $attachments);
    }

    /** @param MailAttachment[] $attachments */
    public static function sendView(string|MailAddress|array $to, string $subject, View $view, string|MailAddress|null $from = null, array $attachments = []): void {
        self::send($to, $subject, $view->toString(), $from, $attachments);
    }
}
