<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    | Chave de autenticação da API de e-mail.
    | Defina ATI_EMAIL_KEY no .env do projeto Laravel consumidor.
    */
    'key' => env('ATI_EMAIL_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    | URL base da API (sem caminho). Ex: https://api.seuservico.com
    | O transport acrescenta automaticamente o path de envio.
    */
    'endpoint' => env('ATI_EMAIL_ENDPOINT', 'https://api.seuservico.com'),

    /*
    |--------------------------------------------------------------------------
    | Staging
    |--------------------------------------------------------------------------
    | Quando true, os e-mails são enviados em modo de teste (staging).
    */
    'staging' => env('ATI_STAGING', true),

    /*
    |--------------------------------------------------------------------------
    | SSL Certificate
    |--------------------------------------------------------------------------
    | Caminho para o bundle de CAs usado na verificação SSL.
    | Gerado automaticamente pelo comando ati:install.
    | Defina false para desabilitar (não recomendado em produção).
    */
    'ssl_cert' => env('ATI_SSL_CERT', true),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    | Tempo máximo em segundos para aguardar resposta da API.
    */
    'timeout' => env('ATI_EMAIL_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Base64 File Processor — Disco e Pasta
    |--------------------------------------------------------------------------
    | Disco Laravel (filesystems.disks) e pasta de destino usados pelo
    | Base64FileProcessor ao salvar arquivos extraídos de payloads base64.
    |
    | ATI_B64_DISK   → nome do disco (padrão: public)
    | ATI_B64_FOLDER → subpasta dentro do disco (padrão: documents-email)
    */
    'b64_disk'   => env('ATI_B64_DISK',   'public'),
    'b64_folder' => env('ATI_B64_FOLDER', 'documents-email'),

];