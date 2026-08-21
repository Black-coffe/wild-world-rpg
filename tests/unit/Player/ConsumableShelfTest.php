<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\ConsumableShelfService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Consumables;
use Config\Debuffs;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('curingMedicine')]
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
     * Русские названия и бафы — той же ФОРМЫ, что реально лежит в `crafted_items`:
     * двухключевой JSON `{"Здоровье": N, "Выносливость": M}` (замерено на живой БД,
     * docs/specs/pharmacy-split/pharmacy-split-07.md), а не тестовый однодозовый
     * английский fallback из `row()` выше. Используется только гейтами длины —
     * остальные тесты этого файла проверены на излом и `row()` им не мешает.
     *
     * @return array<string, array{name_rus: string, boost: string}>
     */
    private static function productionCatalog(): array
    {
        return [
            // Лекарства (10 из 15 — реальный `name_rus`/`character_boost` с живой БД;
            // 5 отсутствующих в локальной БД — правдоподобный русский эквивалент той же формы).
            'headache_tablets'      => ['name_rus' => 'Таблетки от головной боли', 'boost' => '[{"Здоровье": 10,"Выносливость": 4}]'],
            'FirstAidKit'           => ['name_rus' => 'Аптечка базовая', 'boost' => '[{"Здоровье": 40,"Выносливость": 9}]'],
            'Common cold tincture'  => ['name_rus' => 'Настойка от простуды', 'boost' => '[{"Здоровье": 45,"Выносливость": 6}]'],
            'TonicElixir'           => ['name_rus' => 'Укрепляющий эликсир', 'boost' => '[{"Здоровье": 18,"Выносливость": 16}]'],
            'Bandage'               => ['name_rus' => 'Повязка', 'boost' => '[{"Здоровье": 2,"Выносливость": 1}]'],
            'AnalgesicPowder'       => ['name_rus' => 'Обезболивающий порошок', 'boost' => '[{"Здоровье": 18,"Выносливость": -4}]'],
            'Stimulator'            => ['name_rus' => 'Стимулятор', 'boost' => '[{"Здоровье": 25,"Выносливость": 15}]'],
            'Antiseptic'            => ['name_rus' => 'Антисептик', 'boost' => '[{"Здоровье": 4,"Выносливость": 2}]'],
            'Sedative'              => ['name_rus' => 'Успокоительное', 'boost' => '[{"Здоровье": 5,"Выносливость": 30}]'],
            'Regenerator'           => ['name_rus' => 'Регенератор', 'boost' => '[{"Здоровье": 30,"Выносливость": 20}]'],
            'SyntheticMedicine'     => ['name_rus' => 'Синтетическое лекарство', 'boost' => '[{"Здоровье": 22,"Выносливость": 10}]'],
            'EmergencyTransfusion'  => ['name_rus' => 'Экстренное переливание крови', 'boost' => '[{"Здоровье": 60,"Выносливость": 5}]'],
            'SurgicalKit'           => ['name_rus' => 'Хирургический набор', 'boost' => '[{"Здоровье": 50,"Выносливость": 12}]'],
            'WinterWarmingBalm'     => ['name_rus' => 'Зимний согревающий бальзам', 'boost' => '[{"Здоровье": 12,"Выносливость": 8}]'],
            'SummerAloeBalm'        => ['name_rus' => 'Летний бальзам с алоэ', 'boost' => '[{"Здоровье": 8,"Выносливость": 6}]'],
            // Провизия — правдоподобный русский эквивалент той же формы (двухключевой Баф).
            'WinterHerbalBrew'      => ['name_rus' => 'Зимний травяной взвар', 'boost' => '[{"Здоровье": 3,"Выносливость": 12}]'],
            'WinterHoneyMead'       => ['name_rus' => 'Зимний медовый сбитень', 'boost' => '[{"Здоровье": 4,"Выносливость": 14}]'],
            'WinterCampStew'        => ['name_rus' => 'Зимняя походная похлёбка', 'boost' => '[{"Здоровье": 6,"Выносливость": 18}]'],
            'WinterPreserves'       => ['name_rus' => 'Зимние заготовки', 'boost' => '[{"Здоровье": 2,"Выносливость": 10}]'],
            'SpringFirstHerbTea'    => ['name_rus' => 'Весенний чай из первых трав', 'boost' => '[{"Здоровье": 3,"Выносливость": 9}]'],
            'SpringBirchSap'        => ['name_rus' => 'Весенний берёзовый сок', 'boost' => '[{"Здоровье": 2,"Выносливость": 8}]'],
            'SpringPrimroseInfusion' => ['name_rus' => 'Весенний настой примулы', 'boost' => '[{"Здоровье": 4,"Выносливость": 7}]'],
            'SpringWildGreens'      => ['name_rus' => 'Весенняя дикая зелень', 'boost' => '[{"Здоровье": 3,"Выносливость": 6}]'],
            'SpringShootsDecoction' => ['name_rus' => 'Весенний отвар из побегов', 'boost' => '[{"Здоровье": 3,"Выносливость": 9}]'],
            'SummerColdKvass'       => ['name_rus' => 'Летний холодный квас', 'boost' => '[{"Здоровье": 2,"Выносливость": 15}]'],
            'SummerBerryMors'       => ['name_rus' => 'Летний ягодный морс', 'boost' => '[{"Здоровье": 3,"Выносливость": 13}]'],
            'SummerFruitWater'      => ['name_rus' => 'Летняя фруктовая вода', 'boost' => '[{"Здоровье": 1,"Выносливость": 16}]'],
            'SummerMintTea'         => ['name_rus' => 'Летний мятный чай', 'boost' => '[{"Здоровье": 2,"Выносливость": 10}]'],
            'AutumnBerryJam'        => ['name_rus' => 'Осенний ягодный джем', 'boost' => '[{"Здоровье": 4,"Выносливость": 8}]'],
            'AutumnMushroomStew'    => ['name_rus' => 'Осенняя грибная похлёбка', 'boost' => '[{"Здоровье": 6,"Выносливость": 17}]'],
            'AutumnNutMix'          => ['name_rus' => 'Осенняя ореховая смесь', 'boost' => '[{"Здоровье": 5,"Выносливость": 12}]'],
            'AutumnCider'           => ['name_rus' => 'Осенний сидр', 'boost' => '[{"Здоровье": 2,"Выносливость": 11}]'],
            'AutumnVegPreserves'    => ['name_rus' => 'Осенние овощные заготовки', 'boost' => '[{"Здоровье": 3,"Выносливость": 9}]'],
            'MushroomSoup'          => ['name_rus' => 'Грибной суп', 'boost' => '[{"Здоровье": 5,"Выносливость": 14}]'],
            'BerryBrew'             => ['name_rus' => 'Ягодный взвар', 'boost' => '[{"Здоровье": 2,"Выносливость": 12}]'],
            'BakedFruit'            => ['name_rus' => 'Печёные фрукты', 'boost' => '[{"Здоровье": 3,"Выносливость": 8}]'],
            'GrainPorridge'         => ['name_rus' => 'Зерновая каша', 'boost' => '[{"Здоровье": 4,"Выносливость": 15}]'],
            'HeartyStew'            => ['name_rus' => 'Сытная похлёбка', 'boost' => '[{"Здоровье": 6,"Выносливость": 18}]'],
            'StewPreserve'          => ['name_rus' => 'Тушёнка', 'boost' => '[{"Здоровье": 5,"Выносливость": 14}]'],
            'DryRation'             => ['name_rus' => 'Сухой паёк', 'boost' => '[{"Здоровье": 2,"Выносливость": 10}]'],
            'FishSoup'              => ['name_rus' => 'Рыбный суп', 'boost' => '[{"Здоровье": 4,"Выносливость": 13}]'],
            'GrilledFish'           => ['name_rus' => 'Жареная рыба', 'boost' => '[{"Здоровье": 3,"Выносливость": 11}]'],
            'FishPreserve'          => ['name_rus' => 'Рыбные консервы', 'boost' => '[{"Здоровье": 4,"Выносливость": 12}]'],
        ];
    }

    /**
     * Строка той же ФОРМЫ, что приходит из БД в `PharmacyAction`/`UsePharmacyAction`:
     * русское имя, срок годности в будущем (годен), двухключевой `character_boost`.
     * `$multiDose` включает начатую многодозовую упаковку (5 доз в шаблоне, 3 осталось,
     * как у Антисептика) — только там, где вызывающий тест явно просит эту строку.
     *
     * @param array<string, array{name_rus: string, boost: string}> $catalog
     * @return array<string, mixed>
     */
    private function productionRow(string $nameEng, array $catalog, int $quantity = 3, bool $multiDose = false): array
    {
        $entry = $catalog[$nameEng];

        return [
            'name_eng'        => $nameEng,
            'name_rus'        => $entry['name_rus'],
            'quantity'        => $quantity,
            'character_boost' => $entry['boost'],
            'durability_time' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'base_charges'    => $multiDose ? 5 : 1,
            'log_charges'     => $multiDose ? 3 : 1,
        ];
    }

    /**
     * Длина в UTF-16 code units — ровно та метрика, которой Telegram считает лимиты
     * caption/text ("after entities parsing"). Эмодзи (суррогатная пара) весит 2 unit,
     * а экран аптечки — это эмодзи в каждой строке (📋/💊/✅/🩺/🔥/🍲): `mb_strlen` (1
     * unit на символ) занижает реальную длину и давал ложное «укладывается впритык»
     * там, где Telegram уже режет текст. Дублирует алгоритм приватного
     * `MediaSender::visibleTgLength` (strip_tags+entity-decode тут не нужны — в
     * экране нет HTML, только markdown `*...*`).
     */
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * 🔴 Длина текста. Полный каталог полки — 28 провизий / 15 лекарств — кормится
     * ПРОДОВОЙ формой строки (`productionRow()`), а не сырым `name_eng`-фолбэком:
     * русские имена, «✅ годен до …», двухключевой «Баф» вместо однодозового
     * английского фолбэка `row()`. Гейт на сырых строках срабатывал бы в разы позже
     * реальной длины — то есть никогда.
     *
     * Порог — единственная реальная жёсткая граница у этого экрана: лимит текста
     * Telegram-сообщения (4096, `MediaSender::TEXT_MESSAGE_LIMIT`). Лимит подписи
     * фото (1024) НЕ гейтится здесь: `MediaSender::sendPhotoOrText` при подписи
     * длиннее 1024 штатно переключается на текстовое сообщение (кнопки сохраняются,
     * `captionExceedsPhotoLimit()`), так что превышение 1024 — ожидаемая, а не
     * аварийная ветка. Сообщение падения называет фактическую длину ОБЕИХ полок.
     */
    public function testFullCatalogScreenFitsTelegramTextLimit(): void
    {
        $telegramLimit = 4096;
        $catalog       = self::productionCatalog();

        $shelves = [Consumables::SHELF_MEDICINE => Consumables::MEDICINE, Consumables::SHELF_PROVISION => Consumables::PROVISION];
        $lengths = [];
        foreach ($shelves as $shelf => $names) {
            $rows            = array_map(fn (string $nameEng): array => $this->productionRow($nameEng, $catalog, 3, $nameEng === 'Antiseptic'), $names);
            $lengths[$shelf] = self::utf16Length($this->service->screen($shelf, $rows)['text']);
        }

        $bothShelvesText = "лекарства: {$lengths[Consumables::SHELF_MEDICINE]} unit, провизия: {$lengths[Consumables::SHELF_PROVISION]} unit";

        foreach ($lengths as $shelf => $length) {
            $this->assertLessThan(
                $telegramLimit,
                $length,
                "Полный экран полки «{$shelf}» перерос лимит Telegram-сообщения {$telegramLimit} unit ({$bothShelvesText})",
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
