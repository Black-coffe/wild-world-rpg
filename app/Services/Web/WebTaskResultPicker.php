<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Events\EventMessageFormatter;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\OnboardingHintCatalog;
use App\Services\Player\TipService;
use Config\WebPlay;

/**
 * web-onboarding-02 — какое из входящих сообщений есть итог законченной задачи экрана `/play`.
 *
 * Метки «итог ↔ задача» от крона нет (общий `Worker` не трогаем), поэтому итог выбирается по времени
 * и по типу: сначала отбрасываются сообщения чужих типов — фоновые отправки, которые новичок получает
 * в ту же минуту крона, — затем из оставшихся в окне `[updated_at задачи, + taskResultWindowSeconds]`
 * берётся первое по id. Без БД: строки входящих приходят снаружи.
 *
 * Сигнатура чужого типа выводится из построителя отправителя (каталог, форматтер, рендер). Где
 * построитель — внутри `app/TaskHandlers/**` (его не трогаем), сигнатура — константа
 * {@see FOREIGN_TEXT_PREFIXES}, сверяемая с исходником отправителя тестом
 * (`WebTaskResultPickerTest`): поменяли текст отправителя — тест красный.
 *
 * @phpstan-import-type Msg from WebScreenStore
 * @phpstan-type InboxRow array{id:int, created_at:string, msg:Msg}
 */
class WebTaskResultPicker
{
    /**
     * Начала текстов чужих фоновых сообщений, чьи построители живут в `app/TaskHandlers/**`.
     * Ключ — тип, значение — начала текста/caption (как отправлены, с Markdown-разметкой).
     */
    public const FOREIGN_TEXT_PREFIXES = [
        // TitleCheckCron::notify(): "🎖 *{$plural}!*", $plural = 'Новые титулы' | 'Новый титул'.
        'title' => ['🎖 *Новый титул!*', '🎖 *Новые титулы!*'],
        // AchievementCheckCron: "🏅 *{$plural}!*", $plural = 'Новые достижения' | 'Новое достижение'.
        'achievement' => ['🏅 *Новое достижение!*', '🏅 *Новые достижения!*'],
        // DailyTaskProgressHandler::sendNotice().
        'daily_task' => ['✅ *Задание дня выполнено!*', '🎉 *Все задания дня выполнены!*'],
        // StreakMilestoneCron: $header.
        'streak' => ['🔥 *Новые вехи серии!*', '🔥 *Веха серии достигнута!*'],
        // EventActivationHandler::legacyBroadcast() (событие без конфига WorldEvents).
        'event_legacy' => ['⚠️ *Внимание, началось событие:'],
        // SeasonalTransitionHandler (рассылка смены сезона).
        'season' => ['🗓 *Сменился сезон:'],
        // PvpLadderWeeklyBroadcastHandler::renderBroadcast() (еженедельная рассылка).
        'pvp_ladder' => ['🏆 *Итоги недели — Рейтинг PvP*'],
        // NodeAnnounceDigestHandler (сводка радиста).
        'node_digest' => ['📻 *Сводка с пустоши*'],
        // FoodAndWaterConsumptionHandler::sendMessageToTelegram() (приём пищи по крону).
        'meal' => ['👤 *Персонаж отлично покушал!*'],
        // LowHealthWarningHandler (предупреждение о здоровье, фото + caption).
        'low_health' => ['Мой дорогой выживальщик *'],
    ];

    private WebPlay $config;

    /**
     * Сигнатуры из построителей, один раз на экземпляр (каталоги читают метки меню из GameSettings).
     *
     * @var array{chain: list<string>, hints: list<string>, tip: string, event: list<string>}|null
     */
    private ?array $signatures = null;

    public function __construct(?WebPlay $config = null)
    {
        $this->config = $config ?? new WebPlay();
    }

    /**
     * Итог задачи среди входящих или null.
     *
     * @param array<string, mixed> $task строка `character_tasks` (нужен `updated_at`)
     * @param list<InboxRow>       $inboxRows
     *
     * @return Msg|null
     */
    public function pick(array $task, array $inboxRows): ?array
    {
        $updated = is_string($task['updated_at'] ?? null) ? strtotime($task['updated_at']) : false;
        if ($updated === false) {
            return null;
        }
        $until = $updated + max(0, $this->config->taskResultWindowSeconds);

        $candidates = [];
        foreach ($inboxRows as $row) {
            $at = strtotime($row['created_at']);
            if ($at === false || $at < $updated || $at > $until) {
                continue;
            }
            if ($this->foreignType($row['msg']) !== null) {
                continue;
            }
            $candidates[] = $row;
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $candidates[0]['msg'];
    }

    /**
     * Тип чужого фонового сообщения или null — не чужое.
     *
     * @param Msg $msg
     */
    public function foreignType(array $msg): ?string
    {
        $text      = $msg['text'] ?? $msg['caption'] ?? '';
        $callbacks = self::callbacks($msg['inline_keyboard']);
        $sig       = $this->signatures ??= [
            'chain' => self::chainCallbacks(),
            'hints' => self::hintHeads(),
            'tip'   => self::tipPrefix(),
            'event' => self::eventMuteCallbacks(),
        ];

        if ($callbacks !== [] && $callbacks === $sig['chain']) {
            return 'chain_step';
        }
        if ($text !== '' && in_array(self::firstLine($text), $sig['hints'], true)) {
            return 'hint';
        }
        if ($text !== '' && str_starts_with($text, $sig['tip'])) {
            return 'daily_tip';
        }
        if (array_intersect($callbacks, $sig['event']) !== []) {
            return 'world_event';
        }
        foreach (self::FOREIGN_TEXT_PREFIXES as $type => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($text !== '' && str_starts_with($text, $prefix)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Клавиатура шага обучающей цепочки — из {@see OnboardingChainCatalog::completion()} (у всех
     * шагов одна).
     *
     * @return list<string>
     */
    private static function chainCallbacks(): array
    {
        $completion = OnboardingChainCatalog::completion(OnboardingChainCatalog::ROOT);
        $markup     = $completion === null ? null : json_decode($completion['reply_markup'], true);
        $rows       = is_array($markup) && is_array($markup['inline_keyboard'] ?? null) ? $markup['inline_keyboard'] : [];

        return self::callbacks($rows);
    }

    /**
     * Заголовки (первые строки) подсказок {@see OnboardingHintCatalog}.
     *
     * @return list<string>
     */
    private static function hintHeads(): array
    {
        return array_values(array_map(
            static fn (array $hint): string => self::firstLine($hint['text']),
            OnboardingHintCatalog::all()
        ));
    }

    /** Начало «Совета дня» — из {@see TipService::renderTip()} до номера совета. */
    private static function tipPrefix(): string
    {
        $head = self::firstLine(TipService::renderTip([]));
        $cut  = strpos($head, '#:');

        return $cut === false ? $head : substr($head, 0, $cut + 2);
    }

    /**
     * Кнопки «приглушить» уведомлений мировых событий — нижний ряд
     * {@see EventMessageFormatter::buildEndKeyboard()} (он же стоит и на старте события).
     *
     * @return list<string>
     */
    private static function eventMuteCallbacks(): array
    {
        $rows = (new EventMessageFormatter())->buildEndKeyboard('', [])['inline_keyboard'];
        $last = $rows === [] ? [] : $rows[array_key_last($rows)];

        return self::callbacks([$last]);
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<string>
     */
    private static function callbacks(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? $row : [] as $button) {
                $data = is_array($button) ? ($button['callback_data'] ?? null) : null;
                if (is_string($data)) {
                    $out[] = $data;
                }
            }
        }

        return $out;
    }

    private static function firstLine(string $text): string
    {
        return trim(explode("\n", $text, 2)[0]);
    }
}
