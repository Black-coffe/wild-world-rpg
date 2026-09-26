<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterGameTipModel;
use App\Models\GameTipsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\BotMenuService;

/**
 * ADR-038 Фаза C — общая логика выдачи советов «/tips».
 *
 * Извлечена из {@see \App\Controllers\Telegram\Commands\TipsCommand}, чтобы и ручная
 * команда `/tips`, и ежедневная авто-рассылка {@see \App\TaskHandlers\Tips\DailyTipBroadcastHandler}
 * использовали ИДЕНТИЧНУЮ логику выбора: дедуп по 15-дневному окну (`character_game_tips`)
 * и лог просмотра.
 *
 * Микро-награда — только за ручной `/tips` (d1-relevel-l1-l2): авто-рассылка зовёт
 * `serveTip(..., false)`, иначе она качала до L2 тех, кто не играет. Размер — GameSettings
 * `tips.reward.experience|agility|intellect`; константы `REWARD_*` — лишь fallback по умолчанию.
 */
final class TipService
{
    /** Окно дедупа: совет не повторяется чаще раза в N дней. */
    public const DEDUP_DAYS = 15;

    /** Fallback-значения награды, если строки GameSettings нет (seed: D1TipRewardGameSettingsAndTip22). */
    public const REWARD_EXPERIENCE = 0.01;
    public const REWARD_AGILITY    = 0.02;
    public const REWARD_INTELLECT  = 0.04;

    private GameTipsModel $tips;
    private CharacterGameTipModel $views;
    private GameSettingsService $settings;

    public function __construct(
        ?GameTipsModel $tips = null,
        ?CharacterGameTipModel $views = null,
        ?GameSettingsService $settings = null
    ) {
        $this->tips     = $tips ?? new GameTipsModel();
        $this->views    = $views ?? new CharacterGameTipModel();
        $this->settings = $settings ?? new GameSettingsService();
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
     * Зафиксировать показ совета и, если $reward, выдать микро-награду персонажу.
     * Дедуп общий для /tips и авто-рассылки (нельзя нафармить); награда — только за /tips.
     */
    public function recordView(int $characterId, int $tipId, bool $reward = true): void
    {
        $this->views->insert([
            'character_id' => $characterId,
            'game_tip_id'  => $tipId,
            'viewed_at'    => date('Y-m-d H:i:s'),
        ]);

        if (! $reward) {
            return;
        }

        // Награда за просмотр — атомарный relative-UPDATE от свежих значений
        // под row-lock'ом (CharacterStatsService, fix lost-update 2026-07-13).
        (new CharacterStatsService())->adjust($characterId, [
            'experience' => $this->rewardSetting('tips.reward.experience', self::REWARD_EXPERIENCE),
            'agility'    => $this->rewardSetting('tips.reward.agility', self::REWARD_AGILITY),
            'intellect'  => $this->rewardSetting('tips.reward.intellect', self::REWARD_INTELLECT),
        ]);
    }

    private function rewardSetting(string $key, float $default): float
    {
        $raw = $this->settings->get($key, $default);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    /**
     * Выбрать совет + сразу записать показ; при $reward — наградить. null — пул исчерпан.
     * `/tips` зовёт с наградой, ежедневная рассылка — с $reward=false.
     *
     * @return array<string,mixed>|null
     */
    public function serveTip(int $characterId, bool $reward = true): ?array
    {
        $tip = $this->pickForCharacter($characterId);
        if ($tip === null) {
            return null;
        }
        $tipId = isset($tip['id']) && is_numeric($tip['id']) ? (int) $tip['id'] : 0;
        if ($tipId > 0) {
            $this->recordView($characterId, $tipId, $reward);
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
        $content = self::applyMenuLabels($content);

        return "🤖 Это я – *Роби* и мой тебе игровой совет #: _{$id}_\n\n"
            . "🔈 *Название:* {$titleRu}\n\n"
            . "➡️ *Направление:* {$tipType}\n\n"
            . "📂 *Описание:* {$content}";
    }

    /**
     * Подставить ЖИВЫЕ метки кнопок нижнего меню вместо плейсхолдеров `{{menu:<группа>}}`.
     *
     * Зачем: советы лежат в БД строками, а каркас меню меняется killswitch'ами (ADR-150).
     * Захардкоженная в тексте метка молча превращается в ложь: после включения final_grid
     * четыре живых совета звали ««Перс» → «⚔️ Экип»» и «на экране «Карта»», хотя кнопки
     * с такими названиями в меню уже не было. Плейсхолдер решает это раз и навсегда —
     * текст совета переживает любую смену каркаса.
     *
     * Подставляются ТОЛЬКО известные группы; чужой плейсхолдер остаётся как есть и ловится
     * тестом (лучше заметная скобка в проде-тесте, чем тихо пустое место в тексте игроку).
     */
    public static function applyMenuLabels(string $text): string
    {
        $replaced = preg_replace_callback(
            '/\{\{menu:(world|me|base|craft|tasks|more)\}\}/',
            static fn (array $m): string => BotMenuService::menuLabel($m[1]),
            $text
        );

        return is_string($replaced) ? $replaced : $text;
    }
}
