<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Craft\CraftDurationBreakdown;
use App\Services\Craft\CraftOrderService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\ResourcePoolService;
use App\Services\Tasks\ActionScopeService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * F3.B5 (v0.21.0) — generic action-start для любого крафта.
 *
 * Заменяет копипастные `*CraftActionStart.php` (~250 LOC каждый).
 * Поведение определяется рецептом из `app/Config/CraftRecipes.php`.
 *
 * Callback-формат: `genericCraft_<RecipeKey>_<qty>`
 *   - `genericCraft_Bandage_5` → recipe='Bandage', qty=5
 *   - `genericCraft_Antiseptic`  → recipe='Antiseptic', qty=1 (qty опционален)
 *
 * W2.N3-01 (ADR-190): логика старта — гейты, пул рюкзак+склад, атомарное списание, длительность,
 * строка `character_tasks` `in_work|queued` — живёт в `App\Services\Craft\CraftOrderService`
 * (его же зовёт веб). Этот handler только разбирает callback, находит персонажа и рендерит исход
 * прежними текстами, фото и кнопками (старт, очередь, нехватка, отказы гейтов).
 *
 * Контракт `task_settings.recipe` ключевой — `GenericCraftCompletionHandler`
 * читает именно его (см. v0.16.1 fix). Если контракт нарушится — handler
 * залогирует error, task завершится без выдачи предмета. Поэтому action-side
 * и handler-side мигрируем синхронно в одном батче.
 *
 * v0.51.129 (community idea #1) — очередь крафта: повторный рецепт встаёт в очередь (queued), лимиты
 * рецепта и слотов — GameSettings `craft.queue.max_per_recipe` / `craft.queue.max_slots` (W2.N3-01,
 * раньше `Config\GameBalance`). Сырьё списывается сразу в обоих путях.
 */
class GenericCraftActionStart extends BaseAction
{
    // ADR-171: единая точка правды рюкзак+склад. Поле живёт здесь, чтобы ядро
    // ({@see core()}) получало тот же пул, что подменяют тесты. `$resourceModel` — из `BaseAction`.
    private ResourcePoolService $resourcePool;

    private string $recipeKey = '';
    private int    $quantity  = 1;
    private bool   $confirmed = false;

    /** Суффикс колбэка подтверждённой партии: `genericCraft_<Key>_<qty>_ok`. */
    public const CONFIRM_SUFFIX = 'ok';

    /** Куда «↩️ Изменить кол-во», если у рецепта нет `info_callback`: выбор верстака. */
    private const CONFIRM_BACK_FALLBACK = 'WorkbenchChoice';
    private GameSettingsService $gameSettings;
    private ActionScopeService $scope;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->resourcePool = new ResourcePoolService();
        $this->gameSettings = new GameSettingsService();
        $this->scope        = new ActionScopeService();

        [$this->recipeKey, $this->quantity, $this->confirmed] = self::parseCallback((string) $callbackQuery->getData());
    }

    /**
     * `genericCraft_<RecipeKey>[_<qty>[_ok]]` → [ключ, количество ≥ 1, подтверждено].
     * `_ok` ставит только экран итога крупной партии (craft-batch-price-confirm).
     *
     * @return array{0:string, 1:int, 2:bool}
     */
    public static function parseCallback(string $data): array
    {
        $parts = explode('_', $data);
        $qty   = isset($parts[2]) && is_numeric($parts[2]) ? max(1, (int) $parts[2]) : 1;

        return [$parts[1] ?? '', $qty, ($parts[3] ?? '') === self::CONFIRM_SUFFIX];
    }

    /**
     * W2.N3-01 (ADR-190): гейты, списание и строка задачи — в {@see CraftOrderService::start()};
     * здесь только рендер исхода прежними текстами, фото и кнопками.
     */
    public function handle(): ServerResponse
    {
        if ($this->recipeKey === '') {
            return $this->sendError('Не указан тип крафта.');
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($this->recipeKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестный рецепт: {$this->recipeKey}");
        }

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден.');
        }
        $characterId = $this->characterIntField($character, 'id');

        $result = $this->core()->start($characterId, $this->recipeKey, $this->quantity, $this->confirmed);
        if ($result['code'] === CraftOrderService::CONFIRM_REQUIRED && $result['batch'] !== null) {
            return $this->notifyConfirmBatch($recipe, $result['batch']);
        }
        if (!$result['ok']) {
            if ($result['log'] !== null) {
                $this->logRejected($characterId, "CRAFT_{$this->recipeKey}", $result['log']['reason'], $result['log']['extra']);
            }

            // ADR-158: экран «чего не хватает» — где добывается и что докупить.
            if ($result['code'] === CraftOrderService::MISSING_MATERIALS) {
                $shortage = new \App\Services\Craft\CraftShortageService();
                if ($shortage->isEnabled()) {
                    $screen = $shortage->describe($character, $result['missing_resources'], $result['missing_items'], $this->quantity, $recipe);
                    Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

                    return Request::sendMessage([
                        'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                        'text'         => $screen['text'],
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode($screen['keyboard']),
                    ]);
                }
            }

            return $this->sendError($result['message']);
        }

        if ($result['code'] === CraftOrderService::QUEUED) {
            return $this->notifyCraftQueued($recipe, $result['queue_pos'], $this->quantity, $result['char_task_id'], $result['background'], self::spentLine($result['consumed']));
        }

        return $this->notifyCraftStarted($recipe, $result['minutes_total'], $this->quantity, $result['background'], $result['breakdown'], self::spentLine($result['consumed']));
    }

    /**
     * Story `craft-shortfall-buy-09` — «может ли крафт вообще стартовать» (все гейты КРОМЕ
     * сырья). Делегирует {@see CraftOrderService::gateError()}; зовёт `CraftShortfallBuyAction`
     * ДО покупки. Отказ пишется в action_log здесь — ядру chat_id не известен.
     *
     * @param array<string,mixed> $recipe
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @param array<string,mixed> $taskRow
     * @return string|null текст отказа (Markdown) либо `null` — крафт может начаться
     */
    public function checkCanStartWithoutMaterials(string $recipeKey, array $recipe, array|\App\Entities\CharacterEntity $character, array $taskRow, int $quantity): ?string
    {
        $gate = $this->core()->gateError($recipeKey, $recipe, $character, $taskRow, $quantity);
        if ($gate === null) {
            return null;
        }
        if ($gate['log'] !== null) {
            $this->logRejected($this->characterIntField($character, 'id'), "CRAFT_{$recipeKey}", $gate['log']['reason'], $gate['log']['extra']);
        }

        return $gate['message'];
    }

    private function core(): CraftOrderService
    {
        return new CraftOrderService($this->resourcePool, $this->resourceModel);
    }

    /** @param array<array-key,mixed>|\App\Entities\CharacterEntity $character */
    private function characterIntField(array|\App\Entities\CharacterEntity $character, string $key): int
    {
        $raw = $character[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Тонкая обёртка над ядром (её зовёт `CraftPoolConsumptionTest`).
     *
     * @param array<string,int> $reqs
     * @return array<string,array{need:int,have:int,name:string,storage:int,pooled:bool}>
     */
    protected function checkResources(int $charId, array $reqs, int $qty): array
    {
        return $this->core()->checkResources($charId, $reqs, $qty);
    }

    /**
     * Тонкая обёртка над ядром (её зовёт `CraftPoolConsumptionTest`); гонку не глушит.
     *
     * @param array<string,int> $reqs
     * @throws \RuntimeException
     */
    protected function subtractResources(int $charId, array $reqs, int $qty): void
    {
        $this->core()->subtractResources($charId, $reqs, $qty);
    }

    /** Тонкая обёртка над ядром (её зовёт `VehicleRecipesTest` на экземпляре без конструктора — пул не нужен). */
    protected function characterFactionId(int $charId): int
    {
        return (new CraftOrderService())->characterFactionId($charId);
    }

    /**
     * Строка «Списано» за всю партию (жалоба 05.10.2026: 50 лопат одним нажатием ушли за 300 000 золота,
     * а сообщение о старте молчало о цене). Числа — из `consumed` ядра, то есть ровно то, что списала
     * транзакция. Имена — данные БД и конфига: markdown-метасимволы вырезаются, иначе непарная `*`/`_`
     * роняет весь caption (legacy Markdown без эскейпа). Пустое списание — пустая строка.
     *
     * @param array{gold:int, resources:array<string,int>, crafted_items:array<string,int>} $consumed
     */
    public static function spentLine(array $consumed): string
    {
        $parts = [];
        if ($consumed['gold'] > 0) {
            $parts[] = number_format($consumed['gold'], 0, '.', ' ') . ' 💰';
        }
        foreach ([$consumed['resources'], $consumed['crafted_items']] as $group) {
            foreach ($group as $name => $n) {
                $clean = trim(str_replace(['*', '_', '`', '[', ']'], '', (string) $name));
                if ($n > 0 && $clean !== '') {
                    $parts[] = $clean . ' ×' . number_format($n, 0, '.', ' ');
                }
            }
        }

        return $parts === [] ? '' : '💸 *Списано:* ' . implode(' · ', $parts);
    }

    /**
     * craft-batch-price-confirm: текст экрана подтверждения крупной партии. Чистая функция — итог
     * приходит из ядра (`batch` ответа `CONFIRM_REQUIRED`), здесь ни одного пересчёта.
     *
     * @param array{qty:int, gold:int, resources:array<string,int>, crafted_items:array<string,int>, minutes_total:int} $batch
     */
    public static function confirmText(string $captionName, array $batch): string
    {
        $list = static function (array $group): string {
            $parts = [];
            foreach ($group as $name => $n) {
                $clean = trim(str_replace(['*', '_', '`', '[', ']'], '', (string) $name));
                if ($n > 0 && $clean !== '') {
                    $parts[] = $clean . ' ×' . number_format($n, 0, '.', ' ');
                }
            }

            return implode(' · ', $parts);
        };

        $lines = [
            '⚠️ *Крупная партия — проверь перед запуском*',
            '',
            "Ты создаёшь: {$captionName} x{$batch['qty']} шт.",
            '',
        ];
        if ($batch['gold'] > 0) {
            $lines[] = '💰 Золото: *' . number_format($batch['gold'], 0, '.', ' ') . '*';
        }
        if (($res = $list($batch['resources'])) !== '') {
            $lines[] = '📦 Ресурсы: ' . $res;
        }
        if (($items = $list($batch['crafted_items'])) !== '') {
            $lines[] = '🛠 Компоненты: ' . $items;
        }
        $lines[] = '⏱ Время: *' . CraftDurationBreakdown::humanize($batch['minutes_total']) . '*';
        $lines[] = '';
        $lines[] = '_Золото и ресурсы спишутся сразу после запуска._';

        return implode("\n", $lines);
    }

    /**
     * Кнопки экрана подтверждения — один ряд из двух: запуск ровно этой партии и возврат к рецепту.
     *
     * @param array<array-key,mixed> $recipe
     * @return list<list<array{text:string,callback_data:string}>>
     */
    public static function confirmKeyboard(string $recipeKey, int $qty, array $recipe): array
    {
        $back = isset($recipe['info_callback']) && is_string($recipe['info_callback']) && $recipe['info_callback'] !== ''
            ? $recipe['info_callback']
            : self::CONFIRM_BACK_FALLBACK;

        return [[
            ['text' => '✅ Запустить', 'callback_data' => 'genericCraft_' . $recipeKey . '_' . $qty . '_' . self::CONFIRM_SUFFIX],
            ['text' => '↩️ Изменить кол-во', 'callback_data' => $back],
        ]];
    }

    /**
     * @param array<array-key,mixed> $recipe
     * @param array{qty:int, gold:int, resources:array<string,int>, crafted_items:array<string,int>, minutes_total:int} $batch
     */
    private function notifyConfirmBatch(array $recipe, array $batch): ServerResponse
    {
        $image   = is_string($recipe['image_in_progress'] ?? null) ? $recipe['image_in_progress'] : '';
        $caption = is_string($recipe['start_caption_name'] ?? null) ? $recipe['start_caption_name'] : $this->recipeKey;

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile(base_url($image)),
            'caption'      => self::confirmText($caption, $batch),
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => self::confirmKeyboard($this->recipeKey, $batch['qty'], $recipe)]),
        ]);
    }

    /**
     * v0.51.129: notification для queued task. Показує queue position + qty +
     * cancel button з callback `cancelQueued_<task_id>` для refund.
     */
    private function notifyCraftQueued(array $recipe, int $queuePosition, int $qty, int $charTaskId, bool $background, string $spent): ServerResponse
    {
        $text = "*В очередь поставлено:* {$recipe['start_caption_name']} x{$qty} шт.\n\n"
            . $this->scope->scopeLine(ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "📋 Позиция в очереди: *#{$queuePosition}*\n"
            . "Начнётся автоматически после завершения активного крафта.\n\n"
            . ($spent !== '' ? $spent . "\n\n" : '')
            . "❗Ресурсы уже списаны. Отмена очереди вернёт их.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ Отменить из очереди', 'callback_data' => "cancelQueued_{$charTaskId}"]],
                [['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue']],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function notifyCraftStarted(array $recipe, int $minutes, int $qty, bool $background, ?CraftDurationBreakdown $breakdown, string $spent): ServerResponse
    {
        $timeStr = $this->formatMinutes($minutes);

        // ADR-158 «строка правды»: свободный стек множителей достигает ×0.22, но был
        // полностью невидим — игрок с −78% видел только итоговое число, читал крафт
        // как медленный и просил ускорение, которое у него уже есть. Показываем и
        // базу, и за счёт чего быстрее. Без бонусов строка не отличается от прежней.
        $timeBlock = "Время крафта: *{$timeStr}* ⏱️";
        if ($breakdown !== null
            && $breakdown->hasBonuses()
            && (bool) $this->gameSettings->get('craft.duration_breakdown.enabled', true)
        ) {
            $timeBlock = $breakdown->truthLine($qty);
        }

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: {$recipe['start_caption_name']} x{$qty} шт.\n\n"
            . $this->scope->startedBlock(ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . $timeBlock . "\n\n"
            . ($spent !== '' ? $spent . "\n\n" : '')
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function formatMinutes(int $totalMinutes): string
    {
        if ($totalMinutes <= 0) {
            return '0 минут';
        }
        $days  = intdiv($totalMinutes, 1440);
        $rem   = $totalMinutes % 1440;
        $hours = intdiv($rem, 60);
        $mins  = $rem % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} " . $this->pluralForm($days, ['день', 'дня', 'дней']);
        }
        if ($hours > 0) {
            $parts[] = "{$hours} " . $this->pluralForm($hours, ['час', 'часа', 'часов']);
        }
        if ($mins > 0) {
            $parts[] = "{$mins} " . $this->pluralForm($mins, ['минута', 'минуты', 'минут']);
        }
        return empty($parts) ? '0 минут' : implode(' ', $parts);
    }

    private function pluralForm(int $n, array $forms): string
    {
        $nMod10  = $n % 10;
        $nMod100 = $n % 100;
        if ($nMod100 >= 11 && $nMod100 <= 14) {
            return $forms[2];
        }
        return match ($nMod10) {
            1       => $forms[0],
            2, 3, 4 => $forms[1],
            default => $forms[2],
        };
    }

    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
