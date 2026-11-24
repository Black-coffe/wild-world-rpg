<?php

declare(strict_types=1);

namespace Tests\Unit\Defects;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/DefectCheckRunner.php';

/**
 * defect-checks-tests-1124 S1 — пять проверок дефектов по коду (`scripts/defects-*-check.php`) идут в наборе
 * PHPUnit, а не ручным шагом: старый гейт `defects-check.sh` ушёл вместе со старым ульем.
 *
 * Каждый случай запускает настоящий скрипт подпроцессом (`PHP_BINARY`, cwd = корень репо): ручная команда
 * из карточки и тест — один источник правды. Два вида случаев:
 *  - каждая фикстура из `fixtures:` карточки `docs/defects/<id>.md` валит скрипт выходом 1 (гейт не слеп);
 *    фикстура, добавленная в карточку, проверяется без правки теста;
 *  - чистый вход (похожий, но правильный код / снимок без расхождений) и живой прогон по `app/` выходят 0
 *    (дефект не вернулся, проверка не красна всегда).
 *
 * Живой прогон `task-result-foreign` — в `tests/database/DefectTaskResultForeignCheckTest.php`: его каталоги
 * читают GameSettings, без MySQL он тратит ~29 с на таймауты соединения (с MySQL — меньше секунды).
 * `preprod-parity` без снимка обеих БД живым не бывает: здесь чистый синтетический снимок, живое сравнение —
 * `bash scripts/preprod-parity.sh` перед preprod-смоуком.
 *
 * @internal
 */
final class DefectCodeChecksTest extends CIUnitTestCase
{
    /** Карточки этой story: проверка по коду или снимку, не по планам (`entry-off-audience-path` — S2). */
    private const CODE_CARDS = [
        'bot-lexicon-in-web',
        'confirm-repeats-irreversible',
        'duel-outcome-not-decided-by-fight',
        'preprod-settings-drift',
        'task-result-foreign-message',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ww-defect-check-' . bin2hex(random_bytes(4));
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function declaredFixtures(): iterable
    {
        foreach (self::CODE_CARDS as $id) {
            foreach (DefectCheckRunner::card($id)['fixtures'] as $fixture) {
                yield basename($fixture) => [$id, $fixture];
            }
        }
    }

    #[DataProvider('declaredFixtures')]
    public function testDeclaredFixtureMakesTheCheckFail(string $id, string $fixture): void
    {
        $card = DefectCheckRunner::card($id);
        $this->assertFileExists(DefectCheckRunner::root() . $fixture, "карточка {$id} называет фикстуру, которой нет");

        [$code, $out] = DefectCheckRunner::run($card['script'], $fixture);

        $this->assertSame(1, $code, "{$card['script']} {$fixture} должен выйти 1 (дефект найден), вышел {$code}:\n{$out}");
    }

    public function testEveryCodeCardDeclaresAScriptAndFixtures(): void
    {
        foreach (self::CODE_CARDS as $id) {
            $card = DefectCheckRunner::card($id);
            $this->assertFileExists(DefectCheckRunner::root() . $card['script'], "карточка {$id}: check не называет существующий скрипт");
            $this->assertNotSame([], $card['fixtures'], "карточка {$id} не объявляет фикстур — гейт нечем проверить на слепоту");
        }
    }

    public function testEveryFixtureFileIsDeclaredByACard(): void
    {
        $declared = [];
        foreach (DefectCheckRunner::cardIds() as $id) {
            foreach (DefectCheckRunner::card($id)['fixtures'] as $fixture) {
                $declared[] = $fixture;
            }
        }

        $onDisk = [];
        foreach (glob(DefectCheckRunner::root() . 'docs/defects/fixtures/*') ?: [] as $file) {
            $onDisk[] = 'docs/defects/fixtures/' . basename($file);
        }

        $this->assertNotSame([], $onDisk);
        $this->assertSame([], array_values(array_diff($onDisk, $declared)), 'фикстура без карточки не проверяется ни одним тестом');
    }

    public function testBotLexiconPassesOnCleanPlayerText(): void
    {
        file_put_contents($this->dir . '/Clean.php', "<?php\n// нижнее меню — в комментарии не ловится\n\$t = 'Нажми кнопку внизу экрана или кнопками ниже.';\n");

        [$code, $out] = DefectCheckRunner::run('scripts/defects-bot-lexicon-check.php', $this->dir);

        $this->assertSame(0, $code, $out);
    }

    public function testConfirmOncePassesOnTokenedCall(): void
    {
        file_put_contents(
            $this->dir . '/Clean.php',
            "<?php\n\$cb = 'bulkSell_go_' . \$percent . '_' . \$token;\n\$r = \$this->trade->bulkSellResources(['id' => 1], \$percent, \$rarity, \$token);\n"
        );

        [$code, $out] = DefectCheckRunner::run('scripts/defects-confirm-once-check.php', $this->dir);

        $this->assertSame(0, $code, $out);
    }

    public function testPreprodParityPassesOnCleanSnapshot(): void
    {
        $tsv = $this->dir . '/clean.tsv';
        file_put_contents($tsv, implode("\n", [
            '# same game: equal values, numbers in another spelling, differences only in the allowlist',
            "onboarding.fast_start.enabled\t1\t1",
            "progression.early.taper_level\t10\t10.0",
            "npc.march_encounter_chance\t0.0500\t0.05",
            "tips.daily_last_broadcast\t2026-09-26\t2026-10-11",
            "tips.daily_enabled\t0\t1",
            "community.bot.enabled\t1\tMISSING",
        ]) . "\n");

        [$code, $out] = DefectCheckRunner::run('scripts/defects-preprod-parity-check.php', $tsv);

        $this->assertSame(0, $code, $out);
    }

    public function testBotLexiconLiveRunOnAppIsClean(): void
    {
        [$code, $out] = DefectCheckRunner::run('scripts/defects-bot-lexicon-check.php');

        $this->assertSame(0, $code, "бот-лексика вернулась в текст для обоих клиентов:\n{$out}");
    }

    public function testConfirmOnceLiveRunOnAppIsClean(): void
    {
        [$code, $out] = DefectCheckRunner::run('scripts/defects-confirm-once-check.php');

        $this->assertSame(0, $code, "подтверждение опта снова исполнимо повторно:\n{$out}");
    }

    public function testDuelKnockoutLiveRunOnCurrentDefaultsIsClean(): void
    {
        [$code, $out] = DefectCheckRunner::run('scripts/defects-duel-knockout-check.php');

        $this->assertSame(0, $code, "исход равной дуэли снова решает не бой:\n{$out}");
    }
}
