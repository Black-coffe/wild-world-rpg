<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\CharacterService;
use App\Services\Player\CharacterSheetService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * W2.N1-01 (ADR-190) — модель персонажа и два её рендерера.
 *
 * Карточка бота теперь рисуется из модели: текст на фикстуре обязан совпасть с тем, что печатала
 * прежняя `showCharacterInfo()` (порядок строк, эмодзи, сырые числа, Markdown). HUD собирается
 * чистой функцией: ближайшая по сроку задача, остальные — счётчиком.
 *
 * @internal
 */
final class CharacterSheetServiceTest extends CIUnitTestCase
{
    public function testCardTextMatchesPreRefactorFormatOnFullFixture(): void
    {
        $expected = "🤖 *Персонаж Ворон 7*\n"
            . "🏳️ *Фракция:* Вольные\n"
            . "🧭 *Координаты:* X=12 Y=7 | 🌄 Пустошь\n"
            . "🌟 *Цель:* построй укрытие\n"
            . "🎢 *Изучено ячеек:* 42\n"
            . "💼 *Всего видов ресурсов:* 9\n"
            . "⏳ *В игре:* 1 мес. 2 дн. 3 чс.\n"
            . "📈 *Уровень:* 4\n"
            . "🪜 *До уровня 5:* ▰▰▰▰▰▰▱▱▱▱ 68% — набрано 5.5 из 8.0\n"
            . "🔓 На 5 уровне: рынок\n"
            . "🌟 *Опыт:* 31.20\n"
            . "🤸‍♂️ *Ловкость:* 2.00\n"
            . "🧠 *Интеллект:* 1.50\n"
            . "💪 *Сила:* 4.10\n\n"
            . "💖 *Здоровье:* 87.50\n"
            . "🥱 *Выносливость:* 64.00\n\n"
            . "💹 *Карма торговли:* 3\n"
            . "🧰 Есть 💰*12,480* золота\n"
            . "🩺 *Раны:*\n"
            . "🦴 *Перелом* — медленнее\n"
            . "_Еда их не снимает — нужен предмет из «💊 Аптечки»._\n\n"
            . "🔥 Серия: 3 дня\n"
            . "🎯 До вехи: 4 дня\n"
            . "🎖 *Титул:* Первопроходец\n"
            . "\n"
            . "🛡 *Броня:* Куртка, Сапоги\n"
            . "⚔️ *Оружие:* Нож\n"
            . "🎓 *Специализация:* Механик\n"
            . "🛡 *Боевой дрон:* активен `15` мин (+10% инициативы)\n";

        $this->assertSame($expected, CharacterService::cardText($this->sheet()));
    }

    public function testCardTextWithoutOptionalFeaturesKeepsBareCard(): void
    {
        $sheet = array_merge($this->sheet(), [
            'faction' => null, 'cell' => null, 'polar_line' => null, 'ladder_line' => null, 'unlock_line' => null,
            'debuffs' => [], 'streak_line' => null, 'milestone_line' => null, 'title' => null,
            'armor' => null, 'weapon' => null, 'specialization' => null, 'drone' => null, 'gold' => 0,
        ]);
        $text = CharacterService::cardText($sheet);

        $this->assertStringContainsString("🧰 Золото отсутствует!\n\n🛡 *Броня:* ❌ Нет\n⚔️ *Оружие:* ❌ Нет\n", $text);
        foreach (['Фракция', 'Координаты', 'До уровня', 'Раны', 'Титул', 'Специализация', 'Боевой дрон'] as $absent) {
            $this->assertStringNotContainsString($absent, $text);
        }
        $this->assertStringEndsWith("⚔️ *Оружие:* ❌ Нет\n", $text);
    }

    public function testHudTakesSoonestTaskAndCountsTheRest(): void
    {
        $soon = date('Y-m-d H:i:s', time() + 120);
        $late = date('Y-m-d H:i:s', time() + 3600);
        $hud  = CharacterSheetService::buildHud($this->stats(), 68, 5, ['x' => 12, 'y' => 7], 'Пустошь', [
            ['name_rus' => 'Постройка', 'name' => 'build', 'end_time' => $late],
            ['name_rus' => null, 'name' => 'no_deadline', 'end_time' => null],
            ['name_rus' => 'Добыча металла', 'name' => 'gather', 'end_time' => $soon],
        ]);

        $this->assertSame(['name' => 'Добыча металла', 'ends_at' => strtotime($soon)], $hud['task']);
        $this->assertSame(2, $hud['tasks_more']);
        $this->assertSame(68, $hud['level_percent']);
        $this->assertSame(5, $hud['next_level']);
        $this->assertSame(['x' => 12, 'y' => 7], $hud['cell']);
        $this->assertSame('Пустошь', $hud['biome']);
        $this->assertSame('87.50', $hud['health']);
        $this->assertSame(12480, $hud['gold']);
    }

    public function testHudWithoutTasksIsIdleAndTaskWithoutDeadlineHasNoTimer(): void
    {
        $idle = CharacterSheetService::buildHud($this->stats(), null, null, null, null, []);
        $this->assertNull($idle['task']);
        $this->assertSame(0, $idle['tasks_more']);
        $this->assertNull($idle['level_percent']);

        $open = CharacterSheetService::buildHud($this->stats(), null, null, null, null, [
            ['name_rus' => '', 'name' => 'explore', 'end_time' => null],
        ]);
        $this->assertSame(['name' => 'explore', 'ends_at' => null], $open['task']);
    }

    public function testDisplayNumberDropsTrailingZerosOnly(): void
    {
        $this->assertSame('100', CharacterSheetService::displayNumber('100.00'));
        $this->assertSame('87.5', CharacterSheetService::displayNumber('87.50'));
        $this->assertSame('0.01', CharacterSheetService::displayNumber('0.01'));
        $this->assertSame('1000', CharacterSheetService::displayNumber('1000'));
        $this->assertSame('—', CharacterSheetService::displayNumber('—'));
    }

    /** @return array{health:string, tired:string, gold:int, level:int, experience:string} */
    private function stats(): array
    {
        return ['health' => '87.50', 'tired' => '64.00', 'gold' => 12480, 'level' => 4, 'experience' => '31.20'];
    }

    /** @return array<string, mixed> */
    private function sheet(): array
    {
        return [
            'id' => 7, 'name' => 'Ворон 7', 'faction' => 'Вольные', 'cell' => ['x' => 12, 'y' => 7], 'biome' => 'Пустошь',
            'explored' => 42, 'resource_kinds' => 9, 'time_in_game' => '1 мес. 2 дн. 3 чс.',
            'level' => '4', 'experience' => '31.20', 'agility' => '2.00', 'intellect' => '1.50', 'strength' => '4.10',
            'health' => '87.50', 'tired' => '64.00', 'trading_karma' => '3', 'gold' => 12480,
            'polar_line' => '🌟 *Цель:* построй укрытие',
            'ladder_line' => '🪜 *До уровня 5:* ▰▰▰▰▰▰▱▱▱▱ 68% — набрано 5.5 из 8.0',
            'unlock_line' => '🔓 На 5 уровне: рынок',
            'debuffs' => ['🦴 *Перелом* — медленнее'],
            'streak_line' => '🔥 Серия: 3 дня', 'milestone_line' => '🎯 До вехи: 4 дня', 'title' => 'Первопроходец',
            'armor' => 'Куртка, Сапоги', 'weapon' => 'Нож', 'specialization' => 'Механик',
            'drone' => ['minutes' => 15, 'bonus' => 10],
            'personal_actions' => [], 'tail_actions' => [],
            'hud' => CharacterSheetService::buildHud($this->stats(), null, null, null, null, []),
        ];
    }
}
