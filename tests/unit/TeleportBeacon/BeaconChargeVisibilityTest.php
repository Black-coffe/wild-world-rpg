<?php

declare(strict_types=1);

namespace Tests\Unit\TeleportBeacon;

use App\Services\Player\TeleportBeacon\BeaconMessageFormatter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Гейт видимости заряда маяка.
 *
 * Вопрос игрока (Анжела, 18.08.2026): «как узнать, сколько заряда осталось в маяке?»
 * Остаток жил в `teleport_beacons.remaining_uses`, но на экране «📡 Маяки» не было ни
 * одного установленного маяка, а в списке перемещения остаток стоял нечитаемым хвостом
 * «ТП. 87».
 *
 * Отдельно держим ДЛИНУ caption'а: экран маяков уходит фотографией, а Telegram молча
 * не отправляет фото с caption > 1024 символов (ok=false, игрок видит зависшую игру).
 * Note в коде такую деградацию не ловит — ловит тест на худшем случае.
 */
final class BeaconChargeVisibilityTest extends CIUnitTestCase
{
    /** Жёсткий предел Telegram на caption фотографии. */
    private const CAPTION_LIMIT = 1024;

    public function testOverviewNamesChargeAndTaxInWords(): void
    {
        $text = (new BeaconMessageFormatter())->beaconsOverview(
            [['x' => 12, 'y' => 44, 'uses' => 87, 'max_uses' => 100, 'biome' => 'Лес', 'tax' => 180]],
            ['x' => 5, 'y' => 7, 'cell' => 4321, 'biome' => 'Горы'],
            30,
            3,
            2,
            5,
            1,
            100,
            180
        );

        $this->assertStringContainsString('Установлено маяков: 1', $text, 'Свои маяки должны быть видны на экране маяков.');
        $this->assertStringContainsString('87', $text, 'Остаток телепортов обязан быть в тексте.');
        $this->assertStringContainsString('X=12 Y=44', $text, 'Без координат непонятно, у какого маяка какой заряд.');
        $this->assertStringContainsString('телепортов', $text, 'Число без слова читается как «ТП. 87».');
        $this->assertStringContainsString('180', $text, 'Налог за маяк игрок должен видеть на экране маяков.');
        $this->assertStringNotContainsString('ТП.', $text, 'Старый нечитаемый хвост не должен вернуться.');
    }

    /**
     * Экран без единого маяка не должен молчать про заряд — иначе исходный вопрос
     * («а где вообще смотреть?») остаётся без ответа.
     */
    public function testOverviewWithoutBeaconsStillExplains(): void
    {
        $text = (new BeaconMessageFormatter())->beaconsOverview(
            [],
            ['x' => 5, 'y' => 7, 'cell' => 4321, 'biome' => 'Горы'],
            9,
            0,
            1,
            1,
            0,
            100,
            180
        );

        $this->assertStringContainsString('Установлено маяков: 0', $text);
        $this->assertStringContainsString('100', $text, 'Сколько телепортов даёт новый маяк — должно быть сказано.');
    }

    /**
     * 🔴 Худший случай: максимум маяков (20 = 10 по уровню + 10 уровней Центра),
     * длинные названия биомов, четырёхзначные координаты и большие числа.
     * Caption обязан влезть в 1024 символа, иначе фото уйдёт в тишину.
     */
    public function testCaptionFitsTelegramLimitAtWorstCase(): void
    {
        $beacons = [];
        for ($i = 0; $i < 20; $i++) {
            $beacons[] = [
                'x'        => 1000,
                'y'        => 1000,
                'uses'     => 100,
                'max_uses' => 100,
                'biome'    => 'Тропические джунгли',
                'tax'      => 180,
            ];
        }

        $text = (new BeaconMessageFormatter())->beaconsOverview(
            $beacons,
            ['x' => 1000, 'y' => 1000, 'cell' => 1000000, 'biome' => 'Тропические джунгли'],
            100,
            10,
            10,
            20,
            9999,
            100,
            180
        );

        $this->assertLessThanOrEqual(
            self::CAPTION_LIMIT,
            mb_strlen($text),
            'Caption экрана маяков перевалил за 1024 — Telegram молча не отправит фото.'
        );
    }

    /**
     * Markdown в легаси parse_mode не экранируется, поэтому непарная `*` роняет
     * отправку целиком (400 → сообщение не доходит).
     */
    public function testMarkdownAsterisksArePaired(): void
    {
        $text = (new BeaconMessageFormatter())->beaconsOverview(
            [['x' => 1, 'y' => 2, 'uses' => 3, 'max_uses' => 100, 'biome' => 'Поля', 'tax' => 180]],
            ['x' => 1, 'y' => 2, 'cell' => 3, 'biome' => 'Поля'],
            20,
            2,
            1,
            3,
            1,
            100,
            180
        );

        $this->assertSame(0, substr_count($text, '*') % 2, 'Непарная «*» ломает Markdown-рендер Telegram.');
    }

    /**
     * 🔴 Выход из тупика «лимит забит выработанными маяками». Лимит считает ВСЕ строки
     * игрока, включая маяки с ⚡ 0, а отказ установки советует освободить место — значит,
     * снятие обязано существовать и резолвиться.
     */
    public function testBeaconRemovalIsReachable(): void
    {
        $routes  = new CallbackRoutes();
        $handler = \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconRemoveAction::class;

        foreach ([
            'teleportBeaconRemove',
            'teleportBeaconRemove_id=7',
            'teleportBeaconRemoveGo_id=7',
        ] as $callbackData) {
            $this->assertSame(
                $handler,
                $routes->resolve(explode('_', $callbackData)[0]),
                "callback_data '{$callbackData}' не резолвится — снять маяк будет нечем."
            );
        }

        $screen = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportBeacon.php'
        );
        $this->assertStringContainsString(
            "'callback_data' => 'teleportBeaconRemove'",
            $screen,
            'Кнопка снятия обязана быть на экране «📡 Маяки» — иначе выход из лимита снова недостижим.'
        );

        $validator = (string) file_get_contents(APPPATH . 'Services/Player/TeleportBeacon/BeaconPlacementValidator.php');
        $this->assertStringNotContainsString(
            'Сначала удали старый маяк',
            $validator,
            'Отказ по лимиту не должен обещать действие, не называя пути к нему.'
        );
    }
}
