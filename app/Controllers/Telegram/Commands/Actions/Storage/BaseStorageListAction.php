<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Storage;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Services\Player\InventorySortService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W3b (ADR-060) — retrieve UI для base_storage (закрывает Q5 ADR-059).
 * Callback `baseStorageList` (без аргументов). Показывает список ресурсов
 * в base_storage чара + комбо-кнопка «🎒 Забрать всё».
 *
 * Доступ: с любой клетки можно посмотреть содержимое склада, но забрать
 * можно только когда персонаж стоит на claimed-клетке своей базы (склад
 * физически на базе). Если игрок не на базе — кнопка «Забрать» дисэйблится,
 * показывается hint «вернись на базу».
 *
 * Если callback пришёл с `_all` суффиксом (`baseStorageList_all`) — выполняем
 * atomic retrieve всех ресурсов: для каждого row в base_storage → increase
 * character_resources, delete row из base_storage.
 *
 * Media-off safe (caption самодостаточен).
 */
class BaseStorageListAction extends BaseAction
{
    private BaseStorageModel $storageModel;
    /** @var CharacterResourceModel */
    protected $resourceModel;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->storageModel  = new BaseStorageModel();
        $this->resourceModel = new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        $callbackData = (string) $this->callbackQuery->getData();

        if ($callbackData === 'baseStorageList_all') {
            return $this->retrieveAll($chatId, $characterId);
        }

        // W8: режим сортировки из `baseStorageList_sort_<mode>` (stateless). Default — recent.
        $rawMode = str_starts_with($callbackData, 'baseStorageList_sort_')
            ? substr($callbackData, strlen('baseStorageList_sort_'))
            : null;
        $mode = InventorySortService::normalizeMode($rawMode, InventorySortService::STORAGE_MODES, InventorySortService::MODE_RECENT);

        return $this->renderList($chatId, $characterId, $mode);
    }

    private function renderList(int $chatId, int $characterId, string $mode = InventorySortService::MODE_RECENT): ServerResponse
    {
        $entries = InventorySortService::sortRows($this->loadEnrichedEntries($characterId), $mode);
        if (empty($entries)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "📦 *Склад базы пуст*\n\nКарго-дрон может доставить сюда ресурсы — отправь его с любой клетки.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'],
                    ['text' => '🏠 База',       'callback_data' => 'Base'],
                ]]]),
            ]);
        }

        $onBase = $this->isOnBase($characterId);

        $text   = "📦 *Склад базы*\n\n";
        $totalUnits = 0;
        foreach ($entries as $e) {
            $rawName = $e['name'] ?? '';
            $name    = is_string($rawName) ? $rawName : '';
            $qty     = is_numeric($e['quantity'] ?? null) ? (int) $e['quantity'] : 0;
            if ($name === '' || $qty <= 0) {
                continue;
            }
            $totalUnits += $qty;
            $emoji  = ResourceIconHelper::for($name);
            $text  .= "{$emoji} {$name} — *{$qty}* шт.\n";
        }
        $text .= "\nИтого: *{$totalUnits}* шт.\n\n";

        $rows = [$this->sortRow($mode)]; // W8: переключатель сортировки

        if ($onBase) {
            $text .= "Ты на базе — можно забрать всё в инвентарь.";
            $rows[] = [['text' => '🎒 Забрать всё', 'callback_data' => 'baseStorageList_all']];
            $rows[] = [['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'], ['text' => '🏠 База', 'callback_data' => 'Base']];
        } else {
            $text .= "_Склад физически на базе. Вернись на свою клейм-клетку, чтобы забрать._";
            $rows[] = [['text' => '🗺 Карта', 'callback_data' => 'inlineMap']];
        }
        $keyboard = ['inline_keyboard' => $rows];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function retrieveAll(int $chatId, int $characterId): ServerResponse
    {
        if (! $this->isOnBase($characterId)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚫 Чтобы забрать со склада, нужно быть на своей клейм-клетке. Вернись на базу.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $entries = $this->storageModel->findByCharacter($characterId);
        if (empty($entries)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "📦 Склад уже пуст — забирать нечего.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $db = \Config\Database::connect();
        $db->transStart();

        $totalUnits = 0;
        foreach ($entries as $e) {
            $resId = is_numeric($e['resource_id'] ?? null) ? (int) $e['resource_id'] : 0;
            $qty   = is_numeric($e['quantity'] ?? null) ? (int) $e['quantity'] : 0;
            $id    = is_numeric($e['id'] ?? null) ? (int) $e['id'] : 0;
            if ($resId <= 0 || $qty <= 0 || $id <= 0) {
                continue;
            }
            $this->resourceModel->increaseResources($characterId, $resId, $qty);
            $this->storageModel->delete($id);
            $totalUnits += $qty;
        }

        $db->transComplete();

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "🎒 *Забрано со склада: {$totalUnits} шт.*\n\nВсе ресурсы перенесены в инвентарь.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '🏠 База',     'callback_data' => 'Base'],
            ]]]),
        ]);
    }

    /**
     * Enrich entries из base_storage join'ом resources (name + weight для UI).
     *
     * @return list<array<string,mixed>>
     */
    private function loadEnrichedEntries(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT bs.id, bs.resource_id, bs.quantity, bs.arrived_from_cell, r.name
             FROM base_storage bs
             INNER JOIN resources r ON r.id = bs.resource_id
             WHERE bs.character_id = ?
             ORDER BY bs.updated_at DESC',
            [$characterId]
        );
        if (! is_object($q) || ! method_exists($q, 'getResultArray')) {
            return [];
        }
        $rows = $q->getResultArray();
        $out  = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * W8: ряд кнопок переключения сортировки склада. Текущий режим помечен «•».
     *
     * @return list<array{text:string,callback_data:string}>
     */
    private function sortRow(string $mode): array
    {
        $row = [];
        foreach ([
            InventorySortService::MODE_RECENT => '🕒 Недавние',
            InventorySortService::MODE_NAME   => '🔤 Название',
            InventorySortService::MODE_QTY    => '🔢 Кол-во',
        ] as $m => $label) {
            $row[] = [
                'text'          => ($m === $mode ? '• ' : '') . $label,
                'callback_data' => "baseStorageList_sort_{$m}",
            ];
        }
        return $row;
    }

    private function isOnBase(int $characterId): bool
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT c.cell_number AS char_cell, cc.cell_number AS base_cell
             FROM characters c
             LEFT JOIN claimed_cells cc ON cc.character_id = c.id
             WHERE c.id = ?',
            [$characterId]
        );
        $row = is_object($q) && method_exists($q, 'getRowArray') ? $q->getRowArray() : null;
        if (! is_array($row)) {
            return false;
        }
        $charCell = is_numeric($row['char_cell'] ?? null) ? (int) $row['char_cell'] : 0;
        $baseCell = is_numeric($row['base_cell'] ?? null) ? (int) $row['base_cell'] : 0;
        return $charCell > 0 && $charCell === $baseCell;
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
