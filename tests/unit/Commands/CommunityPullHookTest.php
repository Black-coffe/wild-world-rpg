<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Story community-chat-bot-13 (ADR-176) — `.claude/hooks/community-pull.sh`: SessionStart
 * hook, половина канала «прод -> локаль». Тесты запускают сам bash-скрипт как отдельный
 * процесс (не грепают исходник — `feedback_source_scan_tests_are_not_coverage`), подменяя
 * `ssh` фейковым исполняемым файлом первым в `$PATH`, и изолируют `$HOME` /
 * `$CLAUDE_PROJECT_DIR` во временном каталоге на каждый прогон.
 *
 * Проверяет ровно то, что помечено 🔴 в контракте story:
 *  - `--no-header` реально присутствует в удалённой команде (а не просто написан в файле);
 *  - невалидный JSON от `community:export` отклоняется вторым рубежом — inbox не пишется;
 *  - хук никогда не валит старт сессии (код 0) при отсутствии ключа/сбое SSH;
 *  - курсор двигается по `state.json` и переживает его повреждение;
 *  - в stdout попадает только счётчик, не содержимое чата;
 *  - `.claude/community/` игнорируется git.
 *
 * @internal
 */
final class CommunityPullHookTest extends CIUnitTestCase
{
    private string $tmpRoot;
    private string $projectDir;
    private string $homeDir;
    private string $fakeBinDir;
    private string $launcherPath;
    private string $hookPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot   = $this->makeTempDir('community-pull-hook-test');
        $this->projectDir = $this->tmpRoot . '/project';
        $this->homeDir     = $this->tmpRoot . '/home';
        $this->fakeBinDir  = $this->tmpRoot . '/bin';
        $this->launcherPath = $this->tmpRoot . '/launcher.sh';
        $this->hookPath = str_replace('\\', '/', ROOTPATH) . '.claude/hooks/community-pull.sh';

        mkdir($this->projectDir, 0777, true);
        mkdir($this->homeDir . '/.ssh', 0777, true);
        mkdir($this->fakeBinDir, 0777, true);

        // Launcher избегает нестабильной передачи $PATH через proc_open()'s env-массив
        // (на Windows он молча игнорируется git-bash'ем при смешанном формате путей) —
        // PATH собирается ВНУТРИ уже запущенного bash, из его же корректного $PATH.
        file_put_contents($this->launcherPath, <<<'SH'
#!/usr/bin/env bash
export HOME="$1"
export CLAUDE_PROJECT_DIR="$2"
export PATH="$3:$PATH"
shift 3
exec bash "$@"
SH
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);

        parent::tearDown();
    }

    // -- helpers -----------------------------------------------------------------------

    private function makeTempDir(string $prefix): string
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/' . $prefix . '_' . uniqid();
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Windows: PATH-список рвётся на двоеточии в "C:/...", поэтому только для PATH-компонентов нужен /c/-вид. */
    private function toUnixPath(string $path): string
    {
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
        // Явный путь к Git Bash: голое "bash" на этой машине резолвится в WSL bash.exe
        // первым по $PATH и не видит переданный HOME/CLAUDE_PROJECT_DIR так же, как
        // производственный вызов хука через Git Bash.
        foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'bash';
    }

    private function writeFakeSsh(string $body): void
    {
        $path = $this->fakeBinDir . '/ssh';
        file_put_contents($path, "#!/usr/bin/env bash\n" . $body . "\n");
        chmod($path, 0755);
    }

    /** @return array{0: string, 1: string, 2: int} [stdout, stderr, exitCode] */
    private function runHook(): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [
                $this->bashBinary(),
                $this->launcherPath,
                $this->toUnixPath($this->homeDir),
                $this->toUnixPath($this->projectDir),
                $this->toUnixPath($this->fakeBinDir),
                $this->hookPath,
            ],
            $descriptors,
            $pipes,
            null,
            null
        );
        $this->assertIsResource($proc, 'proc_open() не смог запустить bash');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$stdout, $stderr, $code];
    }

    private function inboxDir(): string
    {
        return $this->projectDir . '/.claude/community';
    }

    private function writeState(int $since): void
    {
        mkdir($this->inboxDir(), 0777, true);
        file_put_contents($this->inboxDir() . '/state.json', json_encode(['since' => $since]));
    }

    // -- сеть/ключ недоступны: хук никогда не валит старт сессии -----------------------

    public function testMissingSshKeyExitsZeroWithExplanationAndNoInboxWritten(): void
    {
        // Ключ намеренно не создан в $this->homeDir/.ssh/.
        [$stdout, , $code] = $this->runHook();

        $this->assertSame(0, $code, 'хук обязан завершаться кодом 0 даже без ключа');
        $this->assertStringContainsString('SSH-ключ не найден', $stdout);
        $this->assertFileDoesNotExist($this->inboxDir() . '/state.json');
    }

    public function testSshFailureExitsZeroWithExplanation(): void
    {
        file_put_contents($this->homeDir . '/.ssh/wildworld_deploy', 'fake-key');
        $this->writeFakeSsh('exit 1');

        [$stdout, , $code] = $this->runHook();

        $this->assertSame(0, $code, 'сбой SSH не должен валить старт сессии');
        $this->assertStringContainsString('прод недоступен', $stdout);
        $this->assertDirectoryDoesNotExist($this->inboxDir() . '/inbox-' . date('Y-m-d') . '.json');
    }

    // -- второй рубеж: невалидный JSON отклоняется, не пишется тихо --------------------

    public function testInvalidJsonFromRemoteIsRejectedAndInboxNotWritten(): void
    {
        file_put_contents($this->homeDir . '/.ssh/wildworld_deploy', 'fake-key');
        // Симулирует ровно баг story 11: баннер `php spark` перед JSON.
        $this->writeFakeSsh("echo 'CodeIgniter v4.7.2 Command Line Tool - Server Time: ...'\necho '[]'");

        [$stdout, , $code] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('невалидный JSON', $stdout);
        $this->assertFileDoesNotExist($this->inboxDir() . '/inbox-' . date('Y-m-d') . '.json', 'мусор не должен попадать в inbox');
    }

    // -- 🔴 --no-header реально присутствует в удалённой команде -----------------------

    public function testRemoteCommandIncludesNoHeaderFlag(): void
    {
        file_put_contents($this->homeDir . '/.ssh/wildworld_deploy', 'fake-key');
        $capture = $this->fakeBinDir . '/captured-args.txt';
        $this->writeFakeSsh('printf "%s\\n" "$@" > "' . $capture . '"' . "\necho '[]'");

        $this->runHook();

        $this->assertFileExists($capture);
        $captured = file_get_contents($capture);
        $this->assertStringContainsString('--no-header', $captured, '--no-header обязателен — без него php spark печатает баннер в stdout (story 11)');
        $this->assertStringContainsString('community:export', $captured);
        $this->assertStringContainsString('--since=0', $captured);
    }

    // -- успешный путь: inbox пишется, курсор двигается, stdout не содержит контента ---

    public function testValidJsonWritesInboxAdvancesCursorAndSummarizesWithoutLeakingContent(): void
    {
        file_put_contents($this->homeDir . '/.ssh/wildworld_deploy', 'fake-key');
        $secretText = 'СЕКРЕТНЫЙ ТЕКСТ ИГРОКА не должен попасть в stdout';
        $this->writeFakeSsh(sprintf(
            "echo '%s'",
            json_encode([
                ['id' => 10, 'text' => $secretText],
                ['id' => 12, 'text' => 'второе сообщение'],
            ], JSON_UNESCAPED_UNICODE)
        ));

        [$stdout, , $code] = $this->runHook();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2', $stdout, 'сводка должна назвать счётчик новых вопросов');
        $this->assertStringNotContainsString($secretText, $stdout, 'в контекст сессии не должно попадать содержимое чата');

        $inboxFile = $this->inboxDir() . '/inbox-' . date('Y-m-d') . '.json';
        $this->assertFileExists($inboxFile);
        $decoded = json_decode((string) file_get_contents($inboxFile), true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame($secretText, $decoded[0]['text'], 'файл inbox несёт полное содержимое (в файл, не в транскрипт)');

        $state = json_decode((string) file_get_contents($this->inboxDir() . '/state.json'), true);
        $this->assertSame(12, $state['since'], 'курсор двигается на максимальный id из выгрузки');
    }

    // -- курсор: повторный запуск не тянет уже виденное ---------------------------------

    public function testCursorIsPassedAsSinceOnSubsequentRun(): void
    {
        file_put_contents($this->homeDir . '/.ssh/wildworld_deploy', 'fake-key');
        $this->writeState(12);

        $capture = $this->fakeBinDir . '/captured-args.txt';
        $this->writeFakeSsh('printf "%s\\n" "$@" > "' . $capture . '"' . "\necho '[]'");

        $this->runHook();

        $captured = file_get_contents($capture);
        $this->assertStringContainsString('--since=12', $captured, 'уже виденные id не должны запрашиваться повторно');
    }

    // -- повреждённый state.json не роняет хук, курсор сбрасывается в 0 -----------------

    public function testCorruptedStateJsonResetsCursorToZeroWithWarning(): void
    {
        file_put_contents($this->homeDir . '/.ssh/wildworld_deploy', 'fake-key');
        mkdir($this->inboxDir(), 0777, true);
        file_put_contents($this->inboxDir() . '/state.json', '{not valid json');

        $capture = $this->fakeBinDir . '/captured-args.txt';
        $this->writeFakeSsh('printf "%s\\n" "$@" > "' . $capture . '"' . "\necho '[]'");

        [$stdout, $stderr, $code] = $this->runHook();

        $this->assertSame(0, $code, 'повреждённый state.json не должен ронять хук');
        $this->assertStringContainsString('state.json повреждён', $stdout . $stderr);
        $this->assertStringContainsString('--since=0', file_get_contents($capture));
    }

    // -- .claude/community/ игнорируется git --------------------------------------------

    public function testCommunityDirIsGitIgnored(): void
    {
        // shell_exec() зовёт cmd.exe на Windows, где `; echo $?` не значит ничего — код
        // возврата смотрим напрямую через proc_close(), без POSIX-конструкций в команде.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            ['git', 'check-ignore', '-q', '.claude/community/inbox-probe.json'],
            $descriptors,
            $pipes,
            str_replace('\\', '/', ROOTPATH)
        );
        $this->assertIsResource($proc);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, 'git check-ignore обязан подтвердить игнор .claude/community/');
    }
}
