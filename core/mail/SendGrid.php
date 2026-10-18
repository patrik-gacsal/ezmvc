<?php
namespace EzMvc;

/** Sends mail through the SendGrid v3 API. */
class SendGrid implements MailSender {
    private $client, $fromEmail, $fromName;
    /** @var array[]|null {email, name} recipients */
    private $bcc = null;

    /** $fromEmail/$fromName are the sender used when send() is given none. */
    public function __construct(#[\SensitiveParameter] $apiKey, $fromEmail = null, $fromName = null) {
        $this->fromEmail = $fromEmail;
        $this->fromName  = $fromName;
        $this->client    = new HttpClient();
        $this->client->setJson();
        $this->client->addHeader('Authorization: Bearer ' . $apiKey);
    }

    /** Adds a Bcc recipient to every later send(). */
    public function addBcc($email, $name = null) {
        $this->bcc[] = ['email' => $email, 'name' => $name];
    }

    /** Removes every addBcc() recipient. */
    public function clearBcc() {
        $this->bcc = null;
    }

    /** @param MailAttachment[] $attachments */
    public function send(MailAddress|array $to, string $subject, string $message, ?MailAddress $from = null, array $attachments = []): bool {
        $this->client->post('https://api.sendgrid.com/v3/mail/send', [
            'personalizations' => [[
                'to'  => array_map(fn(MailAddress $recipient) => ['email' => $recipient->email, 'name' => $recipient->name], MailAddress::listFrom($to)),
                'bcc' => $this->bcc,
            ]],
            'from'        => ['email' => $from?->email ?? $this->fromEmail, 'name' => $from?->name ?? $this->fromName],
            'subject'     => $subject,
            'content'     => [['type' => 'text/html', 'value' => $message]],
            'attachments' => $attachments ? array_map(
                fn(MailAttachment $a) => ['content' => base64_encode($a->content), 'type' => $a->mimeType, 'filename' => $a->filename],
                $attachments
            ) : null,
        ]);
        return $this->client->getLastCode() >= 200 && $this->client->getLastCode() < 300;
    }

    /** The raw body of the last API response, for diagnosing a failed send(). */
    public function getLastResponse() {
        return $this->client->getLastResponse();
    }
}
