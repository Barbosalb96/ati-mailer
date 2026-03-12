# Instalação rápida (plug and play)

## 1. Instalar via Composer

```bash
composer require ati/api-email-lib
```

O auto-discovery do Laravel registra o `AtiEmailServiceProvider` automaticamente.
Nenhum `config/app.php` precisa ser editado.

---

## 2. Rodar o instalador

```bash
php artisan ati:install
```

Este comando faz tudo automaticamente:
- Publica `config/meu-servico.php` no projeto
- Adiciona `MAIL_MAILER`, `ATI_EMAIL_KEY` e `ATI_EMAIL_ENDPOINT` no `.env` (sem sobrescrever valores existentes)
- Injeta o bloco `'ati'` no array `mailers` do `config/mail.php`

Depois edite os valores reais no `.env`:

```dotenv
ATI_EMAIL_KEY=sua_api_key_aqui
ATI_EMAIL_ENDPOINT=https://api.seuservico.com/v1/send
```

---

## (Manual) Variáveis no `.env`

```dotenv
MAIL_MAILER=ati
MAIL_FROM_ADDRESS="noreply@seudominio.com"
MAIL_FROM_NAME="${APP_NAME}"

ATI_EMAIL_KEY=sua_api_key_aqui
ATI_EMAIL_ENDPOINT=https://api.seuservico.com/v1/send
```

---

## 3. Adicionar o mailer em `config/mail.php`

Dentro do array `mailers`, adicione:

```php
'mailers' => [

    // ... outros drivers (smtp, ses, etc.)

    'ati' => [
        'transport' => 'ati',
        // Opcional: sobrescreve as variáveis de ambiente só para este mailer
        // 'key'      => env('ATI_EMAIL_KEY'),
        // 'endpoint' => env('ATI_EMAIL_ENDPOINT'),
    ],

],
```

---

## 4. (Opcional) Adicionar em `config/services.php`

Se preferir centralizar credenciais de terceiros no padrão Laravel:

```php
'ati' => [
    'key'      => env('ATI_EMAIL_KEY'),
    'endpoint' => env('ATI_EMAIL_ENDPOINT', 'https://api.seuservico.com/v1/send'),
],
```

> O pacote lê de `config('meu-servico.*)` por padrão, mas o `AtiEmailServiceProvider`
> aceita os valores do array `$config` passado pelo `config/mail.php`, então qualquer
> uma das abordagens funciona.

---

## 5. (Opcional) Publicar o config

Se quiser editar `config/meu-servico.php` no projeto:

```bash
php artisan vendor:publish --tag=ati-email-config
```

---

## 6. Testar

```bash
php artisan tinker
```

```php
Mail::raw('Teste de envio via API', fn ($m) => $m->to('destino@exemplo.com')->subject('Teste ATI'));
```

---

## Ajustar o payload da request

No `AtiApiTransport::doSend()`, o JSON enviado à API é:

```php
[
    'to'      => ['destino@exemplo.com'],   // array de endereços
    'subject' => 'Assunto do e-mail',
    'html'    => '<p>Corpo HTML</p>',
    'text'    => 'Corpo texto plano',
    'from'    => 'remetente@seudominio.com',
]
```

Se a sua API espera campos diferentes (ex: `recipient_email` em vez de `to`),
edite apenas esse array no método `doSend`.
