<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterFactionModel;

/**
 * N-навигация (2026-06-11) — анти button-soup карточки Перса (memory
 * feedback_character_card_button_soup). Единый источник кнопок двух подменю-хабов:
 *   • «📊 Прогресс» — read-only виды (достижения/титулы/рейтинг/экономика/что нового);
 *   • «⚙️ Развитие» — прогресс-фичи (специализация/проект фракции/дрон/модернизация).
 *
 * Карточка Перса показывает ХАБ-кнопку (если в группе есть ≥1 включённая фича), а сами
 * кнопки рендерят {@see \App\Controllers\Telegram\Commands\Actions\Profile\ProgressHubAction}
 * / {@see \App\Controllers\Telegram\Commands\Actions\Profile\DevelopmentHubAction}. Та же
 * killswitch-логика, что была инлайн в CharacterService → discoverability сохранена, шум убран.
 */
final class ProfileHubService
{
    /**
     * 🔴 ЕДИНЫЙ ИСТОЧНИК меток двух хаб-кнопок карточки Перса — и для самой кнопки, и для
     * любого текста, который эту кнопку называет (справочник /guide, строка левелапа).
     *
     * Зачем (инцидент 2026-09-05): совет #57 годами звал «Вход: 👤 Перс → 🎓 Специализация»,
     * хотя с N-навигации (11.06) кнопка живёт ВНУТРИ хаба «⚙️ Развитие». Игрок L5+ читал
     * подсказку, смотрел на карточку и не находил ничего — тот же класс, что метки нижнего
     * меню, закрытый в {@see \App\Services\Telegram\BotMenuService::menuLabel()}. Хардкод
     * метки в копии = отложенная ложь при следующей перекладке карточки.
     */
    public const HUB_PROGRESS_LABEL    = '📊 Прогресс';
    public const HUB_DEVELOPMENT_LABEL = '⚙️ Развитие';

    /**
     * Кнопки хаба «📊 Прогресс» (включённые read-only виды).
     *
     * $level (если передан) резолвит L50-lock музея (E19): ≥ min → «🏛 Музей», иначе lock-кнопка.
     * null (проверка видимости хаба из CharacterService) → lock-кнопка (вход не скрыт, хаб появится).
     *
     * @return list<array{text:string, callback_data:string}>
     */
    public static function progressButtons(?int $level = null): array
    {
        $b = [];
        if ((new AchievementService())->isEnabled()) {
            $b[] = ['text' => '🏅 Достижения', 'callback_data' => 'achievements'];
        }
        if ((new TitleService())->enabled()) {
            $b[] = ['text' => '🎖 Титулы', 'callback_data' => 'titles'];
        }
        // ADR-132 — экран «🔥 Серия выживания» (лестница вех login-стрика; gated milestones_enabled).
        if ((new StreakMilestoneService())->enabled()) {
            $b[] = ['text' => '🔥 Серия выживания', 'callback_data' => 'streakScreen'];
        }
        $collections = new CollectionService();
        if ($collections->isEnabled()) {
            $min = $collections->minLevel();
            if ($level !== null && $level >= $min) {
                $b[] = ['text' => '🏛 Музей', 'callback_data' => 'museum'];
            } else {
                $b[] = ['text' => "🔒 Музей (с lvl {$min})", 'callback_data' => 'museumLocked'];
            }
        }
        // Топ игроков (2026-07-10) — общий рейтинг по уровню. Стоит ПЕРЕД «Рейтинг PvP»:
        // спрашивали именно про него («тут есть топ игроков?»), а ладдер — это про дуэли.
        if ((new \App\Services\Social\LeaderboardService())->enabled()) {
            $b[] = ['text' => '🏆 Топ игроков', 'callback_data' => 'leaderboard'];
        }
        if ((new \App\Services\PVE\PvpLadderService())->enabled()) {
            $b[] = ['text' => '🏆 Рейтинг PvP', 'callback_data' => 'pvpLadder'];
        }
        if ((new \App\Services\Economy\PlayerEconomyService())->enabled()) {
            $b[] = ['text' => '💰 Моя экономика', 'callback_data' => 'myEconomy'];
        }
        if ((new WhatsNewService())->isEnabled()) {
            $b[] = ['text' => '📚 Что нового', 'callback_data' => 'whatsNewCatalog'];
        }
        return $b;
    }

    /**
     * Кнопки хаба «⚙️ Развитие» (включённые прогресс-фичи; lock-состояния сохранены).
     *
     * @return list<array{text:string, callback_data:string}>
     */
    public static function developmentButtons(int $characterId, int $level): array
    {
        $b = [];
        if ((new SpecializationService())->isEnabled()) {
            $b[] = ['text' => '🎓 Специализация', 'callback_data' => 'specialization'];
        }
        if ((new FactionProjectService())->enabled()) {
            if (self::hasChosenFaction($characterId)) {
                $b[] = ['text' => '🤝 Проект фракции', 'callback_data' => 'factionProject'];
            } else {
                $lockHint = $level >= 10 ? 'выбери фракцию' : 'с lvl 10';
                $b[] = ['text' => "🔒 Проект фракции ({$lockHint})", 'callback_data' => 'factionProjectLocked'];
            }
        }
        if ((new DroneService())->combatIsEnabled()) {
            $b[] = ['text' => '🛡 Боевой дрон', 'callback_data' => 'combatDroneList'];
        }
        if ((new \App\Services\Craft\ItemModifierService())->enabled()) {
            $b[] = ['text' => '🔧 Модернизация', 'callback_data' => 'enchant'];
        }
        return $b;
    }

    private static function hasChosenFaction(int $characterId): bool
    {
        $row = (new CharacterFactionModel())->where('character_id', $characterId)->first();
        if (! is_array($row)) {
            return false;
        }
        $fidRaw = $row['faction_id'] ?? 0;
        $fid    = is_numeric($fidRaw) ? (int) $fidRaw : 0;
        return $fid !== 5 && ! empty($row['joined_at']);
    }
}
