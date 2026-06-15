<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\GuideCatalog;
use App\Services\Onboarding\GuideService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-127 — справочник-онбординг «📖 Путь новичка» (/guide).
 *
 * Каталог и сервис — pure-данные/рендер без БД. Тест гейтит:
 *  1) структурную целостность каталога (ключи, группы, навигация);
 *  2) достижимость каждого раздела из оглавления (UX-discoverability);
 *  3) корректность роутинг-парсинга ключей (урок мёртвых `npcAct_`);
 *  4) 🔴 АНТИ-АБЬЮЗ: source-scan — ни один guide-файл НЕ содержит вызовов
 *     выдачи/мутации (StarterKit/LuckyFind/insert/update/save). /guide обязан
 *     быть read-only «сколько угодно раз», без наград и лазеек.
 *
 * @internal
 */
final class GuideCatalogTest extends CIUnitTestCase
{
    // ── Структура каталога ──────────────────────────────────────────────────

    public function testHasSectionsGroupedIntoKnownGroups(): void
    {
        $sections = GuideCatalog::sections();
        $this->assertNotEmpty($sections);

        foreach ($sections as $section) {
            $this->assertArrayHasKey($section['group'], GuideCatalog::GROUPS, "Группа '{$section['group']}' неизвестна.");
            $this->assertNotSame('', trim($section['key']));
            $this->assertNotSame('', trim($section['button']));
            $this->assertNotSame('', trim($section['title']));
            $this->assertNotSame('', trim($section['body']));
        }
    }

    /**
     * 🔴 Шейп раздела — РОВНО {key, group, button, title, body}. Если кто-то добавит
     * `reward`/`gold`/`grant` — тест упадёт: справочник не должен носить награды.
     */
    public function testSectionShapeHasNoRewardLikeFields(): void
    {
        foreach (GuideCatalog::sections() as $section) {
            $this->assertSame(
                ['key', 'group', 'button', 'title', 'body'],
                array_keys($section),
                'Раздел справочника должен содержать только текстовые поля — никаких наград/выдачи.'
            );
        }
    }

    public function testSectionKeysAreUnique(): void
    {
        $keys = array_column(GuideCatalog::sections(), 'key');
        $this->assertCount(count($keys), array_unique($keys), 'Ключи разделов обязаны быть уникальны.');
    }

    /**
     * 🔴 Урок мёртвых `npcAct_`: callback раздела = `guide_<key>`, резолвится по первому
     * сегменту `guide`. Если key содержит `_`, парсинг хвоста сломается семантически —
     * запрещаем `_` (и вообще оставляем только [a-z]).
     */
    public function testSectionKeysAreLowercaseLettersOnly(): void
    {
        foreach (GuideCatalog::sections() as $section) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]+$/',
                $section['key'],
                "Ключ раздела '{$section['key']}' должен быть только из строчных латинских букв (без '_'/цифр)."
            );
        }
    }

    // ── Навигация ───────────────────────────────────────────────────────────

    public function testNextKeyFormsValidChainEndingInNull(): void
    {
        $sections = GuideCatalog::sections();
        $count    = count($sections);

        foreach ($sections as $i => $section) {
            $next = GuideCatalog::nextKey($section['key']);
            if ($i === $count - 1) {
                $this->assertNull($next, 'У последнего раздела не должно быть «следующего».');
            } else {
                $this->assertNotNull($next);
                $this->assertNotNull(GuideCatalog::find((string) $next), "nextKey '{$next}' обязан указывать на существующий раздел.");
            }
        }
    }

    public function testFindReturnsSectionOrNull(): void
    {
        $first = GuideCatalog::sections()[0]['key'];
        $this->assertNotNull(GuideCatalog::find($first));
        $this->assertNull(GuideCatalog::find('no_such_key'));
    }

    // ── Сервис рендера ──────────────────────────────────────────────────────

    public function testIndexExposesButtonForEverySection(): void
    {
        $payload = (new GuideService())->indexPayload();
        $markup  = json_decode($payload['reply_markup'], true);
        $this->assertIsArray($markup);
        $rows = $markup['inline_keyboard'] ?? null;
        $this->assertIsArray($rows);

        $callbacks = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            foreach ($row as $btn) {
                $this->assertIsArray($btn);
                $callbacks[] = $btn['callback_data'] ?? null;
            }
        }

        foreach (GuideCatalog::sections() as $section) {
            $this->assertContains(
                'guide_' . $section['key'],
                $callbacks,
                "Раздел '{$section['key']}' обязан иметь кнопку в оглавлении (UX-discoverability)."
            );
        }
    }

    public function testSectionPayloadRendersTitleBodyAndBackButton(): void
    {
        $key     = GuideCatalog::sections()[0]['key'];
        $payload = (new GuideService())->sectionPayload($key);
        $section = GuideCatalog::find($key);
        $this->assertNotNull($section);

        $this->assertStringContainsString($section['title'], $payload['text']);
        $this->assertStringContainsString($section['body'], $payload['text']);
        $this->assertStringContainsString('"guide"', $payload['reply_markup'], 'Должна быть кнопка «⬅️ К оглавлению» (callback «guide»).');
    }

    public function testUnknownSectionFallsBackToIndex(): void
    {
        $service = new GuideService();
        $this->assertSame(
            $service->indexPayload()['text'],
            $service->sectionPayload('totally_unknown')['text'],
            'Неизвестный ключ раздела обязан безопасно падать в оглавление.'
        );
    }

    public function testKeyFromCallbackParsing(): void
    {
        $this->assertSame('', GuideService::keyFromCallback('guide'));
        $this->assertSame('combat', GuideService::keyFromCallback('guide_combat'));
        $this->assertSame('', GuideService::keyFromCallback('somethingElse'));
    }

    /**
     * 🖼 MEDIA-OFF: payload'ы текстовые — никаких 'photo'; reply_markup — валидный JSON.
     */
    public function testPayloadsAreTextOnlyAndValidJson(): void
    {
        $service  = new GuideService();
        $payloads = [$service->indexPayload(), $service->sectionPayload(GuideCatalog::sections()[0]['key'])];

        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('photo', $payload, 'Справочник media-off: фото быть не должно.');
            $this->assertNotSame('', trim($payload['text']));
            $this->assertIsArray(json_decode($payload['reply_markup'], true), 'reply_markup обязан быть валидным JSON.');
        }
    }

    /**
     * Telegram legacy Markdown кидает 400 «Can't parse entities» при нечётном числе
     * `*` или `_`. Гейтим баланс во ВСЕХ рендерах (оглавление + каждый раздел) — это
     * подмена части Tier-3 (история багов экранирования S5b/Sell-#6).
     */
    public function testRenderedTextHasBalancedMarkdownEntities(): void
    {
        $service = new GuideService();

        $texts = [$service->indexPayload()['text']];
        foreach (GuideCatalog::sections() as $section) {
            $texts[] = $service->sectionPayload($section['key'])['text'];
        }

        foreach ($texts as $text) {
            $this->assertSame(0, substr_count($text, '*') % 2, "Несбалансированные «*» в Markdown:\n{$text}");
            $this->assertSame(0, substr_count($text, '_') % 2, "Несбалансированные «_» в Markdown:\n{$text}");
        }
    }

    // ── 🔴 АНТИ-АБЬЮЗ (source-scan) ──────────────────────────────────────────

    /**
     * Главный инвариант фичи: /guide ничего не выдаёт и ничего не меняет. Ни один из
     * 4 guide-файлов не должен ссылаться на сервисы выдачи или write-методы моделей.
     * Если кто-то «обогатит» справочник наградой за просмотр — тест поймает.
     */
    public function testGuideFilesContainNoGrantOrMutationCalls(): void
    {
        $files = [
            APPPATH . 'Services/Onboarding/GuideCatalog.php',
            APPPATH . 'Services/Onboarding/GuideService.php',
            APPPATH . 'Controllers/Telegram/Commands/GuideCommand.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Guide/GuideAction.php',
        ];

        // Code-level признаки выдачи/мутации (не трогают русскоязычный текст-пояснения).
        $forbidden = [
            'StarterKitService',
            'LuckyFindService',
            'ensureChainAssigned',
            'CharacterResourceModel',
            '->insert(',
            '->update(',
            '->save(',
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "АНТИ-АБЬЮЗ: '{$needle}' в " . basename($file) . " — /guide обязан быть read-only (без выдачи/мутаций)."
                );
            }
        }
    }
}
