<?php

/*
 * Runs ONE scenario ONCE in this process and prints a JSON line to stdout.
 * Normally invoked by run.php, but handy on its own for profiling, e.g.
 *
 *   php benchmarks/scenario.php 10k taxonomy-popular
 *   php -d xdebug.mode=profile benchmarks/scenario.php 10k taxonomy-popular
 *
 * Options (all repeatable where it makes sense):
 *   --config=key=value       Override a config value (value is JSON-decoded if possible).
 *   --provider=FQCN          Register an extra service provider after Statamic's.
 *   --clear-command=name     Artisan command used to clear the cache (default statamic:stache:clear).
 *   --warm-command=name      Artisan command used to build the cache (default statamic:stache:warm).
 *   --refresh-command=name   Artisan command used to rebuild the cache (default statamic:stache:refresh).
 *   --dump                   Also print the scenario's result to stderr, to eyeball correctness.
 */

$processStart = hrtime(true);

require __DIR__.'/bootstrap.php';

[$positional, $options] = bench_args($argv);
[$size, $name] = $positional + [null, null];

$scenarios = require __DIR__.'/scenarios.php';

if (! $size || ! isset($scenarios[$name])) {
    fwrite(STDERR, "Usage: php benchmarks/scenario.php <size> <scenario>\nScenarios: ".implode(', ', array_keys($scenarios))."\n");
    exit(1);
}

$scenario = $scenarios[$name];

$app = bench_app(
    $size,
    bench_parse_config((array) ($options['config'] ?? [])),
    (array) ($options['provider'] ?? []),
);

$meta = json_decode(file_get_contents(bench_site_path($size).'/.bench-site.json'), true);

$ctx = [
    'size' => $size,
    'samples' => $meta['samples'],
    'clear_command' => $options['clear-command'] ?? 'statamic:stache:clear',
    'warm_command' => $options['warm-command'] ?? 'statamic:stache:warm',
    'refresh_command' => $options['refresh-command'] ?? 'statamic:stache:refresh',
    'state' => $state = new stdClass,
    // Sends a GET request through the HTTP kernel, like a real request would.
    // The body is kept on $ctx['state']->body for --dump.
    'get' => function (string $uri, array $headers = []) use ($app, $state) {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Illuminate\Http\Request::create('http://localhost'.$uri, 'GET', server: $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $state->body = $response->getContent();

        // Never time an error page as if it were a successful render.
        if ($response->getStatusCode() >= 400) {
            $exception = $response->exception ?? null;

            throw new RuntimeException("GET {$uri} returned {$response->getStatusCode()}".($exception ? ': '.$exception->getMessage() : ''), 0, $exception);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => md5((string) $state->body),
        ];
    },
];

$bootMs = (hrtime(true) - $processStart) / 1e6;

if (isset($scenario['setup'])) {
    $scenario['setup']($ctx);
}

gc_collect_cycles();
$memoryBefore = memory_get_usage();
memory_reset_peak_usage();

$start = hrtime(true);
$result = $scenario['run']($ctx);
$runMs = (hrtime(true) - $start) / 1e6;

$peak = memory_get_peak_usage() - $memoryBefore;

if (isset($options['dump'])) {
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    if (isset($state->body)) {
        fwrite(STDERR, $state->body."\n");
    }
}

echo json_encode([
    'scenario' => $name,
    'size' => $size,
    'boot_ms' => round($bootMs, 3),
    'run_ms' => round($runMs, 3),
    'peak_bytes' => $peak,
    'retained_bytes' => memory_get_usage() - $memoryBefore,
    'checksum' => $result === null ? null : substr(md5(json_encode($result)), 0, 12),
    'cache_bytes' => bench_cache_bytes($app),
]), "\n";

function bench_cache_bytes($app): int
{
    $bytes = 0;

    // The Laravel file cache (default Stache) plus anything under storage/statamic,
    // where a SQLite backend would keep its database.
    foreach ([$app->storagePath('framework/cache/data'), $app->storagePath('statamic')] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file->getSize();
        }
    }

    return $bytes;
}
