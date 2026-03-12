<?php
namespace Atima\ApiEmailLib;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class AtiApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly bool $staging = false
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

        $files = $email->getAttachments();

        $response = Http::withHeaders([
            'Authorization' => 'bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->post($this->endpoint, [
            'recipients'  => $recipients,
            'subject'     => $email->getSubject(),
            'body'        => $email->getHtmlBody() ?? $email->getTextBody(),
            'attachments' => $files,
            'staging'     => $this->staging,
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
    }

    protected function prepareAttachments(array $attachments): array
    {
        $anexos = [];

        foreach ($attachments as $attachment) {
            $conteudo = $attachment->getMediaSubtype() === 'pdf'
                ? base64_encode(file_get_contents('files/' . $attachment->getName()))
                : base64_encode($attachment->getBody());

            $anexos[] = [
                'filename' => $attachment->getName(),
                'content'  => $conteudo,
            ];
        }

        return $anexos;
    }

    public function __toString(): string
    {
        return 'ati';
    }
}
