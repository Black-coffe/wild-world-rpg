<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterGameTipModel;
use App\Models\GameTipsModel;

/**
 * ADR-038 Фаза C — общая логика выдачи советов «/tips».
 *
 * Извлечена из {@see \App\Controllers\Telegram\Commands\TipsCommand}, чтобы и ручная
 * команда `/tips`, и ежедневная авто-рассылка {@see \App\TaskHandlers\Tips\DailyTipBroadcastHandler}
 * использовали ИДЕНТИЧНУЮ логику: дедуп по 15-дневному окну (`character_game_tips`),
 * лог просмотра и одинаковую микро-награду.
 *
 * Reward (выровнено с историческим поведением TipsCommand):
 *   опыт +0.01 · ловкость +0.02 · интеллект +0.04 (см. совет #22, ADR-038 Фаза A).
 */
final class TipService
{
    /** Окно дедупа: совет не повторяется чаще раза в N дней. */
    public const DEDUP_DAYS = 15;

    public const REWARD_EXPERIENCE = 0.01;
    public const REWARD_AGILITY    = 0.02;
    public const REWARD_INTELLECT  = 0.04;

    private GameTipsModel $tips;
    private CharacterGameTipModel $views;

    public function __construct(
        ?GameTipsModel $tips = null,
        ?CharacterGameTipModel $views = null
    ) {
        $this->tips  = $tips ?? new GameTipsModel();
        $this->views = $views ?? new CharacterGameTipModel();
    }

    /**
     * Выбрать случайный совет, не виденный персонажем за последние DEDUP_DAYS дней.
     * null — если пул исчерпан (все советы показаны в окне).
     *
     * @return array<string,mixed>|null
     */
    public function pickForCharacter(int $characterId): ?array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::DEDUP_DAYS . ' days'));

        $recent = $this->views
            ->select('game_tip_id')
            ->where('character_id', $characterId)
            ->where('viewed_at >', $cutoff)
            ->findAll();
        $recentIds = array_column($recent, 'game_tip_id');

        $query = $this->tips->orderBy('RAND()');
        if (! empty($recentIds)) {
            $query = $query->whereNotIn('id', $recentIds);
        }
        $tip = $query->first();
        if (! is_array($tip)) {
            return null;
        }

        // Нормализуем ключи к string (GameTipsModel returnType=array → int|string keys).
        $out = [];
        foreach ($tip as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * Зафиксировать показ совета + выдать микро-награду персонажу.
     * Используют и /tips, и авто-рассылка (общий дедуп → нельзя нафармить).
     */
    public function recordView(int $characterId, int $tipId): void
    {
        $this->views->insert([
            'character_id' => $characterId,
            'game_tip_id'  => $tipId,
            'viewed_at'    => date('Y-m-d H:i:s'),
        ]);

        // Награда за просмотр — атомарный relative-UPDATE от свежих значений
        // под row-lock'ом (CharacterStatsService, fix lost-update 2026-07-13).
        (new CharacterStatsService())->adjust($characterId, [
            'experience' => self::REWARD_EXPERIENCE,
            'agility'    => self::REWARD_AGILITY,
            'intellect'  => self::REWARD_INTELLECT,
        ]);
    }

    /**
     * Выбрать совет + сразу записать показ и наградить. null — пул исчерпан.
     *
     * @return array<string,mixed>|null
     */
    public function serveTip(int $characterId): ?array
    {
        $tip = $this->pickForCharacter($characterId);
        if ($tip === null) {
            return null;
        }
        $tipId = isset($tip['id']) && is_numeric($tip['id']) ? (int) $tip['id'] : 0;
        if ($tipId > 0) {
            $this->recordView($characterId, $tipId);
        }
        return $tip;
    }

    /**
     * Единый рендер совета (Markdown). Используют /tips и авто-рассылка → одинаковый вид.
     *
     * @param array<string,mixed> $tip
     */
    public static function renderTip(array $tip): string
    {
        $id      = isset($tip['id']) && is_numeric($tip['id']) ? (int) $tip['id'] : 0;
        $titleRu = isset($tip['title_ru']) && is_string($tip['title_ru']) ? $tip['title_ru'] : '';
        $tipType = isset($tip['tip_type']) && is_string($tip['tip_type']) ? $tip['tip_type'] : '';
        $content = isset($tip['content']) && is_string($tip['content']) ? $tip['content'] : '';

        return "🤖 Это я – *Роби* и мой тебе игровой совет #: _{$id}_\n\n"
            . "🔈 *Название:* {$titleRu}\n\n"
            . "➡️ *Направление:* {$tipType}\n\n"
            . "📂 *Описание:* {$content}";
    }
}
