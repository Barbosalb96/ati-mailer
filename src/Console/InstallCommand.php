<?php

namespace Atima\ApiEmailLib\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature   = 'ati:install';
    protected $description = 'Configura o driver de e-mail ATI no projeto Laravel';

    public function handle(): int
    {
        $this->info('Configurando ATI Email...');

        $this->publishConfig();
        $this->downloadCaCert();
        $this->writeEnvVariables();
        $this->registerMailer();
        $this->registerHttpMacro();

        $this->newLine();
        $this->info('✔ ATI Email configurado com sucesso!');
        $this->line('  Edite ATI_EMAIL_KEY e ATI_EMAIL_ENDPOINT no seu .env antes de usar.');

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function downloadCaCert(): void
    {
        $certPath = storage_path('app/ati-cacert.pem');
        $certUrl  = 'https://curl.se/ca/cacert.pem';

        $this->line('  [..] Baixando bundle de certificados SSL...');

        $context = stream_context_create([
            'http' => ['timeout' => 15],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $pem = @file_get_contents($certUrl, false, $context);

        if ($pem === false || ! str_contains($pem, 'CERTIFICATE')) {
            $this->warn('  [skip] Não foi possível baixar o cacert.pem — SSL usará o bundle padrão do sistema.');
            return;
        }

        @mkdir(dirname($certPath), 0755, true);
        file_put_contents($certPath, $pem);

        $this->line('  [ok] cacert.pem salvo em storage/app/ati-cacert.pem');
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'ati-email-config']);
        $this->line('  [ok] config/ati-servico.php publicado');
    }

    private function writeEnvVariables(): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->warn('  [skip] .env não encontrado');
            return;
        }

        $env = file_get_contents($envPath);

        $entries = [
            'MAIL_MAILER'        => 'ati',
            'ATI_EMAIL_KEY'      => 'sua_api_key_aqui',
            'ATI_EMAIL_ENDPOINT' => 'https://api.seuservico.com',
            'ATI_STAGING'        => 'true',
            'ATI_SSL_CERT'       => 'false',
            'ATI_EMAIL_TIMEOUT'  => '30',
        ];

        foreach ($entries as $key => $default) {
            if (Str::contains($env, $key . '=')) {
                if ($key === 'MAIL_MAILER') {
                    $env = preg_replace('/^MAIL_MAILER=.*/m', 'MAIL_MAILER=ati', $env);
                }
                $this->line("  [skip] {$key} já existe no .env");
                continue;
            }

            $env .= PHP_EOL . "{$key}={$default}";
            $this->line("  [ok] {$key} adicionado ao .env");
        }

        file_put_contents($envPath, $env);
    }

    private function registerMailer(): void
    {
        $mailConfigPath = config_path('mail.php');

        if (! file_exists($mailConfigPath)) {
            $this->warn('  [skip] config/mail.php não encontrado — adicione o mailer manualmente (veja INSTALL.md)');
            return;
        }

        $content = file_get_contents($mailConfigPath);

        if (Str::contains($content, "'ati'")) {
            $this->line('  [skip] mailer "ati" já existe em config/mail.php');
            return;
        }

        $mailerBlock = <<<'PHP'

        'ati' => [
            'transport' => 'ati',
            // 'key'      => env('ATI_EMAIL_KEY'),
            // 'endpoint' => env('ATI_EMAIL_ENDPOINT'),
        ],

PHP;

        $content = $this->injectBeforeMailersClosingBracket($content, $mailerBlock);

        file_put_contents($mailConfigPath, $content);
        $this->line('  [ok] mailer "ati" adicionado em config/mail.php');
    }

    private function registerHttpMacro(): void
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');

        if (! file_exists($providerPath)) {
            $this->warn('  [skip] AppServiceProvider.php não encontrado');
            return;
        }

        $content = file_get_contents($providerPath);

        if (Str::contains($content, 'atiEmail')) {
            $this->line('  [skip] Http macro "atiEmail" já existe em AppServiceProvider');
            return;
        }

        $macro = <<<'PHP'
        Http::macro('atiEmail', function () {
            return Http::baseUrl(rtrim(config('ati-servico.endpoint'), '/'))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('ati-servico.key'),
                    'Accept'        => 'application/json',
                ])
                ->withOptions([
                    'verify'  => config('ati-servico.ssl_cert', true),
                    'timeout' => config('ati-servico.timeout', 30),
                ])
                ->acceptJson();
        });
PHP;

        if (! Str::contains($content, 'use Illuminate\Support\Facades\Http;')) {
            $content = str_replace(
                'use Illuminate\Support\ServiceProvider;',
                "use Illuminate\Support\Facades\Http;\nuse Illuminate\Support\ServiceProvider;",
                $content
            );
        }

        $content = preg_replace(
            '/(\bpublic function boot\(\): void\s*\{)/',
            "$1\n        {$macro}\n",
            $content,
            1
        );

        file_put_contents($providerPath, $content);
        $this->line('  [ok] Http macro "atiEmail" adicionado em AppServiceProvider');
    }

    private function injectBeforeMailersClosingBracket(string $content, string $block): string
    {
        $start = strpos($content, "'mailers'");
        if ($start === false) {
            return $content;
        }

        $openPos = strpos($content, '[', $start);
        if ($openPos === false) {
            return $content;
        }

        $depth = 0;
        $pos   = $openPos;
        $len   = strlen($content);

        while ($pos < $len) {
            if ($content[$pos] === '[') {
                $depth++;
            } elseif ($content[$pos] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, 0, $pos) . $block . substr($content, $pos);
                }
            }
            $pos++;
        }

        return $content;
    }
}
