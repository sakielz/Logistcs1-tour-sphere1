<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (class_exists(ServeCommand::class)) {
            ServeCommand::$passthroughVariables = array_merge(
                ServeCommand::$passthroughVariables,
                ['SystemRoot', 'SystemDrive', 'windir', 'LOCALAPPDATA', 'TEMP', 'TMP']
            );
        }
    }
}
