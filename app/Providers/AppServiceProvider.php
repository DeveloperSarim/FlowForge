<?php

namespace App\Providers;

use App\Domain\Integrations\ManifestLoader;
use App\Domain\System\SystemSettings;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // Route middleware uses these: can:admin, can:workflows.view|edit|delete, can:ai.
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        foreach (array_keys(User::PERMISSIONS) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
