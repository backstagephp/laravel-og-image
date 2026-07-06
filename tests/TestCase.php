<?php

namespace Backstage\OgImage\Laravel\Tests;

use Backstage\OgImage\Laravel\Facades\OgImage;
use Backstage\OgImage\Laravel\OgImageServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected static $latestResponse;

    protected function getPackageProviders($app)
    {
        return [
            OgImageServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'OgImage' => OgImage::class,
        ];
    }
}
