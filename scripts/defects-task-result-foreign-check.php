<?php

/**
 * Defect check `task-result-foreign-message` (docs/defects/task-result-foreign-message.md).
 *
 * Fails (exit 1) when `WebTaskResultPicker::pick()` puts a foreign background message (onboarding chain step, hint,
 * title, …) on the `/play` screen instead of the task result. A fixture is `{task, rows, expect_message_id}`;
 * `"picker": "window_only"` runs the form before the fix — the window alone, with the type filter switched off.
 *
 * usage: php scripts/defects-task-result-foreign-check.php [<fixture.json>]
 *   fixture — that one case, in the picker form it names;
 *   no argument (or a non-.json argument) — the card's fixtures through the real picker.
 */

declare(strict_types=1);

use App\Services\Web\WebTaskResultPicker;

$arg   = $argv[1] ?? '';
$root  = dirname(__DIR__);
$cases = [];
if ($arg !== '' && str_ends_with($arg, '.json')) {
    $cases[] = [$arg, true];
} else {
    foreach (['original', 'neighbour'] as $form) {
        $cases[] = [$root . "/docs/defects/fixtures/task-result-foreign-message-{$form}.json", false];
    }
}

// Группа БД `tests` — как в phpunit.xml.dist (по умолчанию бутстрап берёт SQLite3, которого нет).
// Каталоги читают метки меню через GameSettings; нет таблицы или MySQL — умолчания кода.
foreach (['hostname' => '127.0.0.1', 'database' => 'wildworld_tests', 'username' => 'root', 'password' => '', 'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'port' => '3306'] as $k => $v) {
    putenv("database.tests.{$k}={$v}");
    $_ENV["database.tests.{$k}"] = $_SERVER["database.tests.{$k}"] = $v;
}

require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

// Кэш настроек — заглушка: метки меню в подсказках берутся из умолчаний кода, а не из чужого кэша.
config(\Config\Cache::class)->handler = 'dummy';
\Config\Services::resetSingle('cache');

$windowOnly = new class () extends WebTaskResultPicker {
    public function foreignType(array $msg): ?string
    {
        return null;
    }
};

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, 'task-result-foreign-check: ERROR — ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(3);
});

$ok = true;
foreach ($cases as [$file, $asWritten]) {
    if (! is_file($file)) {
        fwrite(STDERR, "task-result-foreign-check: no such fixture: {$file}\n");
        exit(2);
    }
    $fixture = json_decode((string) file_get_contents($file), true);
    if (! is_array($fixture) || ! is_array($fixture['task'] ?? null) || ! is_array($fixture['rows'] ?? null) || ! is_int($fixture['expect_message_id'] ?? null)) {
        fwrite(STDERR, "task-result-foreign-check: fixture is not {task, rows, expect_message_id}: {$file}\n");
        exit(2);
    }
    $form   = $asWritten && ($fixture['picker'] ?? '') === 'window_only' ? 'window_only' : 'typed';
    $picker = $form === 'window_only' ? $windowOnly : new WebTaskResultPicker();
    $picked = $picker->pick($fixture['task'], $fixture['rows']);
    $got    = $picked['message_id'] ?? null;
    $pass   = $got === $fixture['expect_message_id'];
    $ok     = $ok && $pass;
    printf(
        "task-result-foreign-check: %s — %s, picker %s: picked %s, expected %d\n",
        $pass ? 'OK' : 'FAIL',
        basename($file),
        $form,
        $got === null ? 'none' : (string) $got,
        $fixture['expect_message_id']
    );
}
exit($ok ? 0 : 1);
