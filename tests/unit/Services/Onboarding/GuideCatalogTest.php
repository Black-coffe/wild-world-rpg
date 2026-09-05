<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\GuideCatalog;
use App\Services\Onboarding\GuideService;
use App\Services\Player\ProfileHubService;
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

    /**
     * Инцидент 2026-09-05: справочник не имел раздела про специализацию вовсе, а совет звал
     * по пути без хаба «⚙️ Развитие». Гейтим и наличие раздела, и то, что путь в нём назван
     * живой меткой хаба, а не хардкодом.
     */
    public function testSpecializationSectionNamesItsHubPath(): void
    {
        $sections = array_column(GuideCatalog::sections(), 'body', 'key');

        $this->assertArrayHasKey('spec', $sections, 'В справочнике обязан быть раздел про специализацию крафта.');
        $this->assertStringContainsString(ProfileHubService::HUB_DEVELOPMENT_LABEL, $sections['spec']);
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

    /**
     * WB14 (ADR-137 «Узлы») — раздел «Узлы» в эндгейме: учит понятиям (что такое Узел,
     * Осмотр, Облава, трофей), media-off самодостаточен, и 🔴 БЕЗ слова «клан»
     * (клан/рейд-механика отрезана из v1 — упоминание ввело бы в заблуждение).
     */
    public function testBossesSectionTeachesNodesWithoutClan(): void
    {
        $section = GuideCatalog::find('bosses');
        $this->assertNotNull($section, 'Раздел «Узлы» (bosses) обязан быть в /guide.');
        $this->assertSame('end', $section['group'], 'Узлы — эндгейм-контент.');

        $text = $section['title'] . $section['body'];
        foreach (['Узл', 'Осмотр', 'Облав', 'Метка пустоши'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Раздел «Узлы» не упоминает «{$needle}» (media-off самодостаточность).");
        }
        $this->assertStringNotContainsStringIgnoringCase('клан', $text, 'Раздел «Узлы» НЕ должен упоминать клан (механика отрезана из v1).');
    }

    /**
     * Раздел «Телепорт» (2026-08-06, вопрос игрока «как пользоваться телепортом, который
     * не ранец?»): справочник обязан РАЗВОДИТЬ три устройства — маяк (точка возврата в мире)
     * против рюкзака и портативного (возврат домой). Именно их путаница и породила вопрос.
     */
    public function testTeleportSectionSeparatesBeaconFromReturnDevices(): void
    {
        $section = GuideCatalog::find('teleport');
        $this->assertNotNull($section, 'Раздел «Телепорт» обязан быть в /guide.');
        $this->assertSame('mid', $section['group'], 'Телепорт — механика среднего этапа.');

        $text = $section['title'] . $section['body'];
        foreach (['Маяки', 'Установить маяк здесь', 'Переместиться на маяк', 'рюкзак', 'Портативный'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Раздел «Телепорт» не упоминает «{$needle}».");
        }
    }

    /**
     * Story 07 (2ff1464a, storage-craft-insurance) расширила раздел «storage» двумя
     * смыслами: рюкзак и склад базы — единый запас для крафта/ремонта/апгрейда построек,
     * и забор со склада работает по одному виду ресурса (зеркало «Положить на склад»),
     * а не только «забрать всё». `GuideCatalogTest.php` был назван в `## Files` story 07,
     * но утверждения не появилось — story 12 закрывает этот пробел. Проверяем по устойчивым
     * смысловым маркерам, а не по точной формулировке: текст ещё будут редактировать.
     */
    public function testStorageSectionExplainsUnifiedPoolAtBase(): void
    {
        $section = GuideCatalog::find('storage');
        $this->assertNotNull($section, 'Раздел «Склад» (storage) обязан быть в /guide.');
        $body = $section['body'];

        foreach (['рюкзак', 'склад'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $body, "Раздел «Склад» не упоминает «{$needle}».");
        }

        // Единый пул касается всех трёх действий — крафта, ремонта, апгрейда построек.
        foreach (['крафт', 'ремонт', 'апгрейд'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $body, "Раздел «Склад» не упоминает «{$needle}» (единый пул трат).");
        }

        $hasPoolMarker = mb_stripos($body, 'общ') !== false || mb_stripos($body, 'един') !== false;
        $this->assertTrue($hasPoolMarker, 'Раздел «Склад» не называет запас рюкзака и склада общим/единым.');
    }

    public function testStorageSectionExplainsWithdrawByType(): void
    {
        $section = GuideCatalog::find('storage');
        $this->assertNotNull($section, 'Раздел «Склад» (storage) обязан быть в /guide.');
        $body = $section['body'];

        $this->assertStringContainsStringIgnoringCase('забрать', $body, 'Раздел «Склад» не объясняет, как забрать со склада.');

        $hasPerTypeMarker = false;
        foreach (['по одному', 'каждым вид', 'только его', 'не трогая'] as $marker) {
            if (mb_stripos($body, $marker) !== false) {
                $hasPerTypeMarker = true;
                break;
            }
        }
        $this->assertTrue($hasPerTypeMarker, 'Раздел «Склад» не объясняет забор по одному виду ресурса (зеркало «Положить на склад»).');
    }

    // ── chat-requests-batch-08: две новые двери дописаны в СУЩЕСТВУЮЩИЕ разделы ──

    /**
     * story 07 — «🔨 Снести постройку». Дописано в существующий раздел «база»
     * (owner: отдельный раздел не заводим), а не отдельным ключом.
     */
    public function testBaseSectionMentionsSingleBuildingDemolishWithoutRefund(): void
    {
        $section = GuideCatalog::find('base');
        $this->assertNotNull($section, 'Раздел «База» обязан быть в /guide.');
        $body = $section['body'];

        $this->assertStringContainsString('Снести постройку', $body, 'Раздел «База» не называет кнопку сноса ОДНОЙ постройки.');
        $this->assertStringContainsStringIgnoringCase('не возвращаются', $body, 'Раздел «База» обязан честно сказать: ресурсы не возвращаются.');

        // Без чисел баланса: ни ставки налога, ни длины кулдауна — они настраиваемые.
        // Единственные допустимые цифры в разделе — уже существующие шаги "1️⃣/2️⃣/3️⃣".
        $digits = [];
        preg_match_all('/\d+/', $body, $digits);
        foreach ($digits[0] as $digit) {
            $this->assertContains(
                (int) $digit,
                [1, 2, 3],
                "Раздел «База» не должен называть числа баланса (найдено «{$digit}») — они настраиваемые.",
            );
        }
    }

    /**
     * story 06 — «🧾 Куда ушло». Дописано в существующий раздел «склад»
     * (та же логика: где лежит vs куда делось — соседние вопросы одного игрока).
     */
    public function testStorageSectionMentionsWhereItWentLedger(): void
    {
        $section = GuideCatalog::find('storage');
        $this->assertNotNull($section, 'Раздел «Склад» (storage) обязан быть в /guide.');
        $body = $section['body'];

        $this->assertStringContainsString('Куда ушло', $body, 'Раздел «Склад» не упоминает экран «Куда ушло».');
        foreach (['налог', 'смерт', 'событ'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $body, "Раздел «Склад» не называет категорию «{$needle}» ленты «Куда ушло».");
        }

        // Глубина ленты (economy.ledger.depth) настраиваемая — именно параграф про
        // «Куда ушло» не смеет называть её числом (остальной раздел «Склад» цифры
        // содержит — шаги 1️⃣/2️⃣/3️⃣ карго-дрона, уровень мастерской — это не про эту дверь).
        $paragraphs  = explode("\n\n", $body);
        $ledgerParas = array_filter($paragraphs, static fn (string $p): bool => str_contains($p, 'Куда ушло'));
        $this->assertNotEmpty($ledgerParas, 'Не найден параграф про «Куда ушло» в разделе «Склад».');
        foreach ($ledgerParas as $para) {
            $this->assertDoesNotMatchRegularExpression('/\d/u', $para, 'Параграф про «Куда ушло» не должен называть число баланса (глубина ленты, ADR-134).');
        }
    }

    /**
     * ADR-176 (community-chat-bot), story 15 — раздел «💬 Общий чат» (ключ `chat`, группа
     * `meta`). Guide-вердикт Редколлегии: ДА — про навигацию и понятия (что это, где искать,
     * что Роби там читает и отвечает, срок ответа, куда с личным вопросом), без чисел баланса.
     */
    public function testChatSectionExplainsWhatItIsAndOwnerRoute(): void
    {
        $section = GuideCatalog::find('chat');
        $this->assertNotNull($section, 'Раздел «Общий чат» (chat) обязан быть в /guide.');
        $this->assertSame('meta', $section['group'], 'Общий чат — раздел «Полезное».');

        $body = $section['body'];
        foreach (['Роби', 'владельцу', 'суток'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $body, "Раздел «Общий чат» не упоминает «{$needle}».");
        }

        // Без чисел баланса — раздел про навигацию и понятия, не про настраиваемые пороги.
        $this->assertDoesNotMatchRegularExpression('/\d/u', $body, 'Раздел «Общий чат» не должен называть числа баланса.');
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
