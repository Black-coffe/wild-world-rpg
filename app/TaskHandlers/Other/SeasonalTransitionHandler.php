<?php

declare(strict_types=1);

namespace App\TaskHandlers\Other;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Services\World\SeasonalCraftService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * S28 (ADR-032) — ежедневная проверка смены сезона + анонс игрокам.
 *
 * Tasks.php daily 00:00. Вычисляет активный сезон (SeasonalCraftService, pure-math),
 * сравнивает с cache `seasonal_last_announced`. При смене (и enabled) — broadcast
 * всем игрокам о новом сезоне. State в cache (не DB): пропуск дня → анонс на след.
 * запуске; потеря кэша → silent baseline (без спам-анонса).
 *
 * **First-run silent:** если cache пуст (первый запуск / после деплоя) — только
 * выставляем baseline БЕЗ broadcast. Первый игровой анонс — на ПЕРВОЙ реальной
 * смене сезона (winter→spring), а не на запуске фичи.
 */
#[HandlerKey(
    key: 'seasonal_transition',
    displayName: 'Смена сезона (S28)',
    description: 'Daily 00:00: проверяет активный craft-сезон, при смене шлёт broadcast игрокам. State в cache, first-run silent.',
)]
final class SeasonalTransitionHandler extends BaseTaskHandler
{
    private const CACHE_KEY = 'seasonal_last_announced';
    private const CACHE_TTL = 172800; // 2 дня

    private SeasonalCraftService $seasonal;

    public function __construct(?SeasonalCraftService $seasonal = null)
    {
        $this->seasonal = $seasonal ?? new SeasonalCraftService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        $current = $this->seasonal->getActiveSeasonKey();
        if ($current === '') {
            // Killswitch off / нет активного сезона — ничего не делаем.
            return;
        }

        $cache = \Config\Services::cache();
        $last  = $cache->get(self::CACHE_KEY);

        if (!is_string($last) || $last === '') {
            // First-run / cache потерян → baseline без broadcast (no spam).
            $cache->save(self::CACHE_KEY, $current, self::CACHE_TTL);
            log_message('info', "[SeasonalTransition] baseline set: {$current} (no broadcast)");
            return;
        }

        if ($last === $current) {
            $cache->save(self::CACHE_KEY, $current, self::CACHE_TTL); // refresh TTL
            return;
        }

        // Реальная смена сезона → анонс.
        $this->broadcastNewSeason($current);
        $cache->save(self::CACHE_KEY, $current, self::CACHE_TTL);
        log_message('info', "[SeasonalTransition] season changed {$last} → {$current}, broadcast sent");
    }

    private function broadcastNewSeason(string $seasonKey): void
    {
        $this->telegram();

        $label    = $this->seasonal->getSeasonLabel($seasonKey);
        $daysLeft = $this->seasonal->daysRemainingInSeason();
        $count    = count($this->seasonal->getRecipeKeysForSeason($seasonKey));

        $msg  = "🗓 *Сменился сезон: {$label}!*\n\n";
        if ($count > 0) {
            $msg .= "Открылись *{$count}* эксклюзивных сезонных рецептов на *{$daysLeft}* дней.\n";
            $msg .= "Загляни в *Общий крафт → 🗓 Сезонный крафт*.\n\n";
        } else {
            $msg .= "Новый сезон вступил в силу.\n\n";
        }
        $msg .= "_Не успеешь скрафтить — рецепты вернутся в следующем году._";

        $query = (new CharacterModel())->builder()
            ->select('telegram_users.telegram_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->get();
        /** @var list<array<string,mixed>> $rows */
        $rows = $query === false ? [] : $query->getResultArray();

        $sent = 0;
        $seen = [];
        foreach ($rows as $row) {
            $tgRaw = $row['telegram_id'] ?? null;
            if (!is_numeric($tgRaw)) {
                continue;
            }
            $tgId = (int) $tgRaw;
            if ($tgId === 0 || isset($seen[$tgId])) {
                continue;
            }
            $seen[$tgId] = true;
            $this->safeSendMessage($tgId, $msg, ['parse_mode' => 'Markdown']);
            $sent++;
        }
        log_message('info', "[SeasonalTransition] broadcast {$seasonKey} → {$sent} игроков");
    }
}
