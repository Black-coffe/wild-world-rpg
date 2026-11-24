<?php

declare(strict_types=1);

namespace Tests\Unit\Defects;

use RuntimeException;

/**
 * defect-checks-tests-1124 — общая обвязка тестов проверок дефектов.
 *
 * `run()` запускает настоящий `scripts/defects-*-check.php` подпроцессом (`PHP_BINARY`, cwd = корень репо),
 * как его запускает человек по строке `check:` карточки. `card()` читает front matter карточки
 * `docs/defects/<id>.md`: скрипт из `check:` и список `fixtures:` — фикстура, добавленная в карточку,
 * попадает в тест без его правки.
 *
 * Не тест (имя без `Test.php`): PHPUnit его не собирает, тесты подключают его `require_once`.
 */
final class DefectCheckRunner
{
    /** Корень репозитория со слэшем на конце. */
    public static function root(): string
    {
        return rtrim(str_replace('\\', '/', ROOTPATH), '/') . '/';
    }

    /**
     * Запускает скрипт проверки; stderr слит в stdout (без второго канала нет взаимной блокировки пайпов).
     *
     * @param string      $script путь от корня репо, `scripts/defects-…-check.php`
     * @param string|null $arg    фикстура, путь или снимок; null — живой прогон без аргумента
     *
     * @return array{0: int, 1: string} [код выхода, вывод]
     */
    public static function run(string $script, ?string $arg = null): array
    {
        $cmd = [PHP_BINARY, $script];
        if ($arg !== null) {
            $cmd[] = $arg;
        }

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, self::root());
        if (! is_resource($proc)) {
            throw new RuntimeException('proc_open() не смог запустить ' . $script);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        return [proc_close($proc), $out];
    }

    /**
     * @return array{id: string, check: string, script: string, fixtures: list<string>}
     */
    public static function card(string $id): array
    {
        $path = self::root() . "docs/defects/{$id}.md";
        if (! is_file($path)) {
            throw new RuntimeException("нет карточки дефекта {$path}");
        }

        $meta = self::frontMatter((string) file_get_contents($path));

        $check  = $meta['check'] ?? '';
        $script = preg_match('~^php\s+(scripts/\S+\.php)~', $check, $m) === 1 ? $m[1] : '';

        $fixtures = [];
        $list     = trim($meta['fixtures'] ?? '');
        if (str_starts_with($list, '[') && str_ends_with($list, ']')) {
            foreach (explode(',', substr($list, 1, -1)) as $item) {
                $item = trim($item, " \t\"'");
                if ($item !== '') {
                    $fixtures[] = $item;
                }
            }
        }

        return ['id' => $id, 'check' => $check, 'script' => $script, 'fixtures' => $fixtures];
    }

    /**
     * Карточки, объявляющие `fixtures:` (README.md и INDEX.md — не карточки).
     *
     * @return list<string>
     */
    public static function cardIds(): array
    {
        $ids = [];
        foreach (glob(self::root() . 'docs/defects/*.md') ?: [] as $file) {
            $id = basename($file, '.md');
            if ($id === 'README' || $id === 'INDEX') {
                continue;
            }
            if (array_key_exists('fixtures', self::frontMatter((string) file_get_contents($file)))) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Плоский YAML front matter: `ключ: значение` между первыми двумя строками `---`.
     *
     * @return array<string, string>
     */
    private static function frontMatter(string $text): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        if (($lines[0] ?? '') !== '---') {
            return [];
        }

        $meta = [];
        for ($i = 1, $n = count($lines); $i < $n && $lines[$i] !== '---'; $i++) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $lines[$i], $m) === 1) {
                $meta[$m[1]] = trim($m[2]);
            }
        }

        return $meta;
    }
}
