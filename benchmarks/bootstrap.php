<?php

/*
 * Boots a Statamic application against one of the generated benchmark sites.
 *
 * Each site lives in benchmarks/.build/<size>/ and is a copy of the Testbench
 * Laravel skeleton with its own content, blueprints and Stache cache, so sizes
 * never share state and the test suite's skeleton is never touched.
 */

require __DIR__.'/../vendor/autoload.php';

use Orchestra\Testbench\Foundation\Application as Testbench;

final class BenchApplication extends Testbench
{
    /** @var array<string, mixed> */
    public static array $configOverrides = [];

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $root = dirname(__DIR__);

        foreach (['assets', 'cp', 'forms', 'routes', 'static_caching', 'stache', 'system', 'users', 'api', 'graphql', 'git', 'search', 'editions', 'revisions'] as $config) {
            if (is_file($file = "{$root}/config/{$config}.php")) {
                $app['config']->set("statamic.{$config}", require $file);
            }
        }

        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        $config->set('app.debug', false);
        $config->set('app.env', 'production');

        // Production-like: a persistent cache shared between processes, no watcher.
        $config->set('cache.default', 'file');
        $config->set('cache.stores.file', [
            'driver' => 'file',
            'path' => $app->storagePath('framework/cache/data'),
            'lock_path' => $app->storagePath('framework/cache/locks'),
        ]);
        $config->set('statamic.stache.watcher', false);
        $config->set('auth.providers.users.driver', 'statamic');
        $config->set('statamic.users.repository', 'file');
        $config->set('statamic.system.multisite', false);
        $config->set('statamic.search.indexes.default.driver', 'null');
        $config->set('statamic.search.indexes.cp', ['driver' => 'null']);
        $config->set('statamic.git.enabled', false);
        $config->set('statamic.static_caching.strategy', null);

        foreach (self::$configOverrides as $key => $value) {
            $config->set($key, $value);
        }
    }
}

function bench_site_path(string $size): string
{
    return __DIR__.'/.build/'.$size;
}

/**
 * @param  array<string, mixed>  $configOverrides
 * @param  array<int, class-string>  $extraProviders  Registered after Statamic's, e.g. an alternative Stache backend.
 */
function bench_app(string $size, array $configOverrides = [], array $extraProviders = []): Illuminate\Foundation\Application
{
    $basePath = bench_site_path($size);

    if (! is_file($basePath.'/.bench-site.json')) {
        fwrite(STDERR, "Site [{$size}] hasn't been generated. Run: php benchmarks/generate.php {$size}\n");
        exit(1);
    }

    BenchApplication::$configOverrides = $configOverrides;

    $app = BenchApplication::create(
        basePath: $basePath,
        options: [
            'load_environment_variables' => false,
            'extra' => [
                'dont-discover' => ['*'],
                'providers' => [
                    \Statamic\Providers\StatamicServiceProvider::class,
                    \Inertia\ServiceProvider::class,
                    \Rebing\GraphQL\GraphQLServiceProvider::class,
                    \Wilderborn\Partyline\ServiceProvider::class,
                    \Archetype\ServiceProvider::class,
                    ...$extraProviders,
                ],
            ],
        ],
    );

    return $app;
}

/**
 * Parses "key=value" pairs into config overrides. Values are decoded as JSON
 * where possible so booleans and numbers survive the trip.
 *
 * @param  array<int, string>  $pairs
 * @return array<string, mixed>
 */
function bench_parse_config(array $pairs): array
{
    $config = [];

    foreach ($pairs as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $decoded = json_decode($value, true);
        $config[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    return $config;
}

/**
 * Minimal --flag=value / --flag parsing. Repeated flags become arrays.
 *
 * @return array{0: array<int, string>, 1: array<string, mixed>}
 */
function bench_args(array $argv): array
{
    $positional = [];
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            $positional[] = $arg;

            continue;
        }

        [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);

        if (isset($options[$name])) {
            $options[$name] = array_merge((array) $options[$name], [$value]);
        } else {
            $options[$name] = $value;
        }
    }

    return [$positional, $options];
}

/**
 * "1k" => 1000, "50k" => 50000, "250" => 250.
 */
function bench_size_to_count(string $size): int
{
    if (preg_match('/^(\d+)k$/i', $size, $m)) {
        return (int) $m[1] * 1000;
    }

    if (ctype_digit($size)) {
        return (int) $size;
    }

    throw new InvalidArgumentException("Invalid size [{$size}]. Use e.g. 500, 1k, 10k, 50k.");
}
