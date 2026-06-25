<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — cold-open framing /start (спина-слайс 1b).
 *
 * Проблема (S1): 58% свежей когорты НЕ называют чара — первый экран /start (121 слово)
 * слишком плотный для немедленного bounce. Cold-open вариант — короткое, интригующее
 * вступление от лица Роби (постапок-тон), ведущее к единственному CTA «назвать героя».
 * Полярная звезда (слайс 1a) дальше показывает первую цель на карточке/карте.
 *
 * Гейт — ОТДЕЛЬНЫЙ суб-killswitch `onboarding.cold_open_v2.start_greeting` (default OFF →
 * dormant = легаси-приветствие 121 слово, byte-identical). Намеренно НЕ мастер-флаг
 * `onboarding.cold_open_v2.enabled` (он уже включён для полярной звезды): первое впечатление
 * — субъективная высокоставочная копия, ей нужен СВОЙ Tier-3 cold-smoke + одобрение копии
 * владельцем до экспозиции (П10: «обновление не ломает онбординг»).
 *
 * Текст в коде (не GameSettings: value_string=255 мал). Самодостаточен (media-off, ADR-020),
 * markdown-safe (парные `*`, ссылка `[..](..)`).
 */
class ColdOpenGreetingService
{
    use GameSettingsReaderTrait;

    /** Суб-killswitch 1b: показывать cold-open приветствие вместо легаси. Default OFF → dormant. */
    public function greetingEnabled(): bool
    {
        return $this->gsBool('onboarding.cold_open_v2.start_greeting', false);
    }

    /**
     * Cold-open приветствие новичку (постапок-тон Роби, ~3 коротких абзаца). Ведёт к CTA
     * «🤔 Задать имя персонажа» (кнопку добавляет StartCommand следом). Самодостаточно.
     */
    public function greeting(): string
    {
        return "🤖 Я — *Роби*, твой проводник. Ты очнулся на острове, где рухнула цивилизация: "
            . "кругом пустошь, а выжившие сами решают, как жить дальше.\n\n"
            . "Здесь всё — своими руками: разведка, добыча, крафт, своя база. Первые шаги пройдём "
            . "вместе, дальше пустошь — твоя.\n\n"
            . "Сначала назови героя — и в путь. Вопросы и стратегии — в "
            . "[общем чате](https://t.me/wild_world_info).";
    }
}
