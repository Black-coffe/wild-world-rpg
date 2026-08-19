<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Storage;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Services\Bases\BaseCheckService;
use App\Services\Onboarding\OnboardingHintService;
use App\Services\Player\InventorySortService;
use App\Services\Telegram\ButtonPacker;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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
 * storage-craft-insurance-03 — зеркало ручной сдачи (`BaseStorageDepositAction`):
 * `baseStorageList_res_<resource_id>_<mode>` забирает ОДИН вид ресурса целиком.
 * `<mode>` — текущий режим сортировки (W8), протащенный сквозь callback_data,
 * чтобы после забора экран вернулся в том же порядке, а не сбросился на recent
 * (сортировка stateless, другого места её хранить нет).
 *
 * Media-off safe (caption самодостаточен).
 */
class BaseStorageListAction extends BaseAction
{
    /** Сколько видов показываем кнопками (зеркало `BaseStorageDepositAction::MAX_BUTTONS`). */
    private const MAX_BUTTONS = 18;

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
            $resp = $this->retrieveAll($chatId, $characterId);
        } elseif (str_starts_with($callbackData, 'baseStorageList_res_')) {
            $resp = $this->retrieveOneFromCallback($chatId, $characterId, $callbackData);
        } else {
            // W8: режим сортировки из `baseStorageList_sort_<mode>` (stateless). Default — recent.
            $rawMode = str_starts_with($callbackData, 'baseStorageList_sort_')
                ? substr($callbackData, strlen('baseStorageList_sort_'))
                : null;
            $mode = InventorySortService::normalizeMode($rawMode, InventorySortService::STORAGE_MODES, InventorySortService::MODE_RECENT);

            $resp = $this->renderList($chatId, $characterId, $mode);
        }

        // Slice 2 («ресурс-грамотность») — one-shot подсказка при первом открытии склада:
        // добыча лежит в рюкзаке, на склад её кладут руками дома или карго-дроном из поля.
        // Гейты (killswitch/opt-out/one-shot) внутри сервиса; шлётся ПОСЛЕ основного экрана.
        (new OnboardingHintService())->maybeSendFirstStorageHint($character, (int) $chatId);

        return $resp;
    }

    private function renderList(int $chatId, int $characterId, string $mode = InventorySortService::MODE_RECENT): ServerResponse
    {
        $entries = InventorySortService::sortRows($this->loadEnrichedEntries($characterId), $mode);
        if (empty($entries)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "📦 *Склад базы пуст*\n\nСюда можно сложить добычу двумя путями: руками, "
                    . "стоя на базе, или карго-дроном с любой клетки.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '📥 Положить на склад', 'callback_data' => 'baseStorageDeposit'],
                    ['text' => '🚚 Карго-дрон',        'callback_data' => 'cargoDroneList'],
                    ['text' => '🏠 База',              'callback_data' => 'Base'],
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
            $text .= "Ты на базе — можно забрать всё в инвентарь, забрать один вид, или, наоборот, сложить сюда добычу из рюкзака.";

            // storage-craft-insurance-03: выбор одного вида — зеркало депозита.
            // Кнопки ограничены тем же пределом, что у сдачи; «Забрать всё» страхует хвост.
            $shown   = array_slice($entries, 0, self::MAX_BUTTONS);
            $buttons = [];
            foreach ($shown as $e) {
                $rid   = is_numeric($e['resource_id'] ?? null) ? (int) $e['resource_id'] : 0;
                $ename = is_string($e['name'] ?? null) ? $e['name'] : '';
                $eqty  = is_numeric($e['quantity'] ?? null) ? (int) $e['quantity'] : 0;
                if ($rid <= 0 || $ename === '' || $eqty <= 0) {
                    continue;
                }
                $buttons[] = [
                    'text'          => ResourceIconHelper::for($ename) . ' ' . $ename,
                    'callback_data' => "baseStorageList_res_{$rid}_{$mode}",
                ];
            }
            foreach (ButtonPacker::pack($buttons) as $row) {
                $rows[] = $row;
            }
            if (count($entries) > self::MAX_BUTTONS) {
                $text .= "\n\n_…и ещё " . (count($entries) - self::MAX_BUTTONS) . " видов — их заберёт кнопка «Забрать всё»._";
            }

            $rows[] = [
                ['text' => '🎒 Забрать всё',       'callback_data' => 'baseStorageList_all'],
                ['text' => '📥 Положить на склад', 'callback_data' => 'baseStorageDeposit'],
            ];
            $rows[] = [['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'], ['text' => '🏠 База', 'callback_data' => 'Base']];
        } else {
            $text .= "_Склад физически на базе. Вернись на свою клейм-клетку, чтобы забрать или сложить руками — "
                . "а из поля груз домой носит карго-дрон._";
            $rows[] = [['text' => '🗺 Карта', 'callback_data' => 'move'], ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList']];
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
     * Разбирает `baseStorageList_res_<resource_id>_<mode>` и делегирует забор.
     * Роутер режет callback_data только по первому `_` (см. class docblock), хвост
     * разбираем сами: первый сегмент — id ресурса, второй (опциональный) — режим
     * сортировки, чтобы после забора вернуться в тот же порядок.
     */
    private function retrieveOneFromCallback(int $chatId, int $characterId, string $callbackData): ServerResponse
    {
        $tail  = substr($callbackData, strlen('baseStorageList_res_'));
        $parts = explode('_', $tail, 2);
        $resId = is_numeric($parts[0]) ? (int) $parts[0] : 0;
        $mode  = InventorySortService::normalizeMode($parts[1] ?? null, InventorySortService::STORAGE_MODES, InventorySortService::MODE_RECENT);

        return $this->retrieveOne($chatId, $characterId, $resId, $mode);
    }

    /**
     * Забрать один вид ресурса целиком (зеркало `BaseStorageDepositAction::depositOne`).
     * Списание и зачисление — в одной транзакции через `withdraw()` (списывает не
     * больше, чем реально было на складе — на сбое ресурс не задваивается и не
     * исчезает); `quantityFor()`/read-then-withdraw гонки нет, т.к. `withdraw()`
     * сам берёт «сколько есть», а не то, что мы заранее прочитали отдельным запросом.
     */
    private function retrieveOne(int $chatId, int $characterId, int $resourceId, string $mode): ServerResponse
    {
        if ($resourceId <= 0) {
            return $this->errReply($chatId, 'Неверный ресурс.');
        }

        if (! $this->isOnBase($characterId)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚫 Чтобы забрать со склада, нужно быть на своей клейм-клетке. Вернись на базу.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $name = $this->resourceName($resourceId);

        $db = \Config\Database::connect();
        $db->transStart();
        $withdrawn = $this->storageModel->withdraw($characterId, $resourceId, PHP_INT_MAX);
        if ($withdrawn > 0) {
            $this->resourceModel->increaseResources($characterId, $resourceId, $withdrawn);
        }
        $db->transComplete();

        if ($withdrawn <= 0) {
            return $this->errReply($chatId, 'Такого ресурса на складе уже нет.');
        }

        $this->logActivity($characterId, 'BASE_STORAGE_RETRIEVE_ONE', "res={$name} qty={$withdrawn}");

        $emoji = ResourceIconHelper::for($name);
        $units = number_format($withdrawn, 0, '.', ' ');

        $text  = "🎒 *Забрано со склада*\n\n";
        $text .= "  {$emoji} *{$name}* × *{$units}* шт.\n\n";
        $text .= "Ресурс теперь в рюкзаке.";

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [
                    ['text' => '🎒 Забрать ещё',       'callback_data' => "baseStorageList_sort_{$mode}"],
                    ['text' => '📥 Положить на склад', 'callback_data' => 'baseStorageDeposit'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🏠 База',      'callback_data' => 'Base'],
                ],
            ]]),
        ]);
    }

    /**
     * Имя ресурса по id — для caption'а результата забора.
     */
    private function resourceName(int $resourceId): string
    {
        $db  = \Config\Database::connect();
        $q   = $db->query('SELECT name FROM resources WHERE id = ?', [$resourceId]);
        $row = (is_object($q) && method_exists($q, 'getRowArray')) ? $q->getRowArray() : null;
        return (is_array($row) && is_string($row['name'] ?? null)) ? $row['name'] : '';
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

    /**
     * На своей ли базе игрок. Reuse канонического BaseCheckService (map_cell_id ==
     * cell_number) — тот же критерий, что у дронов/построек. W8: заменил латентно-битый
     * inline-запрос (claimed_cells.cell_number — такой колонки нет, бросал SQL-исключение).
     */
    private function isOnBase(int $characterId): bool
    {
        $status = (new BaseCheckService())->checkBaseStatus($characterId);
        return ! empty($status['isOnBase']);
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
