<?php

/*
 * Compares the Stache with the SQLite Stache, alternating between them so both
 * see the same machine conditions, one fresh PHP process per run.
 *
 *   php benchmarks/ab.php <sizes> [scenarios] [rounds]
 *   php benchmarks/ab.php 1k,10k
 *   php benchmarks/ab.php 50k warm,refresh,render-home 3
 *
 * Scenarios default to all of them, rounds to 5. Each scenario runs once per
 * backend first (discarded), then alternates. The SQLite runs use their own
 * cache store, so neither backend's runs change what the other finds.
 *
 * Prints a table of medians, the change, the rounds SQLite won, peak memory,
 * and whether both returned the same results (their checksums matched), and
 * writes the runs to benchmarks/results/ab-<date>.json.
 */

$root = dirname(__DIR__);

$sizes = explode(',', $argv[1] ?? '') ?: [];
$scenarios = isset($argv[2]) ? explode(',', $argv[2]) : array_keys(require __DIR__.'/scenarios.php');
$rounds = (int) ($argv[3] ?? 5);

if (! array_filter($sizes)) {
    fwrite(STDERR, "Usage: php benchmarks/ab.php <sizes> [scenarios] [rounds]\n");
    exit(1);
}

$modes = [
    'stache' => [],
    'sqlite' => ['--config=statamic.stache.sqlite.enabled=true', '--config=statamic.stache.cache_store="file-alt"'],
];

@mkdir($opcache = __DIR__.'/.build/.opcache', 0755, true);

$run = function ($size, $scenario, $flags) use ($opcache) {
    $command = array_merge(
        [PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'opcache.enable_cli=1', '-d', "opcache.file_cache={$opcache}", '-d', 'opcache.file_cache_only=1'],
        [__DIR__.'/scenario.php', $size, $scenario],
        $flags
    );

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);

    $lines = array_filter(explode("\n", trim($out)));
    $result = json_decode((string) end($lines), true);

    if (! $result) {
        fwrite(STDERR, "FAILED {$size} {$scenario} ".implode(' ', $flags)."\n".substr($err.$out, 0, 800)."\n");
        exit(1);
    }

    return $result;
};

$median = function (array $values) {
    sort($values);
    $n = count($values);

    return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
};

$output = ['date' => date('c'), 'php' => PHP_VERSION, 'rounds' => $rounds, 'results' => []];

printf("%-5s %-22s %10s %10s %7s %6s %9s %9s %s\n", 'size', 'scenario', 'stache', 'sqlite', 'change', 'wins', 'peak st', 'peak sq', 'same?');

foreach ($sizes as $size) {
    foreach ($scenarios as $scenario) {
        foreach ($modes as $flags) {
            $run($size, $scenario, $flags);
        }

        $results = ['stache' => [], 'sqlite' => []];

        for ($i = 0; $i < $rounds; $i++) {
            foreach ($modes as $mode => $flags) {
                $results[$mode][] = $run($size, $scenario, $flags);
            }
        }

        $a = array_column($results['stache'], 'run_ms');
        $b = array_column($results['sqlite'], 'run_ms');
        $wins = count(array_filter(array_map(fn ($x, $y) => $y < $x, $a, $b)));
        $sums = array_unique(array_merge(array_column($results['stache'], 'checksum'), array_column($results['sqlite'], 'checksum')));
        $same = count($sums) === 1 ? 'yes' : (in_array(null, $sums, true) ? '-' : 'NO');

        printf(
            "%-5s %-22s %8.1fms %8.1fms %+6.0f%% %3d/%-2d %7.1fMB %7.1fMB %s\n",
            $size, $scenario, $median($a), $median($b), ($median($b) - $median($a)) / $median($a) * 100, $wins, $rounds,
            $median(array_column($results['stache'], 'peak_bytes')) / 1048576,
            $median(array_column($results['sqlite'], 'peak_bytes')) / 1048576,
            $same
        );

        $output['results'][$size][$scenario] = $results;
    }
}

@mkdir(__DIR__.'/results', 0755, true);
file_put_contents(__DIR__.'/results/ab-'.date('Ymd-His').'.json', json_encode($output, JSON_PRETTY_PRINT));
