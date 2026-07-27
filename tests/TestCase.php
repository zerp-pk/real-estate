<?php

namespace Zerp\RealEstate\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zerp\RealEstate\Providers\RealEstateServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RealEstateServiceProvider::class];
    }
}
