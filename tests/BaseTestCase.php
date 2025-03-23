<?php

namespace EduLazaro\Laractions\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use EduLazaro\Larascraper\LarascraperServiceProvider;

abstract class BaseTestCase extends OrchestraTestCase
{
    /**
     * Register package service providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            LarascraperServiceProvider::class,
        ];
    }
}
