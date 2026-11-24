<?php

declare(strict_types=1);

namespace Tests\Unit\Defects;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/DefectCheckRunner.php';

/**
 * defect-checks-tests-1124 S2 — проверка `entry-off-audience-path` (`scripts/defects-audience-surfaces-check.php`)
 * идёт в наборе PHPUnit: она судит не код, а планы `docs/specs/<slug>/plan.md`.
 *
 * Как в S1, скрипт запускается подпроцессом — ручная команда из карточки и тест один источник правды:
 *  - каждая фикстура из `fixtures:` карточки валит скрипт выходом 1 (гейт не слеп);
 *  - чистый синтетический план с игроцкими файлами и полной таблицей `## Audience surfaces` выходит 0;
 *  - живой прогон: каждый план без заполненного `**Shipped:**` вне списка `LEGACY` выходит 0.
 *
 * `LEGACY` — планы 2026-08-19…09-04, написанные до проверки (добавлена 2026-10-12): выпущены или спят без
 * строки Shipped. Список заморожен и только сокращается: запись, чей план теперь проходит, получил Shipped
 * или исчез, валит тест — её надо вычеркнуть. Новый план в список не добавляется, он чинится.
 *
 * @internal
 */
final class DefectAudienceSurfacesTest extends CIUnitTestCase
{
    private const CARD = 'entry-off-audience-path';

    /** Замороженный список планов до проверки; только сокращается. */
    private const LEGACY = [
        'backpack-teleport-base-choice',
        'community-chat-bot',
        'craft-shortfall-buy',
        'exploit-fix',
        'storage-craft-insurance',
        'transport-system',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ww-defect-audience-' . bin2hex(random_bytes(4));
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
     * @return iterable<string, array{string}>
     */
    public static function declaredFixtures(): iterable
    {
        foreach (DefectCheckRunner::card(self::CARD)['fixtures'] as $fixture) {
            yield basename($fixture) => [$fixture];
        }
    }

    public function testCardDeclaresScriptAndFixtures(): void
    {
        $card = DefectCheckRunner::card(self::CARD);

        $this->assertSame('scripts/defects-audience-surfaces-check.php', $card['script']);
        $this->assertFileExists(DefectCheckRunner::root() . $card['script']);
        $this->assertGreaterThanOrEqual(2, count($card['fixtures']), 'гейт нечем проверить на слепоту');
    }

    #[DataProvider('declaredFixtures')]
    public function testDeclaredFixtureMakesTheCheckFail(string $fixture): void
    {
        $card = DefectCheckRunner::card(self::CARD);
        $this->assertFileExists(DefectCheckRunner::root() . $fixture, 'карточка называет фикстуру, которой нет');

        [$code, $out] = DefectCheckRunner::run($card['script'], $fixture);

        $this->assertSame(1, $code, "{$card['script']} {$fixture} должен выйти 1 (дефект найден), вышел {$code}:\n{$out}");
    }

    public function testCleanPlanWithFullAudienceTablePasses(): void
    {
        $plan = $this->dir . '/plan.md';
        file_put_contents($plan, implode("\n", [
            '# Clean plan',
            '',
            '## Audience surfaces',
            '| Что | Где бывает аудитория (доказательство) | Где стоит вход |',
            '|---|---|---|',
            '| блок «почему» | bot, play, battles-view (скриншот жалобы) | bot, play, battles-view, site |',
            '',
            '## Files',
            '- `app/Controllers/Telegram/Commands/Actions/BattleAction.php`',
            '- `app/Views/battles/show.php`',
            '',
        ]));

        [$code, $out] = DefectCheckRunner::run('scripts/defects-audience-surfaces-check.php', $plan);

        $this->assertSame(0, $code, $out);
    }

    public function testEveryUnshippedPlanHasItsEntryWhereTheAudienceIs(): void
    {
        $failed  = [];
        $checked = 0;
        foreach (self::unshippedPlans() as $slug => $plan) {
            if (in_array($slug, self::LEGACY, true)) {
                continue;
            }
            $checked++;
            [$code, $out] = DefectCheckRunner::run('scripts/defects-audience-surfaces-check.php', $plan);
            if ($code !== 0) {
                $failed[] = "{$plan} (exit {$code}): " . trim($out);
            }
        }

        $this->assertGreaterThan(0, $checked + count(self::LEGACY), 'не найдено ни одного плана в docs/specs');
        $this->assertSame([], $failed, "план не ставит вход туда, где бывает аудитория:\n" . implode("\n", $failed));
    }

    public function testLegacyListOnlyShrinks(): void
    {
        $unshipped = self::unshippedPlans();
        $stale     = [];
        foreach (self::LEGACY as $slug) {
            if (! isset($unshipped[$slug])) {
                $stale[] = "{$slug}: плана нет или в нём заполнен **Shipped:** — вычеркни из LEGACY";

                continue;
            }
            [$code] = DefectCheckRunner::run('scripts/defects-audience-surfaces-check.php', $unshipped[$slug]);
            if ($code === 0) {
                $stale[] = "{$slug}: план теперь проходит проверку — вычеркни из LEGACY";
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * Планы без заполненного `**Shipped:**` (то же правило, что в скрипте): slug => путь от корня репо.
     *
     * @return array<string, string>
     */
    private static function unshippedPlans(): array
    {
        $plans = [];
        foreach (glob(DefectCheckRunner::root() . 'docs/specs/*/plan.md') ?: [] as $file) {
            if (preg_match('/^\*\*Shipped:\*\*[ \t]*\S/mu', (string) file_get_contents($file)) === 1) {
                continue;
            }
            $slug         = basename(dirname($file));
            $plans[$slug] = "docs/specs/{$slug}/plan.md";
        }
        ksort($plans);

        return $plans;
    }
}
