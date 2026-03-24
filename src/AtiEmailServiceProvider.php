<?php

namespace Atima\ApiEmailLib;

use Atima\ApiEmailLib\Console\InstallCommand;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Atima\ApiEmailLib\Base64FileProcessor;
use Atima\ApiEmailLib\InlineImageProcessor;

class AtiEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ati-servico.php',
            'ati-servico'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ati-servico.php' => config_path('ati-servico.php'),
        ], 'ati-email-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        Mail::extend('ati', function (array $config) {
            return new AtiApiTransport(
                apiKey:   $config['key']      ?? config('ati-servico.key'),
                endpoint: $config['endpoint'] ?? config('ati-servico.endpoint'),
            );
        });

        $this->app->singleton(Base64FileProcessor::class, fn () => new Base64FileProcessor(
            disk:   config('ati-servico.b64_disk',   'public'),
            folder: config('ati-servico.b64_folder', 'documents-email'),
        ));

        $this->app->singleton(InlineImageProcessor::class, fn ($app) => new InlineImageProcessor(
            fileProcessor: $app->make(Base64FileProcessor::class),
            disk:          config('ati-servico.b64_disk', 'public'),
        ));
    }
}
