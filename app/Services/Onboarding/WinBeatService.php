<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * ADR-139 ретеншн-спринт S4 «Cold-open», слайс 4 — win-beat «глава 1 закрыта».
 *
 * Усиливает момент завершения шага обучающей цепочки {@see OnboardingChainCatalog}
 * («Первые шаги выжившего», 8 шагов OnbStep*):
 *   • шаги 1..N-1 → к существующему `done_text` добавляется ПОЛОСА ПРОГРЕССА «N из M»
 *     (эффект Зейгарник: видимая близость финиша тянет дойти — бьёт по обрывам
 *     цепочки на Craft/Build, см. воронку прод 2026-06-26);
 *   • финальный шаг → КАПСТОН «Глава 1 закрыта» (recap пути + рамка главы 2 + CTA в
 *     «Квесты») вместо рядового step-уведомления.
 *
 * Гейт — ОТДЕЛЬНЫЙ суб-killswitch `onboarding.cold_open_v2.win_beat` (default OFF),
 * НЕ мастер-флаг полярной звезды: своя копия player-facing → свой Tier-3 + одобрение
 * владельцем до экспозиции (паттерн слайсов 1b/3). При OFF {@see compose} возвращает
 * null → caller ({@see \App\TaskHandlers\Quests\QuestObjectiveHandler::notifyOnboarding})
 * шлёт ЛЕГАСИ-текст байт-в-байт (без новых наград — чистый текст, balance не задет).
 *
 * Инварианты: media-off (всё в тексте, картинок нет); markdown-balanced (парные `*`/`_`);
 * полоса/число шагов выводятся из {@see OnboardingChainCatalog::total} → нет дрейфа,
 * если цепочка вырастет.
 */
class WinBeatService
{
    use GameSettingsReaderTrait;

    /** Killswitch: усиленный win-beat завершения онбординг-шага. Default false → dormant. */
    public function enabled(): bool
    {
        return $this->gsBool('onboarding.cold_open_v2.win_beat', false);
    }

    /**
     * Декорация завершения онбординг-шага. Возвращает ПОЛНЫЙ текст уведомления
     * (включая строку награды) ЛИБО null, если win-beat не применяется:
     *   • killswitch OFF — null (caller использует легаси-путь = byte-identical);
     *   • title_en не принадлежит онбординг-цепочке — null.
     *
     * @param string $titleEn  title_en завершённого шага
     * @param string $doneText каталожная just-in-time подсказка шага (OnboardingChainCatalog::done_text)
     * @param int    $reward   награда золота за шаг (0 — не показывать строку)
     */
    public function compose(string $titleEn, string $doneText, int $reward): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $pos = OnboardingChainCatalog::position($titleEn);
        if ($pos === null) {
            return null;
        }

        $total = OnboardingChainCatalog::total();

        // Финальный шаг → капстон «Глава 1 закрыта».
        if ($pos >= $total) {
            return $this->capstone($total, $reward);
        }

        // Шаги 1..N-1 → done_text + полоса прогресса (+ награда за шаг).
        $text = $doneText
            . "\n\n" . $this->progressBar($pos, $total) . "  Глава 1 — {$pos} из {$total}";
        if ($reward > 0) {
            $text .= "\n🏆 +{$reward} золота за шаг.";
        }

        return $text;
    }

    /**
     * Капстон-беат завершения всей цепочки. Полоса полностью заполнена; журей-эмодзи
     * пути новичка — нарративная строка (одобрена владельцем 2026-06-26).
     */
    private function capstone(int $total, int $reward): string
    {
        $text = "🏁 *ГЛАВА 1 ЗАКРЫТА*\n\n"
            . "«Первые шаги выжившего» пройдены полностью.\n"
            . "🧭⛏🛠🏕🏗💰📈 — весь путь новичка за спиной.\n"
            . $this->progressBar($total, $total) . "  {$total} из {$total}\n\n"
            . "Впереди глава 2: еда и ферма, бой с тварями, поселения NPC, на 10 уровне — выбор фракции.\n"
            . "_Загляни в «Квесты» — ждут новые цели._";
        if ($reward > 0) {
            $text .= "\n🏆 +{$reward} золота.";
        }

        return $text;
    }

    /** Полоса прогресса: $filled зелёных + остаток белых, всего $total ячеек. */
    private function progressBar(int $filled, int $total): string
    {
        $filled = max(0, min($filled, $total));

        return str_repeat('🟩', $filled) . str_repeat('⬜', $total - $filled);
    }
}
