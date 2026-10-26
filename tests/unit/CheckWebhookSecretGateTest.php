<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * s4-deploy-secret-gate — `deploy/check-webhook-secret.sh` не даёт `post-deploy.sh` переключить
 * релиз на production без `telegram.WEBHOOK_SECRET` (после S4 такой вебхук отклоняет все апдейты).
 * Скрипт запускается настоящим bash на фикстурах `.env` (паттерн `CommunityPullHookTest`).
 *
 * @internal
 */
final class CheckWebhookSecretGateTest extends CIUnitTestCase
{
    private const SECRET = 'f00dbabe5ec7e7f00dbabe5ec7e7f00dbabe5ec7e7f00dbabe5ec7e7f00dbabe';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ww-secret-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dir . '/.env')) {
            unlink($this->dir . '/.env');
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testProductionWithEmptySecretFails(): void
    {
        // Исходный случай: пустое значение в production.
        [$out, $code] = $this->gate("CI_ENVIRONMENT = production\ntelegram.WEBHOOK_SECRET = ''\n");
        $this->assertSame(1, $code);
        $this->assertStringContainsString('release NOT switched', $out);
    }

    public function testProductionWithCommentedOrMissingSecretFails(): void
    {
        // Соседняя форма: строка закомментирована (как в .env.example) — секрета нет.
        [, $code] = $this->gate("CI_ENVIRONMENT = production\n# telegram.WEBHOOK_SECRET = '" . self::SECRET . "'\n");
        $this->assertSame(1, $code);

        [, $code] = $this->gate("CI_ENVIRONMENT = production\r\ntelegram.API_KEY = 'x'\r\n");
        $this->assertSame(1, $code, 'строки нет вовсе, CRLF');
    }

    public function testProductionWithSecretPassesAndNeverPrintsIt(): void
    {
        [$out, $code] = $this->gate("CI_ENVIRONMENT = production\ntelegram.WEBHOOK_SECRET = '" . self::SECRET . "'\n");
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString(self::SECRET, $out);
    }

    public function testNonProductionWithoutSecretPasses(): void
    {
        [, $code] = $this->gate("CI_ENVIRONMENT = development\n");
        $this->assertSame(0, $code);
    }

    public function testPostDeployRunsGateBeforeComposerAndSymlinkSwitch(): void
    {
        $src  = (string) file_get_contents(ROOTPATH . 'deploy/post-deploy.sh');
        $gate = strpos($src, 'deploy/check-webhook-secret.sh');
        $this->assertNotFalse($gate);
        $this->assertLessThan((int) strpos($src, 'composer install'), $gate);
        $this->assertLessThan((int) strpos($src, 'SWITCH_TARGET'), $gate);
    }

    /** @return array{0: string, 1: int} [stdout+stderr, exit code] */
    private function gate(string $env): array
    {
        $file = $this->dir . '/.env';
        file_put_contents($file, $env);
        $proc = proc_open(
            [$this->bashBinary(), $this->unix(ROOTPATH . 'deploy/check-webhook-secret.sh'), $this->unix($file)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'proc_open() не смог запустить bash');
        $out = (stream_get_contents($pipes[1]) ?: '') . (stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$out, proc_close($proc)];
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
        // Голое "bash" на Windows может резолвиться в WSL — берём Git Bash (как CommunityPullHookTest).
        foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'bash';
    }
}
