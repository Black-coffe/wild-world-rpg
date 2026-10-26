<?php

/**
 * Defect check `bot-lexicon-in-web` (docs/defects/bot-lexicon-in-web.md).
 *
 * Fails (exit 1) when player text that also reaches the web client (`/play` renders the bot's
 * messages through the bridge, ADR-189) names a place that exists only in Telegram:
 *   - «в нижнем меню» / «нижнее меню» — the web has a dock; say «внизу экрана»;
 *   - «клавиатуры ниже» — say «кнопками ниже»;
 *   - «экран бота» — on the web there is no bot screen;
 *   - a count of menu buttons («шесть кнопок») — the bot has six, the web dock eight.
 * «/команды» are NOT caught: the `/play` input routes them to the same commands.
 *
 * Only string literals and inline HTML are read (PHP tokenizer), never comments. Without an
 * argument it scans app/ minus app/Database/Migrations, plus tip-seed migrations newer than the
 * fix migration (older ones are applied history). A hit passes only when it is listed in
 * ALLOWED below with its reason — there is no opt-out marker in the text itself.
 *
 * usage: php scripts/defects-bot-lexicon-check.php [<file-or-dir>]   (default: app/)
 */

declare(strict_types=1);

const PATTERNS = [
    '/[Нн]ижн[а-яё]* меню/u'                  => 'нижнее меню',
    '/клавиатур[а-яё]* ниже/u'                 => 'клавиатуры ниже',
    '/экран[а-яё]* бота/u'                     => 'экран бота',
    '/(?:[Шш]ест[ьи]|[Сс]ем[ьи]|[Вв]осем[ьи]) кноп/u' => 'счёт кнопок меню',
    '/[Нн]ижн[а-яё]* панел/u'                  => 'нижняя панель',
    // «☰» есть только в Telegram: литерал с ним сам говорит «в Telegram» (W-REG.3).
    '/^(?![\s\S]*Telegram)[\s\S]*☰/u'          => 'значок «☰» без «в Telegram»',
];

/** Text that never reaches the web: file suffix => [fragment => reason]. */
const ALLOWED = [
    'app/Controllers/Telegram/Commands/MenuCommand.php' => [
        'Показать нижнее меню' => 'описание команды /menu — только в списке команд Telegram (/help, «☰»)',
    ],
    'app/Services/Telegram/BotMenuService.php' => [
        '🚀 Старт и возврат нижнего меню' => 'описание setMyCommands — только в меню команд Telegram',
        '🧭 Показать нижнее меню'        => 'описание setMyCommands — только в меню команд Telegram',
    ],
];

/** Tip seeds at or before this migration prefix are applied history and are not scanned. */
const FIX_MIGRATION_PREFIX = '2026-12-21-100000';

$target = $argv[1] ?? null;
$root   = str_replace('\\', '/', (string) getcwd());

$files = [];
if ($target === null) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (! $f instanceof SplFileInfo || ! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) {
            continue;
        }
        $path = str_replace('\\', '/', $f->getPathname());
        if (str_starts_with($path, 'app/Database/Migrations/')) {
            $name = $f->getFilename();
            if (! preg_match('/Seed\w*Tip\.php$/', $name) || strcmp(substr($name, 0, 17), FIX_MIGRATION_PREFIX) <= 0) {
                continue;
            }
        }
        $files[] = $path;
    }
} elseif (is_dir($target)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
} elseif (is_file($target)) {
    $files[] = str_replace('\\', '/', $target);
} else {
    fwrite(STDERR, "bot-lexicon-check: no such path: {$target}\n");
    exit(2);
}

$hits = [];
foreach ($files as $file) {
    $rel     = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    $allowed = [];
    foreach (ALLOWED as $suffix => $fragments) {
        if (str_ends_with($rel, $suffix)) {
            $allowed = $fragments;
        }
    }

    foreach (token_get_all((string) file_get_contents($file)) as $tok) {
        if (! is_array($tok) || ! in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            continue;
        }
        foreach (PATTERNS as $re => $label) {
            if (! preg_match($re, $tok[1])) {
                continue;
            }
            $ok = false;
            foreach (array_keys($allowed) as $fragment) {
                if (str_contains($tok[1], $fragment)) {
                    $ok = true;
                }
            }
            if (! $ok) {
                $snippet = trim((string) preg_replace('/\s+/u', ' ', mb_substr($tok[1], 0, 90)));
                $hits[]  = "{$rel}:{$tok[2]}: {$label} — {$snippet}";
            }
        }
    }
}

if ($hits !== []) {
    fwrite(STDERR, "bot-lexicon-check: player text names a Telegram-only place (docs/defects/bot-lexicon-in-web.md):\n");
    foreach ($hits as $h) {
        fwrite(STDERR, "  {$h}\n");
    }
    fwrite(STDERR, "  -> one phrase for both clients: «кнопка «…» внизу экрана», «кнопками ниже», no button count;\n");
    fwrite(STDERR, "     text that never reaches the web goes into ALLOWED in this script with its reason.\n");
    exit(1);
}

echo 'bot-lexicon-check: ok (' . count($files) . " files)\n";
exit(0);
