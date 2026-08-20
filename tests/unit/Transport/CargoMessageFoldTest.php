<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\Player\Gather\GatherMessageFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * transport-15 — груз-нота живёт ВНУТРИ сообщения о добыче, а не отдельным сообщением.
 *
 * Чистый render-тест на `GatherMessageFormatter::buildResourcesFoundReply` (без Telegram,
 * без БД) — ровно то, что просит `## Acceptance`: длина/markdown-safety проверяются на
 * чистой render-функции. Контракт возврата `GatherResultPersister::persist()`
 * (`$foldCargoNote=true` → строка вместо отдельного `sendMessage`) покрыт существующим
 * `CargoSplitTest.php` (story-08) — он не в Files этой стори и не тронут: дефолт-false
 * у нового параметра держит его контракт байт-в-байт.
 *
 * @internal
 */
final class CargoMessageFoldTest extends CIUnitTestCase
{
    private GatherMessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new GatherMessageFormatter();
    }

    private function resourceEntity(int $id, string $name, int $rarity): object
    {
        return (object) ['id' => $id, 'name' => $name, 'rarity' => $rarity];
    }

    // ── Без грузовой машины — байт-идентично сегодняшнему ──────────────────

    public function testNoCargoNoteIsByteIdenticalToOmittingParameter(): void
    {
        $foundResources = [['resource_id' => 1, 'amount' => 5, 'type' => 'raw']];
        $resourceMap    = [1 => $this->resourceEntity(1, 'Дерево', 10)];

        $withExplicitNull = $this->formatter->buildResourcesFoundReply(
            $foundResources,
            'Лес',
            30,
            $resourceMap,
            [],
            [],
            [],
            [],
            null
        );
        $withoutParam = $this->formatter->buildResourcesFoundReply(
            $foundResources,
            'Лес',
            30,
            $resourceMap,
            [],
            []
        );

        $this->assertSame($withoutParam, $withExplicitNull);
        $this->assertStringNotContainsString('🚚', $withExplicitNull['text'], 'без груза строки про склад быть не должно');
    }

    public function testEmptyCargoNoteAlsoAddsNothing(): void
    {
        $foundResources = [['resource_id' => 1, 'amount' => 5, 'type' => 'raw']];
        $resourceMap    = [1 => $this->resourceEntity(1, 'Дерево', 10)];

        $withNull = $this->formatter->buildResourcesFoundReply($foundResources, 'Лес', 30, $resourceMap, [], []);
        $withEmpty = $this->formatter->buildResourcesFoundReply($foundResources, 'Лес', 30, $resourceMap, [], [], [], [], '');

        $this->assertSame($withNull, $withEmpty);
    }

    // ── С грузовой машиной — ровно одно сообщение, строка внутри ───────────

    public function testCargoNoteFoldsIntoSingleMessage(): void
    {
        $foundResources = [['resource_id' => 1, 'amount' => 10, 'type' => 'raw']];
        $resourceMap    = [1 => $this->resourceEntity(1, 'Дерево', 10)];
        $cargoNote      = '🚚 На склад базы уехало: Дерево × 3.';

        $reply = $this->formatter->buildResourcesFoundReply(
            $foundResources,
            'Лес',
            30,
            $resourceMap,
            [],
            [],
            [],
            [],
            $cargoNote
        );

        // Ровно одна структура ответа — не два вызова, не два text/keyboard.
        $this->assertArrayHasKey('text', $reply);
        $this->assertArrayHasKey('keyboard', $reply);

        $this->assertStringContainsString('Дерево', $reply['text'], 'название ресурса, уехавшего на склад');
        $this->assertStringContainsString('3', $reply['text'], 'количество, уехавшее на склад');
        $this->assertStringContainsStringIgnoringCase('склад', $reply['text']);
    }

    // ── m1: «груз некуда везти» (нет базы) сворачивается точно так же ──────

    public function testNoBaseNoteFoldsIntoSingleMessage(): void
    {
        $foundResources = [['resource_id' => 1, 'amount' => 10, 'type' => 'raw']];
        $resourceMap    = [1 => $this->resourceEntity(1, 'Дерево', 10)];
        // Текст 1-в-1 с `GatherResultPersister::composeNoBaseNote()`.
        $cargoNote = '🚚 Груз некуда везти: своей базы нет — вся добыча осталась в рюкзаке.';

        $reply = $this->formatter->buildResourcesFoundReply(
            $foundResources,
            'Лес',
            30,
            $resourceMap,
            [],
            [],
            [],
            [],
            $cargoNote
        );

        $this->assertArrayHasKey('text', $reply);
        $this->assertStringContainsString('Груз некуда везти', $reply['text']);
        $this->assertLessThanOrEqual(1024, mb_strlen($reply['text']));
    }

    // ── Итог ≤ 1024 знаков (иначе Telegram молча не отправит фото с подписью) ──

    public function testFoldedMessageStaysUnder1024Chars(): void
    {
        $foundResources = [];
        $resourceMap    = [];
        for ($i = 1; $i <= 6; $i++) {
            $foundResources[] = ['resource_id' => $i, 'amount' => 10 + $i, 'type' => 'raw'];
            $resourceMap[$i]  = $this->resourceEntity($i, 'Ресурс номер ' . $i, ($i % 10) + 1);
        }
        $usedToolsCount = ['axe' => 3, 'pickaxe' => 2];
        $toolByName     = [
            'axe'     => ['name_rus' => 'Топор'],
            'pickaxe' => ['name_rus' => 'Кирка'],
        ];
        $cargoNote = '🚚 На склад базы уехало: Ресурс номер 1 × 3, Ресурс номер 2 × 4, Ресурс номер 3 × 2.';

        $reply = $this->formatter->buildResourcesFoundReply(
            $foundResources,
            'Заснеженные пустоши',
            120,
            $resourceMap,
            $usedToolsCount,
            $toolByName,
            [],
            ['Ресурс номер 1', 'Ресурс номер 2'],
            $cargoNote
        );

        $this->assertLessThanOrEqual(1024, mb_strlen($reply['text']), 'caption с грузом обязан влезать в лимит Telegram');
    }

    // ── markdown/HTML-safe: непарные спецсимволы в ноте не ломают parse_mode ──

    public function testCargoNoteIsHtmlEscaped(): void
    {
        $foundResources = [['resource_id' => 1, 'amount' => 10, 'type' => 'raw']];
        $resourceMap    = [1 => $this->resourceEntity(1, 'Дерево', 10)];
        $cargoNote      = '🚚 На склад базы уехало: <script>alert(1)</script> & "штука" × 3.';

        $reply = $this->formatter->buildResourcesFoundReply($foundResources, 'Лес', 30, $resourceMap, [], [], [], [], $cargoNote);

        $this->assertStringNotContainsString('<script>', $reply['text']);
        $this->assertStringContainsString('&lt;script&gt;', $reply['text']);
        $this->assertStringContainsString('&amp;', $reply['text']);
    }
}
