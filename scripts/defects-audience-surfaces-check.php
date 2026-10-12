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
 * The table is not optional: a plan whose `## Files` (its own or its stories') touch a player surface —
 * bot handlers, the public site and `/play`, `/guide`, tip seeds — and that has no table fails too.
 * Without that, a planner who forgets the table passes silently (owner's correction 2026-10-12).
 *
 * usage: php scripts/defects-audience-surfaces-check.php <plan.md | story.md>
 *        a story file is judged by the plan.md next to it; a shipped plan, a plan with no player surface
 *        in its files, or any other argument (a code path from the defects gate) — exit 0
 */

declare(strict_types=1);

/** Player surfaces: a file here means a player meets the change. Admin views are not player-facing. */
const PLAYER_SURFACES = [
    '#^app/Controllers/Telegram/#',
    '#^app/Views/site/(?!admin/)#',
    '#^app/Views/battles/#',
    '#^app/Controllers/(Battles|Profile|Play|Site)\w*Controller\.php$#',
    '#^app/Services/Web/WebNativeScreenService\.php$#',
    '#^app/Services/Onboarding/GuideCatalog\.php$#',
    '#^app/Database/Migrations/[^/]*Tip\.php$#',
    '#^public/assets/(css|js)/wildworld-#',
];

$target = $argv[1] ?? '';
if ($target === '' || ! is_file($target) || ! str_ends_with($target, '.md')) {
    exit(0);
}

// A story file is judged by its spec's plan.md: the table lives in the plan.
$plan = $target;
if (basename($target) !== 'plan.md' && is_file(dirname($target) . '/plan.md')
    && preg_match('/^## Files\b/mu', (string) file_get_contents($target)) === 1) {
    $plan = dirname($target) . '/plan.md';
}

$text = (string) file_get_contents($plan);
if (preg_match('/^\*\*Shipped:\*\*[ \t]*\S/mu', $text) === 1) {
    exit(0); // history: the gate judges plans still in work
}

/** @return list<string> */
function filesOf(string $md): array
{
    $out = [];
    if (preg_match_all('/^## Files[^\n]*\n(.*?)(?=^## |\z)/msu', $md, $mm) > 0) {
        foreach ($mm[1] as $block) {
            if (preg_match_all('/^\s*[-*]\s+`?([^`\s]+)`?/mu', $block, $lines) > 0) {
                foreach ($lines[1] as $f) {
                    $out[] = str_replace('\\', '/', $f);
                }
            }
        }
    }

    return $out;
}

$files = filesOf($text);
if (basename($plan) === 'plan.md') {
    foreach (glob(dirname($plan) . '/*.md') ?: [] as $story) {
        if (! in_array(basename($story), ['plan.md', 'brief.md', 'journal.md', 'report.md'], true)) {
            $files = array_merge($files, filesOf((string) file_get_contents($story)));
        }
    }
}
$playerFiles = array_values(array_filter(
    array_unique($files),
    static fn (string $f): bool => array_filter(PLAYER_SURFACES, static fn (string $re): bool => preg_match($re, $f) === 1) !== []
));

if (preg_match('/^## Audience surfaces[^\n]*\n(.*?)(?=^## |\z)/msu', $text, $m) !== 1) {
    if ($playerFiles === []) {
        exit(0);
    }
    fwrite(STDERR, "entry-off-audience-path: {$plan} трогает игроцкие поверхности (" . implode(', ', array_slice($playerFiles, 0, 3))
        . (count($playerFiles) > 3 ? ', …' : '') . "), а таблицы `## Audience surfaces` нет: где бывает аудитория и где вход — не записано\n");
    exit(1);
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

if ($rows === 0 && $playerFiles !== []) {
    fwrite(STDERR, "entry-off-audience-path: таблица `## Audience surfaces` в {$plan} пустая, а план трогает игроцкие поверхности\n");
    exit(1);
}
if ($bad > 0) {
    exit(1);
}
fwrite(STDOUT, "entry-off-audience-path: {$rows} row(s), every audience surface has the entry\n");
exit(0);
