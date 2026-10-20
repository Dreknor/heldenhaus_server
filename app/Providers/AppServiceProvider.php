<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laufende Events je Familie, einmal pro Anfrage bzw. Job gelesen
        $this->app->scoped(\App\Domain\Events\ActiveEvents::class);
    }

    public function boot(): void
    {
        Gate::define('admin', fn ($user) => (bool) $user->is_admin);
    }
}
