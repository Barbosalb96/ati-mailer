<?php

namespace Ati\ApiEmailLib;

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
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->post($this->endpoint, [
            'to'      => $recipients,
            'subject' => $email->getSubject(),
            'html'    => $email->getHtmlBody(),
            'text'    => $email->getTextBody(),
            'from'    => $email->getFrom()[0]->getAddress(),
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
