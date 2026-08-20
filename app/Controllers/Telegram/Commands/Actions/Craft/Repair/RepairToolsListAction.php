<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Repair;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S5b (v0.51.188) — список изношенных инструментов + кнопки «Ремонт».
 *
 * Ремонт ревью-находки (2026-08-20, ADR-174): список расширен на `type='transport'`
 * (машины). До правки `type='tool'` был жёстким фильтром — разбитая/изношенная
 * машина никогда сюда не попадала, хотя экран машины и текст смерти обещают
 * ремонт. Переиспользуем ЭТОТ ЖЕ механизм (не изобретаем второй): каталог
 * `crafted_items.durability_count` у пяти машин синхронизирован с
 * `world.vehicle.<key>.charges_full` миграцией `SyncVehicleCatalogChargesFull`
 * (сосед-стори чинил тот же ревью-пакет) — значит сравнение
 * `crafted_items_log.durability_count < crafted_items.durability_count` и
 * cur/max-подпись корректны для машин без отдельного чтения GameSettings.
 *
 * Entry-point из `CraftedResourcesAction` (Инвентарь → Крафтовые ресурсы →
 * 🔧 Ремонт инструментов) и с экрана «Мой транспорт» (разбитая/изношенная
 * машина ведёт сюда же).
 *
 * Условие «изношенный»: `crafted_items_log.durability_count < crafted_items.durability_count`
 * (текущая прочность/заряд меньше template-default). Полностью сломанные инструменты
 * (`durability=0 AND quantity=1` → row удалён) сюда не попадают — их нужно
 * крафтить заново (см. S4 deletion-model). Машины при durability=0 НЕ удаляются
 * (ADR-174 «разбивается, но не пропадает» — `VehicleActivationService::breakActive()`),
 * поэтому разбитая машина в списке остаётся видимой и доступной для ремонта.
 *
 * Callback: `repairToolsList` (no args).
 */
class RepairToolsListAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel = new CraftedItemsLogModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $charId = $this->extractCharId($character);

        // Все tool- и transport-предметы персонажа с template_durability > 0.
        // Изношенный = durability_count < template.durability_count (для машин —
        // заряд < полного запаса; ревью-находка 2026-08-20).
        $rows = $this->logModel
            ->select('crafted_items_log.id AS log_id, crafted_items_log.durability_count AS cur_dur, crafted_items.name_rus, crafted_items.name_eng, crafted_items.durability_count AS max_dur, crafted_items.type')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $charId)
            ->whereIn('crafted_items.type', ['tool', 'transport'])
            ->where('crafted_items.durability_count >', 0)
            ->where('crafted_items_log.durability_count <', 'crafted_items.durability_count', false)
            ->orderBy('crafted_items.name_rus', 'ASC')
            ->findAll();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (empty($rows)) {
            $text = "🔧 *Ремонт инструментов и транспорта*\n\n"
                . "_У тебя нет изношенных инструментов или машин с неполным зарядом._\n\n"
                . "Все инструменты с полной прочностью либо уже сломались (нужно крафтить заново). "
                . "Машины при полном заряде тоже сюда не попадают.";

            $keyboard = [
                'inline_keyboard' => [[
                    ['text' => '🔨 Крафтовые ресурсы', 'callback_data' => 'resourcesCrafting'],
                    ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
                ]],
            ];

            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        $text = "🔧 *Ремонт инструментов и транспорта*\n\n"
            . "Выбери, что чинить. Текущая / максимальная прочность (у машин — заряд):\n\n";

        $cfg     = config('CraftRecipes');
        $recipes = $cfg instanceof \Config\CraftRecipes ? $cfg : new \Config\CraftRecipes();

        $buttons    = [];
        $noRecipe   = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $logIdRaw = $row['log_id'] ?? null;
            $nameRaw  = $row['name_rus'] ?? null;
            $logId    = is_numeric($logIdRaw) ? (int) $logIdRaw : 0;
            $name     = is_string($nameRaw) ? $nameRaw : '???';
            $cur      = is_numeric($row['cur_dur'] ?? null) ? (int) $row['cur_dur'] : 0;
            $max      = is_numeric($row['max_dur'] ?? null) ? (int) $row['max_dur'] : 0;
            $nameEng  = is_string($row['name_eng'] ?? null) ? $row['name_eng'] : '';
            $isVehicle = ($row['type'] ?? null) === 'transport';
            // Машины — «заряд», не «прочность» (канон транспорта, GameSettings
            // `world.vehicle.*.charges_full`), включая дрон Инженеров, для
            // которого канон говорит именно «заряжается».
            $icon      = $isVehicle ? '🔋' : '🔧';
            $unit      = $isVehicle ? 'заряд' : 'прочность';

            if ($logId <= 0) {
                continue;
            }

            // Стоимость ремонта считается от рецепта. Нет рецепта — оба пути
            // (ресурсы и NPC) физически не могут посчитать цену, поэтому
            // кнопку не рисуем, но и молча не прячем: говорим честно.
            if ($recipes->findByItemNameEng($nameEng) === null) {
                $noRecipe[] = "• *{$name}*: {$cur}/{$max} ({$unit})";
                continue;
            }

            $text .= "• *{$name}*: {$cur}/{$max} ({$unit})\n";
            $buttons[] = [
                ['text' => "{$icon} {$name} ({$cur}/{$max})", 'callback_data' => "repair_{$logId}"],
                ['text' => '🛠 NPC', 'callback_data' => "npc_repair_{$logId}"],
            ];
        }

        if ($noRecipe !== []) {
            if ($buttons === []) {
                $text = "🔧 *Ремонт инструментов и транспорта*\n\n";
            }
            $text .= "\n_Это починить нельзя — нет рецепта, "
                . "стоимость ремонта не из чего посчитать:_\n"
                . implode("\n", $noRecipe) . "\n";
        }

        // V23: 2 кнопки в строке — S5 (ресурсы) и NPC (gold).
        $keyboard = [
            'inline_keyboard' => array_merge(
                $buttons,
                [[
                    ['text' => '🔨 Крафтовые ресурсы', 'callback_data' => 'resourcesCrafting'],
                    ['text' => '🎒 Инвентарь',         'callback_data' => 'inventory'],
                ]]
            ),
        ];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * F1.4 helper: character может быть array или CharacterEntity.
     */
    private function extractCharId(mixed $character): int
    {
        if (is_array($character)) {
            $v = $character['id'] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($character)) {
            $v = $character->id ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }
}
