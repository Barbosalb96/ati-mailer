# Instalação rápida (plug and play)

## 1. Instalar via Composer

```bash
composer require atima/api-email-lib
```

O auto-discovery do Laravel registra o `AtiEmailServiceProvider` automaticamente.
Nenhum `config/app.php` precisa ser editado.

---

## 2. Rodar o instalador

```bash
php artisan ati:install
```

Este comando faz tudo automaticamente:
- Publica `config/ati-servico.php` no projeto
- Adiciona `MAIL_MAILER`, `ATI_EMAIL_KEY`, `ATI_EMAIL_ENDPOINT` e `STAGING` no `.env` (sem sobrescrever valores existentes)
- Injeta o bloco `'ati'` no array `mailers` do `config/mail.php`

Depois edite os valores reais no `.env`:

```dotenv
ATI_EMAIL_KEY=sua_api_key_aqui
ATI_EMAIL_ENDPOINT=https://api.seuservico.com/v1/send
STAGING=true
```

---

## (Manual) Variáveis no `.env`

```dotenv
MAIL_MAILER=ati
MAIL_FROM_ADDRESS="noreply@seudominio.com"
MAIL_FROM_NAME="${APP_NAME}"

ATI_EMAIL_KEY=sua_api_key_aqui
ATI_EMAIL_ENDPOINT=https://api.seuservico.com/v1/send
STAGING=true
```

---

## 3. Adicionar o mailer em `config/mail.php`

O bloco `'ati'` deve ser um item **de topo** no array `mailers`, nunca aninhado dentro de outro driver (como `smtp`):

```php
'mailers' => [

    'smtp' => [
        'transport' => 'smtp',
        // ... campos do smtp ...
    ],

    // CORRETO: 'ati' no mesmo nível que 'smtp', 'ses', etc.
    'ati' => [
        'transport' => 'ati',
        // 'key'      => env('ATI_EMAIL_KEY'),
        // 'endpoint' => env('ATI_EMAIL_ENDPOINT'),
    ],

],
```

> **Atenção:** se usar `php artisan ati:install` em versões anteriores a esta correção, verifique se o bloco foi inserido no nível correto. O `ati` aninhado dentro do `smtp` causa o erro `Mailer [ati] is not defined`.

---

## 4. (Opcional) Publicar o config

Se quiser editar `config/ati-servico.php` no projeto:

```bash
php artisan vendor:publish --tag=ati-email-config
```

O arquivo publicado expõe as seguintes chaves:

```php
// config/ati-servico.php
return [
    'key'      => env('ATI_EMAIL_KEY'),
    'endpoint' => env('ATI_EMAIL_ENDPOINT', 'https://api.seuservico.com/v1/send'),
    'staging'  => env('STAGING', true),
];
```

---

## 5. Testar

```bash
php artisan tinker
```

```php
Mail::raw('Teste de envio via API', fn ($m) => $m->to('destino@exemplo.com')->subject('Teste ATI'));
```

---

## Autenticação e payload da request

O transporte usa **Bearer Token**. A `ATI_EMAIL_KEY` é enviada diretamente no header `Authorization`.

No `AtiApiTransport::doSend()`, o header e o JSON enviados à API são:

```php
// Header
'Authorization' => 'bearer <ATI_EMAIL_KEY>'

// Payload
[
    'recipients'  => ['destino@exemplo.com'],  // array de endereços
    'subject'     => 'Assunto do e-mail',
    'body'        => '<p>Corpo HTML</p>',       // HTML preferido; fallback para texto plano
    'attachments' => [...],                     // anexos do e-mail (veja abaixo)
    'staging'     => true,                      // controlado por config('ati-servico.staging') / env STAGING
]
```

---

## Envio com anexos

Para anexar arquivos, use o método `attach` normalmente pelo Laravel Mail:

```php
Mail::send([], [], function ($m) {
    $m->to('destino@exemplo.com')
      ->subject('E-mail com anexo')
      ->setBody('<p>Veja o arquivo em anexo.</p>', 'text/html')
      ->attach('/caminho/para/arquivo.pdf');
});
```

Os anexos são repassados diretamente no campo `attachments` do payload enviado à API.
