<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\NavKeyboards;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-150 (чистка дублей) — канонический ряд «что дальше» вместо шестикнопочного супа.
 *
 * @internal
 */
final class NavKeyboardsTest extends CIUnitTestCase
{
    /** Ровно один ряд из двух сиблингов: идти и действовать. Соло-рядов нет. */
    public function testWhatNextIsSingleRowOfTwoSiblings(): void
    {
        $rows = NavKeyboards::whatNext();

        $this->assertCount(1, $rows, 'Ряд «что дальше» должен быть один.');
        $this->assertCount(2, $rows[0], 'Сиблингов ровно два (анти-соло-ряд).');
        $this->assertSame('move', $rows[0][0]['callback_data'], '«Идти» — первым (урок march-interrupt).');
        $this->assertSame('characterActions', $rows[0][1]['callback_data']);
    }

    /** Кнопки чужих групп в «что дальше» не возвращаются: у каждой свой дом на нижней панели. */
    public function testWhatNextCarriesNoCrossGroupButtons(): void
    {
        $flat = array_merge(...NavKeyboards::whatNext());
        $data = array_column($flat, 'callback_data');

        foreach (['events', 'inventory', 'shop', 'entertainment', 'pharmacy'] as $foreign) {
            $this->assertNotContains($foreign, $data, "«{$foreign}» имеет свой дом — в «что дальше» ему не место.");
        }
    }

    /** Контекстные кнопки экрана добавляются ПОСЛЕ канонического ряда, не вместо него. */
    public function testExtraRowsAreAppendedAfterWhatNext(): void
    {
        $kb = NavKeyboards::whatNextWith([[['text' => '📡 Маяки', 'callback_data' => 'teleportBeacon']]]);

        $this->assertArrayHasKey('inline_keyboard', $kb);
        $this->assertCount(2, $kb['inline_keyboard']);
        $this->assertSame('move', $kb['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('teleportBeacon', $kb['inline_keyboard'][1][0]['callback_data']);
    }

    /**
     * 🔴 Анти-self-ref: экран «События» не должен содержать кнопку на самого себя.
     * Именно этим он и болел — «переход» туда, где игрок уже стоит.
     */
    public function testEventScreenHasNoSelfReferenceInSimplifiedKeyboard(): void
    {
        $flat = array_merge(...NavKeyboards::whatNext());

        $this->assertNotContains('events', array_column($flat, 'callback_data'));
    }
}
