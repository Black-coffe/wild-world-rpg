<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Relocation;

use App\Services\Player\Relocation\RelocationRequestService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-122 (UX-хвост) — разбор координат «Полноценного переезда» из свободного ответа игрока.
 *
 * Игрок отвечает на forceReply-промпт как ему удобно. Раньше он был обязан набрать
 * `/base_shifting X=123Y=543` без единой опечатки — единственное место в игре, где нужен
 * синтаксис команды. Парсер обязан понимать человека, но не выдумывать координаты там,
 * где их нет.
 *
 * @internal
 */
final class RelocationCoordsParseTest extends CIUnitTestCase
{
    /** Человеческие формы ответа. */
    public function testAcceptsHumanFormats(): void
    {
        $this->assertSame([357, 391], RelocationRequestService::parseCoords('357 391'));
        $this->assertSame([357, 391], RelocationRequestService::parseCoords('357,391'));
        $this->assertSame([357, 391], RelocationRequestService::parseCoords('357, 391'));
        $this->assertSame([357, 391], RelocationRequestService::parseCoords('357:391'));
        $this->assertSame([357, 391], RelocationRequestService::parseCoords('x=357 y=391'));
        $this->assertSame([357, 391], RelocationRequestService::parseCoords('X=357Y=391'));
        $this->assertSame([1, 1000], RelocationRequestService::parseCoords('1 1000'));
    }

    /** Историческая форма команды продолжает разбираться (обратная совместимость). */
    public function testLegacyCommandFormatStillParses(): void
    {
        $this->assertSame([123, 543], RelocationRequestService::parseCoords('/base_shifting X=123Y=543'));
    }

    /**
     * 🔴 `preg_match_all` возвращает ЧИСЛО совпадений, а не 1/0. Сравнение с `=== 1` (типичная
     * калька с `preg_match`) сделало бы парсер слепым: он принимал бы только строки ровно с одним
     * числом — то есть не принимал бы ничего валидного.
     */
    public function testTwoNumbersAreFoundNotOne(): void
    {
        $this->assertNotNull(RelocationRequestService::parseCoords('357 391'));
        $this->assertSame([12, 34], RelocationRequestService::parseCoords('иди на 12 34 пожалуйста'));
    }

    /** Одного числа мало — координат две. */
    public function testRejectsSingleNumber(): void
    {
        $this->assertNull(RelocationRequestService::parseCoords('357'));
        $this->assertNull(RelocationRequestService::parseCoords('не знаю'));
        $this->assertNull(RelocationRequestService::parseCoords(''));
    }

    /**
     * Координаты — максимум 4 цифры (1..1000). Длинное число режется на куски, а не превращается
     * в мусорный X. Диапазон всё равно проверит `handleCoords`, но парсер не должен фантазировать.
     */
    public function testLongNumberIsNotTreatedAsCoordinate(): void
    {
        $coords = RelocationRequestService::parseCoords('12345678901234567890');
        $this->assertNotNull($coords);
        $this->assertLessThan(10000, $coords[0], 'Число длиннее 4 цифр координатой быть не может.');
    }

    /** Маркер промпта стабилен: по нему GenericmessageCommand узнаёт ответ игрока. */
    public function testPromptMarkerIsStable(): void
    {
        $this->assertSame('🚚 ПЕРЕЕЗД', RelocationRequestService::PROMPT_MARKER);
    }
}
