<?php

declare(strict_types=1);

namespace Tests\Unit\Services\More;

use App\Services\More\MoreSurfaceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-150 Слайс 4 — контракт поверхности «⚙️ Ещё».
 *
 * Рендер проверяется БЕЗ БД: подменяем overridable-сейм `gates()`. Инварианты, которые
 * защищаем: (1) каждая фича уважает СВОЙ killswitch (dormant не обещаем); (2) фракция никогда
 * не пропадает молча — либо кнопка, либо lock (UX-DISCOVERABILITY); (3) экран не тупик;
 * (4) Оракул не дублируется (его дом — «Развлечения»); (5) markdown-парность.
 *
 * @internal
 */
final class MoreSurfaceServiceTest extends CIUnitTestCase
{
    /** @param array<string, mixed> $overrides */
    private function surface(array $overrides = []): MoreSurfaceService
    {
        $state = array_merge([
            'arena'          => false,
            'oracle'         => false,
            'referral'       => false,
            'tribute'        => false,
            'whatsnew'       => false,
            'factionChosen'  => false,
            'factionProject' => false,
            'level'          => 5,
        ], $overrides);

        return new class ($state) extends MoreSurfaceService {
            /** @param array<string, mixed> $state */
            public function __construct(private array $state)
            {
            }

            protected function gates(int $charId, int $level): array
            {
                /** @var array{arena:bool, oracle:bool, referral:bool, tribute:bool, whatsnew:bool, factionChosen:bool, factionProject:bool, level:int} */
                return $this->state;
            }
        };
    }

    /** @return array<string, mixed> */
    private function character(): array
    {
        return ['id' => 7, 'level' => 5];
    }

    private function keyboardJson(MoreSurfaceService $svc): string
    {
        return json_encode(
            $svc->buildScreen($this->character())['keyboard'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    /** Магазин, помощь и настройки есть всегда — базовый скелет группы. */
    public function testAlwaysPresentEntries(): void
    {
        $json = $this->keyboardJson($this->surface());

        foreach (['"callback_data":"shop"', '"callback_data":"entertainment"', '"callback_data":"guide"', '"callback_data":"settings"'] as $needle) {
            $this->assertStringContainsString($needle, $json);
        }
    }

    /**
     * 🔴 Арена — главная находка аудита (0 тапов при включённом killswitch). Показываем её
     * только когда `pvp.duel.enabled`, иначе не обещаем dormant-фичу.
     */
    public function testArenaShownOnlyWhenDuelEnabled(): void
    {
        $this->assertStringNotContainsString('"callback_data":"arena"', $this->keyboardJson($this->surface()));
        $this->assertStringContainsString('"callback_data":"arena"', $this->keyboardJson($this->surface(['arena' => true])));
    }

    /** Оракул НЕ дублируется отдельной кнопкой — его канон внутри «Развлечений». */
    public function testOracleIsNotDualHomed(): void
    {
        $json = $this->keyboardJson($this->surface(['oracle' => true]));

        $this->assertStringNotContainsString('"callback_data":"oracle"', $json);
        $this->assertStringContainsString('"callback_data":"entertainment"', $json);
    }

    /** Но в тексте Оракул упомянут, когда включён — иначе игрок не узнает, что он там. */
    public function testOracleMentionedInTextWhenEnabled(): void
    {
        $text = $this->surface(['oracle' => true])->buildScreen($this->character())['text'];
        $this->assertStringContainsString('Оракул', $text);

        $off = $this->surface()->buildScreen($this->character())['text'];
        $this->assertStringNotContainsString('Оракул', $off);
    }

    /** 🔴 Фракция ниже 10 уровня — lock-кнопка, а не молчаливое исчезновение. */
    public function testFactionLockButtonBelowLevel10(): void
    {
        $json = $this->keyboardJson($this->surface(['level' => 4]));

        $this->assertStringContainsString('"callback_data":"chooseFactionLocked"', $json);
        $this->assertStringContainsString('lvl 10', $json);
    }

    /** С 10 уровня и без фракции — приглашение выбрать. */
    public function testFactionChooseAtLevel10(): void
    {
        $json = $this->keyboardJson($this->surface(['level' => 10]));

        $this->assertStringContainsString('"callback_data":"chooseFaction_info"', $json);
        $this->assertStringNotContainsString('chooseFactionLocked', $json);
    }

    /** Фракция выбрана + проект включён → ведём в проект. */
    public function testFactionProjectWhenChosenAndEnabled(): void
    {
        $json = $this->keyboardJson($this->surface(['level' => 12, 'factionChosen' => true, 'factionProject' => true]));

        $this->assertStringContainsString('"callback_data":"factionProject"', $json);
    }

    /** Фракция выбрана, но проект dormant → не обещаем проект, ведём в саму фракцию. */
    public function testNoProjectButtonWhenProjectDisabled(): void
    {
        $json = $this->keyboardJson($this->surface(['level' => 12, 'factionChosen' => true, 'factionProject' => false]));

        $this->assertStringNotContainsString('"callback_data":"factionProject"', $json);
        $this->assertStringContainsString('"callback_data":"chooseFaction_info"', $json);
    }

    /** Реферал / подать / «Что нового» — каждый за своим killswitch. */
    public function testOptionalEntriesRespectTheirKillswitches(): void
    {
        $off = $this->keyboardJson($this->surface());
        $this->assertStringNotContainsString('"callback_data":"referral"', $off);
        $this->assertStringNotContainsString('"callback_data":"tributeStatus"', $off);
        $this->assertStringNotContainsString('"callback_data":"whatsNewCatalog"', $off);

        $on = $this->keyboardJson($this->surface(['referral' => true, 'tribute' => true, 'whatsnew' => true]));
        $this->assertStringContainsString('"callback_data":"referral"', $on);
        $this->assertStringContainsString('"callback_data":"tributeStatus"', $on);
        $this->assertStringContainsString('"callback_data":"whatsNewCatalog"', $on);
    }

    /** Экран не тупик: всегда есть выход в мир. */
    public function testScreenIsNeverDeadEnd(): void
    {
        $this->assertStringContainsString('"callback_data":"move"', $this->keyboardJson($this->surface()));
    }

    /**
     * Сиблинги пакуются по 2 в ряд (memory feedback_inline_keyboard_pack_sibling_buttons),
     * последний ряд — одиночный выход «Идти».
     */
    public function testButtonsPackedTwoPerRow(): void
    {
        $rows = $this->surface(['arena' => true, 'referral' => true, 'whatsnew' => true])
            ->buildScreen($this->character())['keyboard']['inline_keyboard'];

        $body = array_slice($rows, 0, -1);
        foreach ($body as $row) {
            $this->assertLessThanOrEqual(2, count($row), 'Ряд «Ещё» шире 2 кнопок.');
        }
        $this->assertCount(1, $rows[count($rows) - 1], 'Последний ряд — выход «Идти».');
    }

    /** Итоговый текст держит парные `*` (иначе Telegram отвергнет parse_mode=Markdown). */
    public function testRenderedTextHasBalancedAsterisks(): void
    {
        foreach ([[], ['arena' => true, 'oracle' => true, 'referral' => true, 'tribute' => true, 'whatsnew' => true, 'factionChosen' => true, 'level' => 20]] as $state) {
            $text = $this->surface($state)->buildScreen($this->character())['text'];
            $this->assertSame(0, substr_count($text, '*') % 2, 'Непарная `*` в тексте «Ещё».');
        }
    }
}
