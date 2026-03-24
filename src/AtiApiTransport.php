<?php

namespace Atima\ApiEmailLib;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

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
        $email    = MessageConverter::toEmail($message->getOriginalMessage());
        $response = $this->sendToApi($email);

        if ($response->failed()) {
            $error = sprintf(
                '[AtiApiTransport] Falha ao enviar e-mail. Status: %d — %s',
                $response->status(),
                $response->body()
            );

            Log::error($error, [
                'to'       => array_map(fn($a) => $a->getAddress(), $email->getTo()),
                'subject'  => $email->getSubject(),
                'endpoint' => $this->endpoint,
            ]);

            throw new \RuntimeException($error);
        }

        $this->lastResponse = $response->json() ?? [];

        $message->getOriginalMessage()->getHeaders()->addTextHeader(
            'X-Ati-Api-Response',
            $response->body()
        );
    }

    private function sendToApi(Email $email): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders($this->buildHeaders())
            ->withOptions([
                'verify'  => config('ati-servico.ssl_cert', true),
                'timeout' => config('ati-servico.timeout', 30),
            ])
            ->post(rtrim($this->endpoint, '/') . '/v2/messages/send', $this->buildPayload($email));
    }

    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    private function buildPayload(Email $email): array
    {
        $payload = [
            'recipients'  => array_map(fn($a) => $a->getAddress(), $email->getTo()),
            'subject'     => $email->getSubject(),
            'body'        => $email->getHtmlBody() ?? $email->getTextBody(),
            'attachments' => $this->prepareAttachments($email->getAttachments()),
            'staging'     => config('ati-servico.staging'),
        ];

        if ($from = $email->getFrom()) {
            $payload['from'] = $from[0]->getAddress();

            if ($from[0]->getName()) {
                $payload['from_name'] = $from[0]->getName();
            }
        }

        if ($cc = $email->getCc()) {
            $payload['cc'] = array_map(fn($a) => $a->getAddress(), $cc);
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = array_map(fn($a) => $a->getAddress(), $bcc);
        }

        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $replyTo[0]->getAddress();
        }

        if ($email->getHtmlBody() && $email->getTextBody()) {
            $payload['text_body'] = $email->getTextBody();
        }

        return $payload;
    }

    private function prepareAttachments(array $attachments): array
    {
        return array_map(fn(DataPart $a) => [
            'filename' => $a->getName(),
            'content'  => $this->encodeContent($a->getBody()),
            'mime'     => $a->getMediaType() . '/' . $a->getMediaSubtype(),
        ], $attachments);
    }

    private function encodeContent(string $body): string
    {
        $decoded = base64_decode($body, strict: true);

        return ($decoded !== false && base64_encode($decoded) === $body)
            ? $body
            : base64_encode($body);
    }

    public function __toString(): string
    {
        return 'ati';
    }
}
