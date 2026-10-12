<?php

namespace App\Providers;

use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManifestLoader::class);
        $this->app->singleton(SystemSettings::class);
    }

    public function boot(): void
    {
        //
    }
}
