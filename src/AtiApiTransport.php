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
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->post($this->endpoint, [
            'recipients'  => $recipients,
            'subject'     => $email->getSubject(),
            'body'        => $email->getHtmlBody() ?? $email->getTextBody(),
            'attachments' => $files,
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

        if ($response->successful()) {
            $symfonyMessage = $message->getOriginalMessage();

            $symfonyMessage->getHeaders()->addTextHeader(
                'X-Ati-Api-Response',
                $response->body()
            );
        } else {
            throw new \RuntimeException("Erro API: " . $response->body());
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
