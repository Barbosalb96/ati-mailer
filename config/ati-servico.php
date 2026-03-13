<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    | Chave de autenticação da sua API de e-mail.
    | Defina ATI_EMAIL_KEY no .env do projeto Laravel consumidor.
    */
    'key'      => env('ATI_EMAIL_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    | URL base para envio. Defina ATI_EMAIL_ENDPOINT no .env ou deixe o padrão.
    */
    'endpoint' => env('ATI_EMAIL_ENDPOINT', 'https://api.seuservico.com/v1/send'),

    'staging'  => env('STAGING', true),

    /*
    |--------------------------------------------------------------------------
    | SSL Certificate
    |--------------------------------------------------------------------------
    | Caminho para o bundle de CAs usado na verificação SSL.
    | Gerado automaticamente pelo comando ati:install.
    | Defina false para desabilitar (não recomendado).
    */
    'ssl_cert' => env('ATI_SSL_CERT', true),

];
