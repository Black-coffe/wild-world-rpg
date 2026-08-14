<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logging;

use App\Services\Logging\ActionOrigin;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-168 — метка источника нажатия на callback_data ({@see ActionOrigin}).
 *
 * Лочит инварианты, ценой которых механизм безопасен:
 *  - OFF = byte-identical (метка не проставляется вовсе);
 *  - снятие метки БЕЗУСЛОВНО (кнопки из истории чата работают и после выключения);
 *  - 64-байтный предел Telegram не пробивается никогда (иначе отвалится вся клавиатура);
 *  - мусорная метка не попадает в телеметрию;
 *  - разделитель не ломает разбор `action_name` (первый сегмент по `_`).
 *
 * Реальный сквозной путь «тап помеченной кнопки → строка firehose с origin» — Tier-3 на testbot.
 *
 * @internal
 */
final class ActionOriginTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ActionOrigin::overrideEnabled(true);
        ActionOrigin::reset();
    }

    protected function tearDown(): void
    {
        ActionOrigin::overrideEnabled(null);
        ActionOrigin::reset();
        parent::tearDown();
    }

    // ── Простановка ───────────────────────────────────────────────────────────

    public function testTagAppendsOrigin(): void
    {
        $this->assertSame('gather~cmp', ActionOrigin::tag('gather', ActionOrigin::FROM_COMPASS));
        $this->assertSame('gather_10~hub', ActionOrigin::tag('gather_10', ActionOrigin::FROM_HUB));
    }

    public function testKillswitchOffLeavesCallbackDataByteIdentical(): void
    {
        ActionOrigin::overrideEnabled(false);

        $this->assertSame('gather', ActionOrigin::tag('gather', ActionOrigin::FROM_COMPASS));
        $this->assertSame('gather_10', ActionOrigin::tag('gather_10', ActionOrigin::FROM_HUB));
    }

    public function testNullOrEmptyOriginLeavesDataUnchanged(): void
    {
        $this->assertSame('gather', ActionOrigin::tag('gather', null));
        $this->assertSame('gather', ActionOrigin::tag('gather', ''));
        $this->assertSame('gather', ActionOrigin::tag('gather', '   '));
    }

    public function testGarbageOriginRejected(): void
    {
        // Мусор в телеметрии хуже, чем её отсутствие: по нему нельзя ни считать, ни доверять.
        $this->assertSame('gather', ActionOrigin::tag('gather', 'с компаса'), 'кириллица');
        $this->assertSame('gather', ActionOrigin::tag('gather', 'from_hub'), 'подчёркивание');
        $this->assertSame('gather', ActionOrigin::tag('gather', 'a~b'), 'сам разделитель');
        $this->assertSame('gather', ActionOrigin::tag('gather', 'waytoolongorigin'), 'длиннее 12');
    }

    public function testAlreadyTaggedDataNotDoubleTagged(): void
    {
        $this->assertSame('gather~cmp', ActionOrigin::tag('gather~cmp', ActionOrigin::FROM_HUB));
    }

    public function testTagNeverBreaksTelegram64ByteLimit(): void
    {
        // Пробитый предел = Telegram отвергает ВСЮ клавиатуру, а не одну кнопку. Телеметрия
        // не стоит мёртвого экрана, поэтому метка молча не ставится.
        $long = str_repeat('a', 61); // 61 + '~' + 'cmp' = 65 > 64
        $this->assertSame($long, ActionOrigin::tag($long, ActionOrigin::FROM_COMPASS));

        $fits = str_repeat('a', 60); // 60 + 4 = 64 ровно
        $this->assertSame($fits . '~cmp', ActionOrigin::tag($fits, ActionOrigin::FROM_COMPASS));
        $this->assertSame(64, strlen(ActionOrigin::tag($fits, ActionOrigin::FROM_COMPASS)));
    }

    public function testOriginNormalizedToLowercase(): void
    {
        $this->assertSame('gather~cmp', ActionOrigin::tag('gather', 'CMP'));
    }

    // ── Снятие ────────────────────────────────────────────────────────────────

    public function testStripIsUnconditionalEvenWhenKillswitchOff(): void
    {
        // Сообщения живут в истории чата вечно: помеченная при ON кнопка обязана работать
        // после OFF, иначе выключение флага ломает игрокам старые экраны.
        ActionOrigin::overrideEnabled(false);

        $this->assertSame('gather', ActionOrigin::strip('gather~cmp'));
        $this->assertSame('gather_10', ActionOrigin::strip('gather_10~cmp'));
    }

    public function testStripLeavesUntaggedDataAlone(): void
    {
        $this->assertSame('gather', ActionOrigin::strip('gather'));
        $this->assertSame('move_dir_north', ActionOrigin::strip('move_dir_north'));
    }

    public function testExtractReadsOrigin(): void
    {
        $this->assertSame('cmp', ActionOrigin::extract('gather~cmp'));
        $this->assertNull(ActionOrigin::extract('gather'));
        $this->assertNull(ActionOrigin::extract('gather~'), 'пустая метка = её нет');
        $this->assertNull(ActionOrigin::extract('gather~ЧУШЬ'), 'мусор не читаем');
    }

    public function testStripUpdateCleansCallbackDataAndReturnsOrigin(): void
    {
        [$clean, $origin, $stripped] = ActionOrigin::stripUpdate($this->cbUpdate('gather_10~cmp'));

        $this->assertSame('gather_10', $clean['callback_query']['data'], 'хендлеры видят прежнюю строку');
        $this->assertSame('cmp', $origin);
        $this->assertTrue($stripped);
    }

    public function testStripUpdateLeavesUntaggedUpdateUntouched(): void
    {
        $update                      = $this->cbUpdate('gather');
        [$clean, $origin, $stripped] = ActionOrigin::stripUpdate($update);

        $this->assertSame($update, $clean, 'непомеченный трафик не трогаем вовсе');
        $this->assertNull($origin);
        $this->assertFalse($stripped, 'ввод Longman-у перезаписывать не за чем');
    }

    public function testStripUpdateIgnoresNonCallbackUpdates(): void
    {
        $update                      = ['message' => ['text' => 'привет~cmp', 'from' => ['id' => 25]]];
        [$clean, $origin, $stripped] = ActionOrigin::stripUpdate($update);

        $this->assertSame($update, $clean, 'текст игрока — не callback_data, тильда в нём законна');
        $this->assertNull($origin);
        $this->assertFalse($stripped);
    }

    public function testGarbageTagIsStrippedEvenThoughOriginIsRejected(): void
    {
        // 🔴 Замок на баг, пойманный Tier-3 14.08. «Апдейт изменён» ≠ «метка распознана»:
        // мусорный хвост срезается так же, как валидный, но источником не становится. Если
        // связать перезапись ввода Longman-а с наличием метки, он получит СЫРУЮ строку,
        // которой роутер не знает → мёртвая кнопка, а firehose запишет очищенную. На проде
        // это выглядело как `status='unrouted'` при `raw_input='gather'`.
        [$clean, $origin, $stripped] = ActionOrigin::stripUpdate($this->cbUpdate('gather~ЧУШЬ'));

        $this->assertSame('gather', $clean['callback_query']['data'], 'кнопка обязана доехать до хендлера');
        $this->assertNull($origin, 'мусор не попадает в телеметрию');
        $this->assertTrue($stripped, 'ввод обязан быть перезаписан, иначе роут увидит сырую строку');
    }

    // ── Совместимость с разбором firehose ─────────────────────────────────────

    public function testSeparatorDoesNotDisturbActionNameParsing(): void
    {
        // firehose берёт action_name как explode('_', $data)[0], а роутер режет callback_data
        // по тому же символу. Инвариант: метка живёт в ПОСЛЕДНЕМ сегменте и первый не трогает —
        // поэтому и выбран `~`, а не `_` (с подчёркиванием 'gather_10~cmp' дало бы разбор
        // 'gather' | '10~cmp', а 'gather_cmp' — вовсе неотличимо от настоящей длительности).
        $this->assertSame('gather', explode('_', ActionOrigin::strip('gather_10~cmp'))[0], 'после снятия');
        $this->assertSame('gather', explode('_', 'gather_10~cmp')[0], 'и даже до снятия');
    }

    // ── Request-scoped холдер ─────────────────────────────────────────────────

    public function testHolderKeepsAndResetsOrigin(): void
    {
        $this->assertNull(ActionOrigin::current(), 'по умолчанию метки нет');

        ActionOrigin::set('cmp');
        $this->assertSame('cmp', ActionOrigin::current());

        ActionOrigin::reset();
        $this->assertNull(ActionOrigin::current(), 'метка не течёт в следующий апдейт');
    }

    public function testHolderRejectsGarbage(): void
    {
        ActionOrigin::set('не метка');
        $this->assertNull(ActionOrigin::current());
    }

    /** @return array<string,mixed> */
    private function cbUpdate(string $data): array
    {
        return ['callback_query' => [
            'data'    => $data,
            'from'    => ['id' => 25],
            'message' => ['chat' => ['id' => 25]],
        ]];
    }
}
