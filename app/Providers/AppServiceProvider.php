<?php

namespace App\Providers;

use App\Domain\Updater\DeploymentEnvironment;
use App\Domain\Updater\GithubReleaseSource;
use App\Domain\Updater\LocalDeploymentEnvironment;
use App\Domain\Updater\ManifestVerifier;
use App\Domain\Updater\ReleaseSource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReleaseSource::class, fn () => new GithubReleaseSource(
            config('updater.github.repository'),
            config('updater.github.token'),
        ));

        $this->app->bind(ManifestVerifier::class, fn () => new ManifestVerifier(config('updater.public_key')));

        $this->app->bind(DeploymentEnvironment::class, fn () => new LocalDeploymentEnvironment(
            config('updater.deploy_root'),
            config('updater.php_binary'),
        ));
    }

    public function boot(): void
    {
        Gate::define('admin', fn ($user) => (bool) $user->is_admin);
    }
}
