<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Services\Bases\BuildingCardService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Buildings;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ экранов построек (CLAUDE.md §🎮 UX-DISCOVERABILITY).
 *
 * Добавить здание в `Config\Buildings` и забыть его карточку ничего не ломает на этапе сборки:
 * постройка спокойно строится, показывается на базе кнопкой — и отвечает «Неизвестное строение»
 * на тап.
 *
 * Так уже было дважды:
 *  - ADR-041 — оборонные постройки (WoodenWall/BarbedFence/WatchTower);
 *  - S5/ADR-142 — «Навес» (LeanTo), ПЕРВАЯ постройка новичка. Прожил в таком
 *    виде с 27.06 до 12.08, 21 владелец на проде, поймал игрок в Bugs-info,
 *    а не тесты.
 *
 * С W2.N4b (ADR-190) карточки всех зданий — записи таблицы {@see BuildingCardService::CARDS}
 * (раньше — `case` в switch диспетчера и класс на здание). Гейт сверяет таблицу с конфигом.
 *
 * @internal
 */
final class BuildingHandlerCoverageTest extends CIUnitTestCase
{
    /**
     * Главный гейт: у каждой постройки из конфига есть карточка в ядре.
     */
    public function testEveryConfiguredBuildingHasCard(): void
    {
        $configured = array_keys((new Buildings())->recipes);
        $missing    = array_values(array_diff($configured, array_keys(BuildingCardService::CARDS)));

        $this->assertSame([], $missing, sprintf(
            "Постройки без карточки в BuildingCardService::CARDS: %s.\n"
            . "Игрок построит их, увидит кнопку на базе — и получит «Не удалось открыть это строение» на тап.\n"
            . 'Заведи запись (образцы: LeanTo, WoodenWall).',
            implode(', ', $missing)
        ));
    }

    /**
     * Обратная сторона: карточка постройки, которой нет в конфиге, — мёртвая запись.
     * Обычно означает переименование ключа, при котором старую запись забыли убрать.
     */
    public function testNoCardForUnknownBuilding(): void
    {
        $configured = array_keys((new Buildings())->recipes);
        $orphans    = array_values(array_diff(array_keys(BuildingCardService::CARDS), $configured));

        $this->assertSame([], $orphans, sprintf(
            'Карточка в BuildingCardService::CARDS без записи в Config\Buildings: %s.',
            implode(', ', $orphans)
        ));
    }

    /**
     * Каждая картинка карточки лежит на диске: иначе бот уйдёт в текстовый фолбэк молча.
     */
    public function testEveryCardImageExists(): void
    {
        foreach (BuildingCardService::CARDS as $key => $card) {
            $this->assertFileExists(FCPATH . $card['image'], "Карточка {$key}: нет картинки {$card['image']}.");
        }
    }

    /**
     * Неопознанная постройка не оставляет игрока в тупике: кнопка возврата на базу (ADR-103).
     */
    public function testUnknownBuildingOffersWayBack(): void
    {
        $path = ROOTPATH . 'app/Controllers/Telegram/Commands/Actions/Camp/BuildingHandlerAction.php';
        $src  = (string) file_get_contents($path);
        $tail = substr($src, strpos($src, 'private function unknown(') ?: 0);

        $this->assertStringContainsString(
            "'callback_data' => 'Base'",
            $tail,
            'Отказ «не удалось открыть строение» обязан давать кнопку возврата на базу, а не оставлять игрока в тупике.'
        );
    }
}
