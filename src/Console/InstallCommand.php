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
        $this->writeEnvVariables();
        $this->registerMailer();

        $this->newLine();
        $this->info('✔ ATI Email configurado com sucesso!');
        $this->line('  Edite ATI_EMAIL_KEY e ATI_EMAIL_ENDPOINT no seu .env antes de usar.');

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

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
            'ATI_EMAIL_ENDPOINT' => 'https://api.seuservico.com/v1/send',
        ];

        foreach ($entries as $key => $default) {
            if (Str::contains($env, $key . '=')) {
                // Já existe: apenas troca MAIL_MAILER se ainda não for 'ati'
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
