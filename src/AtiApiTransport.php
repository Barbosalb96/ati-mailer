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
            fn ($address) => $address->getAddress(),
            $email->getTo()
        );

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->post($this->endpoint, [
            'destinatarios' => $recipients,
            'assunto'       => $email->getSubject(),
            'corpo'         => $email->getHtmlBody() ?? $email->getTextBody(),
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

    public function __toString(): string
    {
        return 'ati';
    }
}
