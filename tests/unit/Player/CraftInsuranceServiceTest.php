<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Controllers\Telegram\Commands\Actions\Craft\Insurance\CraftInsuranceListAction;
use App\Services\Player\CraftInsuranceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Гейт V24.2 — экран «🛡 Страховка крафта» врал «нет предметов под страховку»
 * игроку, оплатившему полис двух верстаков, потому что единственным текстом
 * для пустого `$rows` были машинные `crafted_items.type` (`robots, workbench,
 * transport`), а действующие полисы вообще не выводились.
 *
 * Тест держит контракт словаря `typeLabel()`/`typeLabels()`, на котором
 * стоит вся правка:
 *  - реальные значения `crafted_items.type` с прода переведены на русский;
 *  - незнакомый тип деградирует в сам токен, а не в пустоту (новая настройка
 *    `craft_insurance.eligible_types` не должна ронять экран);
 *  - список переводов не даёт пустых сегментов («, ,») ни на смеси
 *    известных/неизвестных токенов.
 */
final class CraftInsuranceServiceTest extends CIUnitTestCase
{
    /** Реальные значения crafted_items.type, снятые с прода 19.08.2026. */
    private const PROD_TYPES = [
        'drug', 'weapon', 'tool', 'food', 'clothing', 'building', 'component',
        'transport', 'utility', 'decorative', 'magical item', 'military',
        'accessory', 'teleport', 'workbench', 'robots', 'defense', 'drones',
    ];

    public function testEveryProdTypeHasARussianLabel(): void
    {
        $service = new CraftInsuranceService();

        foreach (self::PROD_TYPES as $type) {
            $label = $service->typeLabel($type);
            $this->assertNotSame('', trim($label), "Тип «{$type}» перевёлся в пустоту.");
            $this->assertNotSame($type, $label, "Тип «{$type}» не переведён — в player-facing текст попадёт машинный токен.");
        }
    }

    public function testCurrentEligibleTypesTranslateAsExpected(): void
    {
        $service = new CraftInsuranceService();

        $this->assertSame('робот', $service->typeLabel('robots'));
        $this->assertSame('верстак', $service->typeLabel('workbench'));
        $this->assertSame('транспорт', $service->typeLabel('transport'));
    }

    public function testUnknownTypeDegradesToTokenInsteadOfBreaking(): void
    {
        $service = new CraftInsuranceService();

        // Настройка craft_insurance.eligible_types может завтра получить тип,
        // которого нет в словаре — экран не должен упасть или показать пустоту.
        $this->assertSame('some_new_type', $service->typeLabel('some_new_type'));
    }

    public function testTypeLabelsJoinsKnownTypesWithComma(): void
    {
        $service = new CraftInsuranceService();

        $this->assertSame(
            'робот, верстак, транспорт',
            $service->typeLabels(['robots', 'workbench', 'transport'])
        );
    }

    public function testTypeLabelsMixedWithUnknownHasNoEmptySegments(): void
    {
        $service = new CraftInsuranceService();

        $joined = $service->typeLabels(['robots', 'some_new_type', 'workbench']);

        $this->assertSame('робот, some_new_type, верстак', $joined);
        $this->assertStringNotContainsString(', ,', $joined);
        $this->assertStringNotContainsString(',,', $joined);
    }

    public function testTypeLabelsOfEmptyListIsEmptyString(): void
    {
        $service = new CraftInsuranceService();

        $this->assertSame('', $service->typeLabels([]));
    }

    // ── Экран «Страховка крафта» — V24.2 гейт: сам баг из жалобы Анжелы жил в
    // ветвлении по списку полисов, а не в словаре типов, и не был закреплён ничем.
    // `CraftInsuranceListAction::renderScreen()` — чистая сборка текста/кнопок без
    // DB/Telegram, извлечённая из `handle()` ровно для этого теста (story 12).

    /**
     * Ровно жалоба Анжелы: полисы оплачены (2 верстака), подходящих под страховку
     * ($rows) больше нет — до починки экран показывал «нет предметов под страховку»
     * вместо того, чтобы сказать, что застраховано ВСЁ.
     */
    public function testAllInsuredStateSaysEverythingIsInsuredNotThatThereIsNothing(): void
    {
        $policyRows = [
            ['name_rus' => 'Верстак базовый',       'qty' => 1],
            ['name_rus' => 'Верстак профессиональный', 'qty' => 1],
        ];

        $screen = CraftInsuranceListAction::renderScreen([], $policyRows, ['robots', 'workbench', 'transport'], new CraftInsuranceService());

        $this->assertStringContainsString('Всё, что подходит под страховку, уже застраховано', $screen['text']);
        $this->assertStringNotContainsString('У тебя нет предметов под страховку', $screen['text']);
    }

    /**
     * Второе состояние: подходящих предметов нет вообще (и полисов тоже нет) — единственный
     * случай, когда честно сказать «нечего страховать» с русскими названиями типов.
     */
    public function testNothingEligibleAtAllStateListsTypesInRussian(): void
    {
        $screen = CraftInsuranceListAction::renderScreen([], [], ['robots', 'workbench', 'transport'], new CraftInsuranceService());

        $this->assertStringContainsString('У тебя нет предметов под страховку', $screen['text']);
        $this->assertStringContainsString('робот, верстак, транспорт', $screen['text']);
        $this->assertStringNotContainsString('workbench', $screen['text']);
    }

    /**
     * Третье состояние: есть что застраховать — список eligible-предметов рисуется
     * с кнопками, а не текстом одного из двух empty-state.
     */
    public function testEligibleItemsStateListsItemsWithButtons(): void
    {
        $rows = [
            ['log_id' => 42, 'qty' => 1, 'insured' => 0, 'name_rus' => 'Верстак стандартный', 'type' => 'workbench'],
        ];

        $screen = CraftInsuranceListAction::renderScreen($rows, [], ['robots', 'workbench', 'transport'], new CraftInsuranceService());

        $this->assertStringContainsString('Верстак стандартный', $screen['text']);
        $this->assertStringNotContainsString('У тебя нет предметов под страховку', $screen['text']);
        $this->assertStringNotContainsString('уже застраховано', $screen['text']);

        $callbacks = array_merge(...array_map(
            fn (array $row): array => array_column($row, 'callback_data'),
            $screen['buttons']
        ));
        $this->assertContains('craftInsure_42', $callbacks, 'Кнопка страховки конкретной строки log_id=42 обязана быть в клавиатуре.');
    }

    /**
     * Действующие полисы перечисляются поимённо (`crafted_items.name_rus`), а не просто
     * фактом наличия — вторая половина жалобы: игрок хотел УВИДЕТЬ, что оплачено.
     */
    public function testActivePoliciesAreListedByNameAndQuantity(): void
    {
        $policyRows = [
            ['name_rus' => 'Верстак базовый',          'qty' => 1],
            ['name_rus' => 'Верстак профессиональный', 'qty' => 2],
        ];

        // Одновременно проверяем блок полисов и при непустом $rows (полисы рисуются
        // НАД списком доступного к страхованию — а не только в пустом состоянии).
        $rows = [
            ['log_id' => 7, 'qty' => 1, 'insured' => 0, 'name_rus' => 'Дрон-разведчик', 'type' => 'robots'],
        ];

        $screen = CraftInsuranceListAction::renderScreen($rows, $policyRows, ['robots', 'workbench', 'transport'], new CraftInsuranceService());

        $this->assertStringContainsString('Действующие полисы', $screen['text']);
        $this->assertStringContainsString('Верстак базовый', $screen['text']);
        $this->assertStringContainsString('Верстак профессиональный × 2', $screen['text']);
    }
}
