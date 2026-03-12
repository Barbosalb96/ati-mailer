<?php

namespace Atima\ApiEmailLib;

use Atima\ApiEmailLib\Console\InstallCommand;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AtiEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/meu-servico.php',
            'meu-servico'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/meu-servico.php' => config_path('meu-servico.php'),
        ], 'ati-email-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        Mail::extend('ati', function (array $config) {
            return new AtiApiTransport(
                apiKey:   $config['key']      ?? config('meu-servico.key'),
                endpoint: $config['endpoint'] ?? config('meu-servico.endpoint'),
            );
        });
    }
}
