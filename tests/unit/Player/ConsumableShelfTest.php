<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\ConsumableShelfService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Consumables;
use Config\Debuffs;

/**
 * Полка расходника через её публичный вход: массив строк → `split()`/`screen()` →
 * текст и кнопки. Никакого `file_get_contents` по исходнику — такой тест остаётся
 * зелёным при сломанном методе (docs/specs/pharmacy-split/pharmacy-split-03.md).
 *
 * `durability_time` в тестовых строках сознательно не задаётся: `screen()` дергает
 * `ConsumableExpiryService::enabled()`, который живой БД тянет `game_settings`, а
 * при недоступности деградирует к default (см. докблок `GameSettingsService::get`).
 * Без `durability_time` ветка годности не участвует независимо от того, что вернёт
 * `enabled()` на этой машине — тест детерминирован что с БД, что без неё.
 */
final class ConsumableShelfTest extends CIUnitTestCase
{
    private ConsumableShelfService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConsumableShelfService();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $nameEng, array $overrides = []): array
    {
        return array_merge([
            'name_eng'        => $nameEng,
            'name_rus'        => $nameEng,
            'quantity'        => 1,
            'character_boost' => json_encode(['heal' => ['hp' => 10]]),
            'base_charges'    => 1,
            'log_charges'     => 1,
        ], $overrides);
    }

    public function testSplitPutsEachItemOnItsOwnShelf(): void
    {
        $rows = [
            $this->row('StewPreserve'), // тушёнка
            $this->row('Bandage'),      // бинт
            $this->row('FishSoup'),     // уха
            $this->row('FirstAidKit'),  // аптечка
        ];

        $result = $this->service->split($rows);

        $this->assertSame(
            ['Bandage', 'FirstAidKit'],
            array_column($result['medicine'], 'name_eng'),
        );
        $this->assertSame(
            ['StewPreserve', 'FishSoup'],
            array_column($result['provision'], 'name_eng'),
        );
    }

    public function testUnknownNameGoesToProvision(): void
    {
        $result = $this->service->split([$this->row('ЧтоТоНеизвестное')]);

        $this->assertSame([], $result['medicine']);
        $this->assertCount(1, $result['provision']);
        $this->assertSame('ЧтоТоНеизвестное', $result['provision'][0]['name_eng']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function curingMedicine(): array
    {
        return [
            'Bandage'     => ['Bandage'],
            'Antiseptic'  => ['Antiseptic'],
            'Regenerator' => ['Regenerator'],
            'FirstAidKit' => ['FirstAidKit'],
        ];
    }

    /**
     * @dataProvider curingMedicine
     */
    public function testMedicineShelfPrintsCuredLineForCuringItems(string $nameEng): void
    {
        $screen = $this->service->screen(ConsumableShelfService::SHELF_MEDICINE, [$this->row($nameEng)]);

        $this->assertStringContainsString('🩺 *Снимает:*', $screen['text']);

        // Строка обязана называть саму рану, а не просто присутствовать —
        // иначе игрок узнает, что предмет что-то снимает, но не что именно.
        foreach (Debuffs::curedByItem($nameEng) as $key) {
            $meta = Debuffs::get($key);
            $this->assertNotNull($meta);
            $this->assertStringContainsString($meta['name'], $screen['text']);
        }
    }

    public function testProvisionItemNeverPrintsCuredLineEvenOnMedicineShelf(): void
    {
        // StewPreserve не снимает ни одной раны (Config\Debuffs::cured_by пуст для него) —
        // строка «Снимает» не должна появиться, даже если её ошибочно подсунуть на полку лекарств.
        $screen = $this->service->screen(ConsumableShelfService::SHELF_MEDICINE, [$this->row('StewPreserve')]);

        $this->assertStringNotContainsString('🩺 *Снимает:*', $screen['text']);
    }

    public function testProvisionShelfNeverPrintsCuredLineForRealMedicine(): void
    {
        // Раздел «Снимает» — привилегия полки лекарств (бриф: еда состояний не снимает).
        // Даже если бинт по ошибке передать на полку провизии, itemLine() не должна его печатать.
        $screen = $this->service->screen(ConsumableShelfService::SHELF_PROVISION, [$this->row('Bandage')]);

        $this->assertStringNotContainsString('🩺 *Снимает:*', $screen['text']);
    }

    /**
     * 🔴 Длина текста. Полный каталог полки — 28 провизий / 15 лекарств, по одному
     * предмету в наличии — не имеет права перерасти лимит Telegram-сообщения 4096
     * символов. `MediaSender::visibleTgLength` приватный, здесь текста без HTML-тегов
     * не встречается — считаем `mb_strlen` напрямую (см. Requirement #5 story).
     */
    public function testFullCatalogScreenFitsTelegramTextLimit(): void
    {
        $telegramLimit = 4096;

        foreach ([Consumables::SHELF_MEDICINE => Consumables::MEDICINE, Consumables::SHELF_PROVISION => Consumables::PROVISION] as $shelf => $names) {
            $rows = array_map(
                fn (string $nameEng): array => $this->row($nameEng, ['quantity' => 3]),
                $names,
            );

            $screen = $this->service->screen($shelf, $rows);

            $this->assertLessThan(
                $telegramLimit,
                mb_strlen($screen['text']),
                "Полный экран полки «{$shelf}» ({" . count($names) . "} предметов) перерос лимит Telegram-сообщения",
            );
        }
    }

    /**
     * Разметка `*...*` обязана закрываться — нечётное число звёздочек ломает
     * Telegram Markdown-рендер всего сообщения целиком (не только хвоста).
     */
    public function testAsteriskMarkupIsBalancedOnFullCatalogScreens(): void
    {
        foreach ([Consumables::SHELF_MEDICINE => Consumables::MEDICINE, Consumables::SHELF_PROVISION => Consumables::PROVISION] as $shelf => $names) {
            $rows = array_map(
                fn (string $nameEng): array => $this->row($nameEng),
                $names,
            );

            $screen = $this->service->screen($shelf, $rows);

            $count = substr_count($screen['text'], '*');
            $this->assertSame(0, $count % 2, "Полка «{$shelf}»: {$count} звёздочек — нечётное число ломает Markdown-рендер");
        }
    }
}
