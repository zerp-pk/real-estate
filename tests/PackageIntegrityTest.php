<?php

namespace Zerp\RealEstate\Tests;

/**
 * Contract tests for the package itself, independent of the host app.
 *
 * These modules are published to Packagist and installed independently, so the
 * things that break silently are the wiring, not the business logic: a renamed
 * provider stops Laravel auto-discovery without an error, a module.json that
 * disagrees with composer.json registers the module under the wrong key in
 * `add_ons`, a duplicate migration name means one migration never runs, a
 * misplaced class breaks only the request that touches it.
 *
 * None of this needs a database or the host app.
 */
class PackageIntegrityTest extends TestCase
{
    private function composerJson(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function moduleJson(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../module.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_composer_json_is_valid_json(): void
    {
        $this->assertIsArray($this->composerJson());
    }

    public function test_module_json_is_valid_json(): void
    {
        $this->assertIsArray($this->moduleJson());
    }

    /**
     * PackageSeeder scans vendor/zerp/* for module.json and keys AddOn rows off
     * it. If package_name drifts from the composer package the module is
     * installed as, the seeder registers a module nothing can enable.
     */
    public function test_module_json_package_name_matches_the_composer_package(): void
    {
        $module = $this->moduleJson();
        $composer = $this->composerJson();

        $this->assertArrayHasKey('package_name', $module);
        $this->assertSame(
            $composer['name'],
            'zerp/'.$module['package_name'],
            'module.json package_name must match the composer package name.'
        );
    }

    public function test_module_json_declares_the_fields_the_seeder_reads(): void
    {
        $module = $this->moduleJson();

        foreach (['name', 'alias', 'priority', 'version', 'package_name'] as $key) {
            $this->assertArrayHasKey($key, $module, "module.json is missing '$key'.");
        }

        $this->assertNotSame('', trim((string) $module['name']));
        $this->assertNotSame('', trim((string) $module['alias']));
        $this->assertIsNumeric($module['priority']);
    }

    /**
     * Laravel auto-discovers the module through extra.laravel.providers. A
     * provider renamed or moved without updating composer.json leaves the
     * module installed but never booted, with nothing logged.
     */
    public function test_declared_service_providers_all_exist(): void
    {
        $providers = $this->composerJson()['extra']['laravel']['providers'] ?? [];

        $this->assertNotEmpty($providers, 'The package declares no service provider, so Laravel will never boot it.');

        foreach ($providers as $provider) {
            $this->assertTrue(
                class_exists($provider),
                "Declared provider $provider does not exist."
            );
            $this->assertTrue(
                $this->app->providerIsLoaded($provider),
                "Declared provider $provider did not boot."
            );
        }
    }

    /**
     * Every class under src/ has to sit at the path its PSR-4 prefix implies,
     * or Composer's autoloader cannot find it. Checked by reading the declared
     * namespace rather than by autoloading: module classes extend host app
     * classes (App\Http\Controllers\Controller, App\Models\User,
     * App\Models\Concerns\TenantScoped) that do not exist outside an install,
     * so loading them here would fatal on the parent, not on the mapping.
     */
    public function test_every_class_in_src_sits_at_its_psr4_path(): void
    {
        $autoload = $this->composerJson()['autoload']['psr-4'] ?? [];
        $this->assertNotEmpty($autoload, 'The package declares no PSR-4 autoload mapping.');

        $prefix = array_key_first($autoload);
        $root = realpath(__DIR__.'/../'.$autoload[$prefix]);
        $this->assertNotFalse($root, "PSR-4 path {$autoload[$prefix]} does not exist.");

        $misplaced = [];

        foreach ($this->phpFilesIn($root) as $file) {
            $relative = substr($file, strlen($root) + 1);

            // Migrations and route files are loaded by path, not by class name,
            // and migrations are deliberately anonymous classes.
            if (str_starts_with($relative, 'Database/Migrations/') || str_starts_with($relative, 'Routes/')) {
                continue;
            }

            $source = file_get_contents($file);

            // Only a named type needs to be autoloadable; a file of helpers does not.
            if (!preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $type)) {
                continue;
            }

            if (!preg_match('/^\s*namespace\s+([^;]+);/m', $source, $ns)) {
                $misplaced[] = "$relative (no namespace declared)";
                continue;
            }

            $declared = trim($ns[1]).'\\'.$type[1];
            $expected = $prefix.str_replace('/', '\\', substr($relative, 0, -4));

            if ($declared !== $expected) {
                $misplaced[] = "$relative declares $declared, PSR-4 requires $expected";
            }
        }

        $this->assertSame([], $misplaced, "PSR-4 violations:\n".implode("\n", $misplaced));
    }

    /**
     * loadMigrationsFrom() keys migrations by filename. Two files sharing a name
     * across the app means one of them silently never runs.
     */
    public function test_migration_filenames_are_unique_and_well_formed(): void
    {
        $dir = __DIR__.'/../src/Database/Migrations';

        if (!is_dir($dir)) {
            $this->markTestSkipped('Package ships no migrations.');
        }

        $names = [];

        foreach (glob($dir.'/*.php') as $file) {
            $name = basename($file, '.php');

            $this->assertMatchesRegularExpression(
                '/^\d{4}_\d{2}_\d{2}_\d{6}_\w+$/',
                $name,
                "Migration $name does not follow Laravel's timestamp_name convention."
            );

            $this->assertArrayNotHasKey($name, $names, "Duplicate migration name: $name");
            $names[$name] = true;
        }

        $this->assertNotEmpty($names);
    }

    /**
     * A migration file that does not return a Migration throws only when the
     * migrator reaches it, i.e. during someone's deploy.
     */
    public function test_every_migration_returns_a_migration_instance(): void
    {
        $dir = __DIR__.'/../src/Database/Migrations';

        if (!is_dir($dir)) {
            $this->markTestSkipped('Package ships no migrations.');
        }

        foreach (glob($dir.'/*.php') as $file) {
            $migration = require $file;

            $this->assertInstanceOf(
                \Illuminate\Database\Migrations\Migration::class,
                $migration,
                basename($file).' does not return a Migration instance.'
            );
            $this->assertTrue(
                method_exists($migration, 'up'),
                basename($file).' has no up() method.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
