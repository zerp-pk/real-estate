<?php

namespace Zerp\RealEstate\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class RealEstateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routesPath = __DIR__.'/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $apiRoutesPath = __DIR__.'/../Routes/api.php';
        if (file_exists($apiRoutesPath)) {
            $this->loadRoutesFrom($apiRoutesPath);
        }

        // Scoped Swagger/OpenAPI docs for this module at /docs/real-estate.
        if (class_exists(\Dedoc\Scramble\Scramble::class)) {
            \Dedoc\Scramble\Scramble::registerApi('real-estate', [
                'api_path' => 'api/real-estate',
                'info' => ['version' => \Composer\InstalledVersions::getPrettyVersion('zerp/real-estate') ?? '1.0.0', 'description' => 'Zerp Real Estate module REST API for mobile and third-party clients.'],
                'ui' => ['title' => 'Zerp Real Estate API'],
            ])->expose(ui: '/docs/real-estate', document: '/docs/real-estate.json');
        }

        $migrationsPath = __DIR__.'/../Database/Migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        // Whitelist orderBy against real table columns + asc|desc so a crafted
        // ?sort=/?direction= cannot be interpolated into SQL. Part of the platform-wide sweep tracked on zerp-pk/zerp#39.
        //
        // Registered here rather than shared: these modules are installed
        // independently and declare no common dependency, so a module cannot rely
        // on another having booted. The guard keeps whichever loads first.
        //
        // hasGlobalMacro, not hasMacro: on an Eloquent builder the latter is an
        // instance method for per-builder macros and cannot be called statically.
        if (! Builder::hasGlobalMacro('sortSafe')) {
            Builder::macro('sortSafe', function ($sort, $direction = null, $defaultColumn = 'created_at', $defaultDirection = 'desc') {
                $table = $this->getModel()->getTable();
                $column = ($sort && Schema::hasColumn($table, $sort)) ? $sort : $defaultColumn;
                $direction = in_array(strtolower((string) $direction), ['asc', 'desc'], true)
                    ? strtolower($direction)
                    : $defaultDirection;

                return $this->orderBy($column, $direction);
            });
        }
    }

    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
    }
}
