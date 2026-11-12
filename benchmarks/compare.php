<?php

/*
 * Compares a candidate run against a baseline and gives a verdict.
 *
 *   php benchmarks/compare.php stache sqlite [--noise=5]
 *
 * (Arguments can be labels or paths to result files.)
 *
 * The verdict only considers the gating groups - cache (warm / refresh),
 * listing (CP listing views) and frontend (rendered pages) - at every size
 * both runs share. The candidate passes only if it's faster on all of them:
 *
 *   faster   median at least --noise % below the baseline
 *   same     within ±noise % - counts as NOT faster
 *   slower   median at least --noise % above the baseline
 *   differs  returned different data (checksum mismatch) - fails regardless
 *
 * "query" scenarios are shown for diagnosis but don't affect the verdict.
 */

[$files, $noise] = (function () use ($argv) {
    $files = [];
    $noise = 5.0;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--noise=')) {
            $noise = (float) substr($arg, 8);
        } else {
            $files[] = $arg;
        }
    }

    return [$files, $noise];
})();

if (count($files) !== 2) {
    fwrite(STDERR, "Usage: php benchmarks/compare.php <baseline> <candidate> [--noise=5]\n");
    exit(1);
}

$load = function (string $path) {
    foreach ([$path, __DIR__.'/'.$path, __DIR__.'/results/'.$path, __DIR__.'/results/'.$path.'.json'] as $candidate) {
        if (is_file($candidate)) {
            return json_decode(file_get_contents($candidate), true);
        }
    }

    fwrite(STDERR, "Can't find {$path}\n");
    exit(1);
};

$a = $load($files[0]);
$b = $load($files[1]);

printf("Baseline:  %s (%s, %d iterations)\n", $a['label'], $a['sha'], $a['iterations']);
printf("Candidate: %s (%s, %d iterations)\n", $b['label'], $b['sha'], $b['iterations']);
printf("Noise threshold: ±%s%%\n", $noise);

$fmtMs = fn ($ms) => $ms >= 1000 ? sprintf('%.2fs', $ms / 1000) : sprintf('%.1fms', $ms);
$gating = ['cache', 'listing', 'frontend'];
$groupOrder = ['cache' => 0, 'listing' => 1, 'frontend' => 2, 'query' => 3];

$failures = [];
$gated = 0;

foreach (array_intersect_key($a['results'], $b['results']) as $size => $scenarios) {
    printf("\n%s\n", $size);
    printf("  %-9s %-22s %10s %10s %8s %8s %10s %10s %9s\n", 'group', 'scenario', 'baseline', 'candidate', 'change', 'result', 'peak base', 'peak cand', 'cache MB');

    uksort($scenarios, fn ($x, $y) => [$groupOrder[$scenarios[$x]['group'] ?? 'query'] ?? 3, $x] <=> [$groupOrder[$scenarios[$y]['group'] ?? 'query'] ?? 3, $y]);

    foreach ($scenarios as $scenario => $ra) {
        if (! $rb = $b['results'][$size][$scenario] ?? null) {
            continue;
        }

        $group = $ra['group'] ?? $rb['group'] ?? 'query';
        $gates = in_array($group, $gating, true);

        if (isset($ra['error']) || isset($rb['error'])) {
            $who = isset($ra['error']) ? 'baseline' : 'candidate';
            printf("  %-9s %-22s %s errored\n", $group, $scenario, $who);

            if ($gates) {
                $gated++;
                $failures[] = "{$size} {$scenario}: {$who} errored";
            }

            continue;
        }

        $ma = $ra['run_ms']['median'];
        $mb = $rb['run_ms']['median'];
        $change = $ma > 0 ? ($mb - $ma) / $ma * 100 : 0;

        $differs = $ra['checksum'] !== null && $rb['checksum'] !== null && $ra['checksum'] !== $rb['checksum'];

        $result = match (true) {
            $differs => 'DIFFERS',
            $change <= -$noise => 'faster',
            $change >= $noise => 'SLOWER',
            default => 'same',
        };

        if ($gates) {
            $gated++;

            if ($result !== 'faster') {
                $failures[] = sprintf('%s %s: %s (%+.0f%%)', $size, $scenario, $result, $change);
            }
        }

        printf(
            "  %-9s %-22s %10s %10s %+7.0f%% %8s %8.1fMB %8.1fMB %9s\n",
            $group,
            $scenario,
            $fmtMs($ma),
            $fmtMs($mb),
            $change,
            $result,
            $ra['peak_mb'],
            $rb['peak_mb'],
            sprintf('%.0f/%.0f', $ra['cache_mb'], $rb['cache_mb']),
        );
    }
}

echo "\n";

if ($gated === 0) {
    echo "Verdict: no gating scenarios (cache, listing, frontend) in common.\n";
    exit(2);
}

if (! $failures) {
    printf("Verdict: PASS - candidate is faster on all %d gating measurements.\n", $gated);
    exit(0);
}

printf("Verdict: FAIL - %d of %d gating measurements aren't faster:\n", count($failures), $gated);
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}

exit(1);
