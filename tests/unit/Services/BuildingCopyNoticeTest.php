<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Buildings\BuildingCopyNotice;
use App\Services\Onboarding\FirstShelterService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * building-bonus-absorption story-02 — тексты, которыми игра честно объясняет то, что
 * сейчас делает молча: поглощение бонусов дублей и одноразовость «Навеса».
 *
 * Круг 2 (ревью): добавлены случаи «нет оборонного бонуса у обычного здания», «три разных
 * причины отказа Навеса» и «fitting-версия никогда не выталкивает caption за лимит».
 *
 * Чисто поведенческий тест (без БД) — {@see BuildingCopyNotice} сам без побочных эффектов.
 *
 * @internal
 */
final class BuildingCopyNoticeTest extends CIUnitTestCase
{
    /**
     * Для НЕ-оборонного здания (стакается ли оборона = false) оговорка держит только два
     * факта, верных для любого здания: бонус один раз на все базы; налог не растёт от копии
     * на той же базе, но берётся с каждой базы отдельно. Про оборону — ни слова: обещание
     * «крепче оборона» было ложью для 13 зданий из 16 ({@see \App\Services\PVE\DefenseStructureService}
     * читает бонус только у `building_type='defensive'`).
     */
    public function testDuplicateWarningForNonDefensiveHasNoRaidPromise(): void
    {
        $text = BuildingCopyNotice::duplicateWarning('🚰', 'Ручная скважина', false);

        $this->assertStringContainsString('один раз на тип здания', $text);
        $this->assertStringContainsString('новых бонусов', $text);
        $this->assertStringContainsString('на этой базе не', $text);
        $this->assertStringContainsString('растёт', $text);
        $this->assertStringContainsString('с каждой базы берётся отдельно', $text);

        $this->assertStringNotContainsString('оборон', $text);
        $this->assertStringNotContainsString('рейд', $text);
    }

    /**
     * Для стакающегося оборонного здания (стена/ограда) третий факт — про оборону этой
     * конкретной базы — появляется. Вышка сюда не относится (presence, не стакает) —
     * caller передаёт `false` для неё, этот тест только про сам текст при `true`.
     */
    public function testDuplicateWarningForStackingDefensiveAddsRaidFact(): void
    {
        $text = BuildingCopyNotice::duplicateWarning('🪵', 'Деревянная стена', true);

        $this->assertStringContainsString('оборону именно этой базы', $text);
        $this->assertStringContainsString('рейда', $text);
    }

    /**
     * Три причины отказа «Навеса» — три разных, правдивых текста, не один общий «недоступно».
     * Ветерану, который Навес никогда не строил (перерос уровень), нельзя говорить «уже
     * поставил» — и наоборот.
     */
    public function testLeanToGateExplanationDiffersByReason(): void
    {
        $usedUp = BuildingCopyNotice::leanToGateExplanation(FirstShelterService::REASON_ALREADY_USED);
        $this->assertStringContainsString('уже поставил', $usedUp);
        $this->assertStringNotContainsString('перерос', $usedUp);

        $outgrew = BuildingCopyNotice::leanToGateExplanation(FirstShelterService::REASON_OUTGREW);
        $this->assertStringContainsString('перерос', $outgrew);
        $this->assertStringNotContainsString('уже поставил', $outgrew);

        $disabled = BuildingCopyNotice::leanToGateExplanation(FirstShelterService::REASON_DISABLED);
        $this->assertStringContainsString('недоступно', $disabled);
        $this->assertStringNotContainsString('уже поставил', $disabled);
        $this->assertStringNotContainsString('перерос', $disabled);

        // Все три указывают, куда идти дальше — не тупик.
        foreach ([$usedUp, $outgrew, $disabled] as $text) {
            $this->assertStringContainsString('списке построек', $text);
        }
    }

    /**
     * Fitting-версия — сердце фикса п.3 (ревью): полный текст добавляет ~330-430 байт, а
     * happy-path превью Арсенала/Склада (замерено `php spark tmp:measure-caption`,
     * одноразовый скрипт, не в репозитории) — 817 и 1022 байта из 1024 БЕЗ оговорки. Тест
     * держит инвариант «никогда не выталкивает готовый caption за лимит», а не конкретные числа
     * реального рендера (для этого — Tier-3 смоук, вне этой истории).
     */
    public function testDuplicateWarningFittingNeverExceedsCaptionLimit(): void
    {
        $limit = BuildingCopyNotice::CAPTION_BYTE_LIMIT;

        // Случай "впритык": caption уже занял почти весь бюджет — остаётся место только
        // под короткую версию (полная ~330+ байт не влезет, короткая ~150 влезет).
        $tightCaption = str_repeat('a', $limit - 200);
        $fitting      = BuildingCopyNotice::duplicateWarningFitting($tightCaption, '⚔️', 'Арсенал', false);
        $this->assertNotSame('', $fitting, 'При наличии ~200 байт места короткая версия обязана появиться');
        $this->assertLessThanOrEqual($limit, strlen($tightCaption) + strlen($fitting));
        $this->assertSame($fitting, BuildingCopyNotice::duplicateWarningShort(false));

        // Случай "нет места вообще": caption уже сам на пределе (или за ним) — как реальный
        // Склад (1022/1024 БЕЗ нашей оговорки). Fitting обязана вернуть '' — не тронуть caption.
        $fullCaption = str_repeat('a', $limit);
        $none        = BuildingCopyNotice::duplicateWarningFitting($fullCaption, '🏚️', 'Склад', false);
        $this->assertSame('', $none, 'Без места fitting не должна ничего добавлять — предыдущий баг caption\'а не наш, но и не наша задача его усугублять');

        // Случай "места полно": обычное лёгкое здание — полная версия.
        $roomyCaption = str_repeat('a', 200);
        $full         = BuildingCopyNotice::duplicateWarningFitting($roomyCaption, '🚰', 'Ручная скважина', false);
        $this->assertSame($full, BuildingCopyNotice::duplicateWarning('🚰', 'Ручная скважина', false));
    }

    /**
     * Легаси-Markdown-парсер Telegram роняет отправку (400) молча при непарной `*` —
     * memory `feedback_legacy_markdown_no_backslash_escape`. Каждая строка класса обязана
     * держать чётное число звёздочек.
     */
    public function testAllSamplesHaveEvenAsteriskCount(): void
    {
        foreach (BuildingCopyNotice::allSamples() as $sample) {
            $count = substr_count($sample, '*');
            $this->assertSame(
                0,
                $count % 2,
                "Непарное число '*' ломает Telegram-отправку молча (400): {$sample}"
            );
        }
    }

    /**
     * Фото-подпись Telegram обрезается на 1024 БАЙТАХ (не символах — кириллица занимает
     * 2 байта, memory `feedback_bytes_vs_chars_utf8_traps`), и отправка при превышении
     * тихо проваливается. Эти строки — не вся подпись, а добавка к уже существующему
     * превью (ресурсы/зависимости/предупреждения выше). Порог 500 байт — почти половина
     * лимита, оставляет запас под остаток caption; реальный самый тяжёлый случай (Склад)
     * фикс п.3 держит через `duplicateWarningFitting()`, а не через укорачивание всех строк.
     */
    public function testAllSamplesLeaveRoomUnderCaptionLimit(): void
    {
        foreach (BuildingCopyNotice::allSamples() as $sample) {
            $bytes = strlen($sample);
            $this->assertLessThan(
                500,
                $bytes,
                "Строка занимает {$bytes} байт — не оставляет запас под лимит подписи 1024: {$sample}"
            );
        }
    }
}
