<?php

/*
 * Runs scenarios across sizes, each iteration in a fresh PHP process, and
 * writes the results to benchmarks/results/<label>.json.
 *
 *   php benchmarks/run.php                                  # every generated size, every scenario
 *   php benchmarks/run.php --sizes=1k,10k --iterations=10
 *   php benchmarks/run.php --scenarios=taxonomy-popular,term-counts
 *   php benchmarks/run.php --label=sqlite --provider='App\SqliteStacheServiceProvider' \
 *       --warm-command=statamic:flatfile:warm --clear-command=statamic:flatfile:clear
 *
 * Options:
 *   --sizes=a,b          Sizes to run (default: every generated site).
 *   --scenarios=a,b      Scenarios to run (default: all). "warm" always runs first when included.
 *   --iterations=N       Timed iterations per scenario (default 5). Each scenario
 *                        also gets one discarded warm-up run first.
 *   --label=name         Result file name (default: <branch>-<sha>).
 *   --no-opcache         Run without the shared opcache file cache.
 *   --config, --provider, --clear-command, --warm-command, --refresh-command
 *                        Passed to every scenario process.
 */

require __DIR__.'/bootstrap.php';

[, $options] = bench_args($argv);

$definitions = require __DIR__.'/scenarios.php';
$scenarios = array_keys($definitions);

$sizes = isset($options['sizes'])
    ? explode(',', $options['sizes'])
    : array_values(array_map('basename', array_filter(glob(__DIR__.'/.build/*', GLOB_ONLYDIR), fn ($d) => is_file("{$d}/.bench-site.json"))));

usort($sizes, fn ($a, $b) => bench_size_to_count($a) <=> bench_size_to_count($b));

if (! $sizes) {
    fwrite(STDERR, "No sites generated. Run e.g.: php benchmarks/generate.php 1k\n");
    exit(1);
}

$selected = isset($options['scenarios']) ? explode(',', $options['scenarios']) : $scenarios;

if ($unknown = array_diff($selected, $scenarios)) {
    fwrite(STDERR, 'Unknown scenarios: '.implode(', ', $unknown)."\n");
    exit(1);
}

// "warm" leaves the cache built, so it goes first.
usort($selected, fn ($a, $b) => ($b === 'warm') <=> ($a === 'warm'));

$iterations = max(1, (int) ($options['iterations'] ?? 5));

$git = fn ($cmd) => trim((string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__))." {$cmd} 2>/dev/null"));
$branch = $git('rev-parse --abbrev-ref HEAD');
$sha = $git('rev-parse --short HEAD');
$dirty = $git('status --porcelain -- src') !== '';
$label = $options['label'] ?? preg_replace('/[^A-Za-z0-9._-]+/', '-', "{$branch}-{$sha}");

$php = [PHP_BINARY, '-d', 'memory_limit=-1'];

if (! isset($options['no-opcache'])) {
    // CLI processes don't share opcache memory, but they can share a file cache,
    // so compiled scripts are reused between runs as they would be under FPM.
    @mkdir($opcacheDir = __DIR__.'/.build/.opcache', 0755, true);
    array_push($php, '-d', 'opcache.enable_cli=1', '-d', "opcache.file_cache={$opcacheDir}", '-d', 'opcache.file_cache_only=1');
}

$passthrough = [];
foreach (['config', 'provider'] as $flag) {
    foreach ((array) ($options[$flag] ?? []) as $value) {
        $passthrough[] = "--{$flag}={$value}";
    }
}
foreach (['clear-command', 'warm-command', 'refresh-command'] as $flag) {
    if (isset($options[$flag])) {
        $passthrough[] = "--{$flag}={$options[$flag]}";
    }
}

$runOnce = function (string $size, string $scenario) use ($php, $passthrough) {
    $command = [...$php, __DIR__.'/scenario.php', $size, $scenario, ...$passthrough];

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($process);

    $lines = array_filter(explode("\n", trim($stdout)));
    $result = json_decode((string) end($lines), true);

    if ($code !== 0 || ! is_array($result)) {
        return ['error' => trim($stderr."\n".$stdout)];
    }

    return $result;
};

$stats = function (array $values) {
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);

    return [
        'median' => $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2,
        'min' => $values[0],
        'max' => $values[$n - 1],
    ];
};

$output = [
    'label' => $label,
    'branch' => $branch,
    'sha' => $sha,
    'src_dirty' => $dirty,
    'php' => PHP_VERSION,
    'opcache' => ! isset($options['no-opcache']),
    'iterations' => $iterations,
    'options' => $passthrough,
    'date' => date('c'),
    'machine' => php_uname('s').' '.php_uname('m'),
    'sites' => [],
    'results' => [],
];

fprintf(STDERR, "Label: %s%s, %d iteration(s)\n", $label, $dirty ? ' (uncommitted src changes)' : '', $iterations);

foreach ($sizes as $size) {
    $output['sites'][$size] = json_decode(file_get_contents(bench_site_path($size).'/.bench-site.json'), true)['counts'];

    fprintf(STDERR, "\n%s\n", $size);

    foreach ($selected as $scenario) {
        fprintf(STDERR, '  %-24s', $scenario);

        $warmup = $runOnce($size, $scenario);

        if (isset($warmup['error'])) {
            fprintf(STDERR, "ERROR\n%s\n", preg_replace('/^/m', '      ', $warmup['error']));
            $output['results'][$size][$scenario] = ['error' => $warmup['error']];

            continue;
        }

        $runs = [];
        for ($i = 0; $i < $iterations; $i++) {
            $runs[] = $run = $runOnce($size, $scenario);

            if (isset($run['error'])) {
                fprintf(STDERR, "ERROR\n%s\n", preg_replace('/^/m', '      ', $run['error']));
                $output['results'][$size][$scenario] = ['error' => $run['error']];

                continue 2;
            }

            fwrite(STDERR, '.');
        }

        $checksums = array_values(array_unique(array_column($runs, 'checksum')));

        $output['results'][$size][$scenario] = [
            'group' => $definitions[$scenario]['group'],
            'run_ms' => $stats(array_column($runs, 'run_ms')),
            'boot_ms' => $stats(array_column($runs, 'boot_ms')),
            'peak_mb' => round($stats(array_column($runs, 'peak_bytes'))['median'] / 1048576, 2),
            'cache_mb' => round(end($runs)['cache_bytes'] / 1048576, 2),
            'checksum' => count($checksums) === 1 ? $checksums[0] : 'unstable:'.implode(',', $checksums),
            'samples_ms' => array_column($runs, 'run_ms'),
        ];

        $r = $output['results'][$size][$scenario];
        fprintf(STDERR, " %10.2f ms  (min %.2f, max %.2f)  peak %7.2f MB\n", $r['run_ms']['median'], $r['run_ms']['min'], $r['run_ms']['max'], $r['peak_mb']);
    }
}

@mkdir(__DIR__.'/results', 0755, true);
file_put_contents($file = __DIR__."/results/{$label}.json", json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fprintf(STDERR, "\nWrote %s\n", $file);
