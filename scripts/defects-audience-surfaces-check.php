<?php

/**
 * Defect check `entry-off-audience-path` (docs/defects/entry-off-audience-path.md).
 *
 * Fails (exit 1) when a plan puts a player-facing entry or explanation only on surfaces its audience
 * does not use. The plan declares it in a `## Audience surfaces` table:
 *
 *   | Что | Где бывает аудитория (доказательство) | Где стоит вход |
 *   |---|---|---|
 *   | блок «почему» | bot, play, battles-view (скриншот жалобы) | bot, play, battles-view |
 *
 * Surfaces are comma-separated slugs; text in parentheses is the evidence and is ignored. Every surface in
 * the second column must also be in the third, otherwise the audience never meets the feature.
 *
 * usage: php scripts/defects-audience-surfaces-check.php <plan.md>
 *        a file without the table, or any other argument (a code path from the defects gate) — exit 0
 */

declare(strict_types=1);

$target = $argv[1] ?? '';
if ($target === '' || ! is_file($target) || ! str_ends_with($target, '.md')) {
    exit(0);
}

$text = (string) file_get_contents($target);
if (preg_match('/^## Audience surfaces[^\n]*\n(.*?)(?=^## |\z)/msu', $text, $m) !== 1) {
    exit(0);
}

/** @return list<string> */
function surfaces(string $cell): array
{
    $cell = (string) preg_replace('/\([^)]*\)/u', '', $cell);
    $out  = [];
    foreach (explode(',', $cell) as $s) {
        $s = mb_strtolower(trim($s));
        if ($s !== '' && $s !== '—' && $s !== '-') {
            $out[] = $s;
        }
    }

    return $out;
}

$bad  = 0;
$rows = 0;
foreach (preg_split('/\R/u', $m[1]) ?: [] as $line) {
    $line = trim($line);
    if (! str_starts_with($line, '|') || preg_match('/^\|[\s:|-]+\|$/u', $line) === 1) {
        continue;
    }
    $cells = array_map('trim', explode('|', trim($line, '|')));
    if (count($cells) < 3 || mb_strtolower($cells[0]) === 'что') {
        continue;
    }
    $rows++;
    $missing = array_values(array_diff(surfaces($cells[1]), surfaces($cells[2])));
    if ($missing !== []) {
        $bad++;
        fwrite(STDERR, "entry-off-audience-path: «{$cells[0]}» — аудитория бывает на " . implode(', ', $missing)
            . ", а входа там нет\n");
    }
}

if ($bad > 0) {
    exit(1);
}
fwrite(STDOUT, "entry-off-audience-path: {$rows} row(s), every audience surface has the entry\n");
exit(0);
