<?php

namespace Vormkracht10\LaravelOpenGraphImage;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Vormkracht10\LaravelOpenGraphImage\Commands\ClearCache;

class LaravelOpenGraphImageServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('open-graph-image')
            ->hasRoute('web')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommand(ClearCache::class)
            ->hasInstallCommand(function(InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishViews()
                    ->askToStarRepoOnGitHub('vormkracht10/laravel-open-graph-image');
            });
    }
}
