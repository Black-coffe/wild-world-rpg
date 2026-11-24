<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * s1-atomicity-1124, review round 1 (ask 6) — `php spark migrate` возвращает 0, даже когда миграция
 * бросила исключение: `Migrate::run()` ловит его, печатает блок `[Класс исключения]` и выходит
 * успехом. `set -e` в `post-deploy.sh` тогда не срабатывает, и релиз переключается на старую схему
 * (без `is_exclusive` ни один старт задачи не работает, без `uq_cr_char_resource` upsert плодит
 * дубли). `deploy/migrate.sh` — шаг миграций `post-deploy.sh` — обязан выйти ненулём и в таком
 * случае, до переключения симлинка.
 *
 * Настоящий прогон (`testRealMigrateAgainstAClosedPortFails`) ходит только на закрытый порт 1:
 * ни одна реальная БД не трогается. Остальные случаи — подменённый `PHP_BIN`, печатающий вывод
 * spark (паттерн `CheckWebhookSecretGateTest`).
 *
 * @internal
 */
final class PostDeployMigrateGateTest extends CIUnitTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ww-migrate-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testRealMigrateAgainstAClosedPortFails(): void
    {
        $this->skipUnlessClosedPortOverrideApplies();

        $wrapper = $this->dir . '/php-real';
        file_put_contents($wrapper, "#!/bin/bash\nexec " . escapeshellarg($this->unix(PHP_BINARY)) . " \"\$@\"\n");
        chmod($wrapper, 0755);

        [$out, $code] = $this->gate($this->unix($wrapper), [
            'CI_ENVIRONMENT'            => 'development',
            'database.default.hostname' => '127.0.0.1',
            'database.default.port'     => '1',
            'database.default.database' => 'ww_no_such_db_s1',
            'database.tests.hostname'   => '127.0.0.1',
            'database.tests.port'       => '1',
            'database.tests.database'   => 'ww_no_such_db_s1',
        ]);

        $this->assertNotSame(0, $code, "migrate на закрытом порту вышел успехом:\n" . $out);
        $this->assertStringContainsString('release NOT switched', $out);
    }

    public function testExceptionBlockWithExitZeroFails(): void
    {
        [$out, $code] = $this->fake(
            "Running all new migrations...\n\n[RuntimeException]\n\ncharacter_resources: 2 duplicate pairs\n\nat APPPATH/Database/Migrations/x.php:40\n",
            0
        );
        $this->assertSame(1, $code);
        $this->assertStringContainsString('release NOT switched', $out);
        $this->assertStringContainsString('duplicate pairs', $out, 'вывод spark виден в логе деплоя');
    }

    /**
     * Под `set -o pipefail` `grep -q` выходит на первом совпадении, `printf | sed` получает SIGPIPE (141), и
     * весь пайп считался неуспехом — исключение в начале длинного вывода (>~80 КБ) проходило зелёным.
     */
    public function testExceptionFollowedByLongOutputStillFails(): void
    {
        [$out, $code] = $this->fake(
            "Running all new migrations...\n\n[RuntimeException]\n\nduplicate pairs\n\n" . str_repeat(str_repeat('x', 99) . "\n", 3000),
            0
        );
        $this->assertSame(1, $code, 'исключение перед 300 КБ вывода должно валить шаг');
        $this->assertStringContainsString('release NOT switched', $out);
    }

    public function testDatabaseExceptionClassNameIsCaughtToo(): void
    {
        [, $code] = $this->fake("Running all new migrations...\n\n[CodeIgniter\\Database\\Exceptions\\DatabaseException]\n\nUnable to connect to the database.\n", 0);
        $this->assertSame(1, $code);
    }

    public function testNonZeroExitFails(): void
    {
        [$out, $code] = $this->fake("PHP Fatal error:  something\n", 255);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('release NOT switched', $out);
    }

    public function testCleanMigrateRunPasses(): void
    {
        [$out, $code] = $this->fake(
            "Running all new migrations...\n\tRunning: (App) 2026-12-25-100020_App\\Database\\Migrations\\S1CharacterResourcesUnique\nMigrations complete.\n",
            0
        );
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Migrations complete.', $out);
    }

    public function testPostDeployRunsTheGateBeforeTheSwitchAndNoBareMigrate(): void
    {
        $src  = (string) file_get_contents(ROOTPATH . 'deploy/post-deploy.sh');
        $gate = strpos($src, 'deploy/migrate.sh');
        $this->assertNotFalse($gate, 'post-deploy.sh не вызывает deploy/migrate.sh');
        $this->assertLessThan((int) strpos($src, 'SWITCH_TARGET'), $gate);
        $this->assertStringNotContainsString('php spark migrate', $src, 'голый migrate в post-deploy.sh снова глотал бы исключение');
    }

    // ── помощники ────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: int} */
    private function fake(string $output, int $exit): array
    {
        $outFile = $this->dir . '/out.txt';
        file_put_contents($outFile, $output);
        $php = $this->dir . '/php-fake';
        file_put_contents($php, "#!/bin/bash\ncat " . escapeshellarg($this->unix($outFile)) . "\nexit {$exit}\n");
        chmod($php, 0755);

        return $this->gate($this->unix($php), []);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: string, 1: int} [stdout+stderr, exit code]
     */
    private function gate(string $phpBin, array $env): array
    {
        $base = getenv();
        $proc = proc_open(
            [$this->bashBinary(), $this->unix(ROOTPATH . 'deploy/migrate.sh')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge($base, ['PHP_BIN' => $phpBin], $env)
        );
        $this->assertIsResource($proc, 'proc_open() не смог запустить bash');
        $out = (stream_get_contents($pipes[1]) ?: '') . (stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$out, proc_close($proc)];
    }

    /**
     * Переопределение из окружения CI4 применяет, только если `.env` сам не задаёт ключ (PHP
     * превращает точки имён окружения в `_`, а `.env` кладёт ключ с точками в `$_ENV` первым).
     * Если `.env` задаёт порт или хост, на котором порт не действует (сокет), прогон мог бы дойти
     * до настоящей БД — тогда тест пропускается, а не рискует. Имя базы `.env` может задавать:
     * при закрытом порту до неё не дойти, подмена имени — лишь второй запор там, где `.env` нет.
     */
    private function skipUnlessClosedPortOverrideApplies(): void
    {
        $env = is_file(ROOTPATH . '.env') ? (string) file_get_contents(ROOTPATH . '.env') : '';
        foreach (['default', 'tests'] as $group) {
            if (preg_match('/^\s*database\.' . $group . '\.port\s*=/m', $env) === 1) {
                $this->markTestSkipped(".env задаёт database.{$group}.port — переопределение порта не применится");
            }
            if (preg_match('/^\s*database\.' . $group . '\.hostname\s*=\s*[\'"]?([^\'"\s]*)/m', $env, $m) === 1) {
                $tcp = $m[1] === '127.0.0.1' || ($m[1] === 'localhost' && PHP_OS_FAMILY === 'Windows');
                if (! $tcp) {
                    $this->markTestSkipped(".env задаёт database.{$group}.hostname={$m[1]} — порт может не действовать");
                }
            }
        }
    }

    private function unix(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY !== 'Windows') {
            return $path;
        }

        return preg_replace_callback('#^([A-Za-z]):/#', static fn (array $m) => '/' . strtolower($m[1]) . '/', $path) ?? $path;
    }

    private function bashBinary(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }
        foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'bash';
    }
}
