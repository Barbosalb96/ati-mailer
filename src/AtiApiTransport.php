<?php
namespace Atima\ApiEmailLib;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class AtiApiTransport extends AbstractTransport
{
    public array $lastResponse = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $recipients = array_map(
            fn($address) => $address->getAddress(),
            $email->getTo()
        );

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->withOptions([
            'verify' => config('ati-servico.ssl_cert', true),
        ])->post($this->endpoint . '/v2/messages/send', [
            'recipients'  => $recipients,
            'subject'     => $email->getSubject(),
            'body'        => $email->getHtmlBody() ?? $email->getTextBody(),
            'attachments' => $this->prepareAttachments($email->getAttachments()),
            'staging'     => config('ati-servico.staging'),
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                sprintf(
                    '[AtiApiTransport] Falha ao enviar e-mail. Status: %d — %s',
                    $response->status(),
                    $response->body()
                )
            );
        }

        $this->lastResponse = $response->json() ?? [];

        $message->getOriginalMessage()->getHeaders()->addTextHeader(
            'X-Ati-Api-Response',
            $response->body()
        );
    }

    protected function prepareAttachments(array $attachments): array
    {
        $anexos = [];

        foreach ($attachments as $attachment) {
            $body    = $attachment->getBody();
            $decoded = base64_decode($body, strict: true);
            $content = ($decoded !== false && base64_encode($decoded) === $body)
                ? $body
                : base64_encode($body);

            $anexos[] = [
                'filename' => $attachment->getName(),
                'content'  => $content,
                'mime'     => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
            ];
        }
        return $anexos;
    }

    public function __toString(): string
    {
        return 'ati';
    }
}
