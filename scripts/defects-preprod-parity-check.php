<?php

/**
 * Defect check `preprod-settings-drift` (docs/defects/preprod-settings-drift.md).
 *
 * Fails (exit 1) when a game_settings snapshot shows preprod-testbot running a different game than prod:
 * any key whose value differs between the two (or exists on one side only) and is not in the declared
 * allowlist below. A preprod smoke on such a snapshot checks the change against the wrong game.
 *
 * The snapshot is a TSV, one line per key: `key<TAB>preprod<TAB>prod`, MISSING for an absent key —
 * `bash scripts/preprod-parity.sh` builds it from both databases and runs this check.
 *
 * usage: php scripts/defects-preprod-parity-check.php <snapshot.tsv>
 *        any other argument (a code path from the defects gate) — nothing to compare, exit 0
 */

declare(strict_types=1);

/**
 * Intentional differences: the reason is the whole point, a new line needs one.
 * Everything else must match prod, balance numbers included.
 */
const ALLOWED = [
    // Cron state: stamps of the last run, they differ by nature.
    '/^tips\.daily_last_broadcast$/',
    '/^returnability\.[a-z0-9_]+\.last_run$/',
    '/^pvp\.ladder\.weekly_last_broadcast$/',
    // Mass sends to the test accounts stay off on preprod.
    '/^tips\.daily_enabled$/',
    '/^returnability\.(comeback|day2|digest|streak)\.enabled$/',
    '/^returnability\.comeback\.send_hour$/',
    '/^pvp\.ladder\.broadcast_enabled$/',
    // Preprod tests ahead of prod: the community chat bot (its own chat) and the tribute spec.
    '/^community\./',
    '/^tribute\./',
    // W-REG: open registration is rehearsed on preprod before the prod flip.
    '/^web\.open_registration$/',
];

$target = $argv[1] ?? '';
if ($target === '' || ! is_file($target) || ! str_ends_with($target, '.tsv')) {
    fwrite(STDOUT, "preprod-parity-check: no snapshot given ({$target}) - nothing to compare\n");
    exit(0);
}

$same = static function (string $a, string $b): bool {
    if (is_numeric($a) && is_numeric($b)) {
        return abs((float) $a - (float) $b) < 1e-9;
    }

    return $a === $b;
};

$hits = [];
foreach (explode("\n", (string) file_get_contents($target)) as $n => $line) {
    $line = rtrim($line, "\r");
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $cols = explode("\t", $line);
    if (count($cols) !== 3) {
        fwrite(STDERR, "preprod-parity-check: {$target}:" . ($n + 1) . " is not key<TAB>preprod<TAB>prod\n");
        exit(2);
    }
    [$key, $pre, $prod] = $cols;
    if ($same($pre, $prod)) {
        continue;
    }
    foreach (ALLOWED as $re) {
        if (preg_match($re, $key) === 1) {
            continue 2;
        }
    }
    $hits[] = "{$key}: preprod={$pre} prod={$prod}";
}

if ($hits === []) {
    fwrite(STDOUT, "preprod-parity-check: preprod matches prod (allowlist aside)\n");
    exit(0);
}

foreach ($hits as $h) {
    fwrite(STDOUT, "DRIFT {$h}\n");
}
fwrite(STDOUT, 'preprod-parity-check: RED - ' . count($hits) . " setting(s) differ from prod; a preprod smoke would test another game\n");
exit(1);
