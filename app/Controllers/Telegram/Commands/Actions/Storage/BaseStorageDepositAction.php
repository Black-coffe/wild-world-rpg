<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Storage;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Services\Bases\BaseCheckService;
use App\Services\Telegram\ButtonPacker;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Ручная сдача ресурсов на склад базы — обратная сторона `BaseStorageListAction`.
 *
 * Зачем. До этого в `base_storage` писала РОВНО одна точка — карго-дрон
 * ({@see \App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneSendAction}),
 * а забрать со склада можно было только стоя на клейм-клетке. Асимметрия читалась
 * как поломка: стоя физически на своей базе игрок мог вынести со склада, но не мог
 * ничего туда положить — надо было отойти на другую клетку и запустить дрон себе же
 * домой. Сигнал игрока (Анжела, 18.08.2026): «находясь на базе переместить лут на
 * склад можно только дроном — нелогично».
 *
 * Callback'и (первый сегмент `baseStorageDeposit`, хвост разбирает сам action —
 * НЕ prefixRoute, урок мёртвых `npcAct_`):
 *   - `baseStorageDeposit`               — экран со списком добытого в рюкзаке
 *   - `baseStorageDeposit_res_<res_id>`  — сдать весь этот ресурс
 *   - `baseStorageDeposit_all`           — сдать всё добытое разом
 *
 * Гейт — тот же, что у выдачи: `BaseCheckService::checkBaseStatus()['isOnBase']`
 * (склад физически на базе). Не на базе — экран НЕ прячется (правило
 * UX-Discoverability), а объясняет причину и даёт две живые двери: вернуться на
 * базу или отправить карго-дрон, который работает с любой клетки.
 *
 * Media-off safe: чистый текст, весь смысл в тексте. Markdown — только парные `*`.
 */
final class BaseStorageDepositAction extends BaseAction
{
    /** Сколько ресурсов показываем кнопками (у ветеранов видов бывает много). */
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
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        if (! $this->isOnBase($characterId)) {
            return $this->offBaseScreen($chatId);
        }

        $data = (string) $this->callbackQuery->getData();

        if ($data === 'baseStorageDeposit_all') {
            return $this->depositAll($chatId, $characterId);
        }

        if (str_starts_with($data, 'baseStorageDeposit_res_')) {
            $resId = (int) substr($data, strlen('baseStorageDeposit_res_'));
            return $this->depositOne($chatId, $characterId, $resId);
        }

        return $this->renderList($chatId, $characterId);
    }

    /**
     * Экран выбора: что из рюкзака положить на склад.
     */
    private function renderList(int $chatId, int $characterId): ServerResponse
    {
        $rows = $this->carriedResources($characterId);

        if ($rows === []) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => "🎒 *В рюкзаке пусто*\n\nСкладывать на склад нечего — сходи за ресурсами.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                    ['text' => '🏠 База',       'callback_data' => 'Base'],
                ]]]),
            ]);
        }

        $totalUnits = 0;
        $lines      = [];
        foreach ($rows as $r) {
            $totalUnits += $r['quantity'];
            $lines[] = ResourceIconHelper::for($r['name']) . " {$r['name']} — *{$r['quantity']}* шт.";
        }

        $shown = array_slice($rows, 0, self::MAX_BUTTONS);

        $text  = "📥 *Положить на склад*\n\n";
        $text .= "Ты на базе — можно переложить добытое из рюкзака на склад руками, без карго-дрона.\n\n";
        $text .= implode("\n", array_slice($lines, 0, self::MAX_BUTTONS)) . "\n";
        if (count($rows) > self::MAX_BUTTONS) {
            $text .= "_…и ещё " . (count($rows) - self::MAX_BUTTONS) . " видов — их заберёт кнопка «Положить всё»._\n";
        }
        $text .= "\nВсего в рюкзаке: *" . number_format($totalUnits, 0, '.', ' ') . "* шт.\n\n";
        $text .= "Нажми на ресурс, чтобы отправить его на склад целиком.";

        $buttons = [];
        foreach ($shown as $r) {
            $buttons[] = [
                'text'          => ResourceIconHelper::for($r['name']) . ' ' . $r['name'],
                'callback_data' => 'baseStorageDeposit_res_' . $r['resource_id'],
            ];
        }

        $keyboard   = ButtonPacker::pack($buttons);
        $keyboard[] = [
            ['text' => '📥 Положить всё', 'callback_data' => 'baseStorageDeposit_all'],
            ['text' => '📦 Склад базы',   'callback_data' => 'baseStorageList'],
        ];
        $keyboard[] = [
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ['text' => '🏠 База',      'callback_data' => 'Base'],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    /**
     * Сдать один ресурс целиком.
     */
    private function depositOne(int $chatId, int $characterId, int $resourceId): ServerResponse
    {
        if ($resourceId <= 0) {
            return $this->errReply($chatId, 'Неверный ресурс.');
        }

        $row = null;
        foreach ($this->carriedResources($characterId) as $r) {
            if ($r['resource_id'] === $resourceId) {
                $row = $r;
                break;
            }
        }

        if ($row === null) {
            return $this->errReply($chatId, 'Такого ресурса в рюкзаке нет.');
        }

        $fromCell = $this->currentCell();

        $db = \Config\Database::connect();
        $db->transStart();
        $this->resourceModel->decreaseResources($characterId, $resourceId, $row['quantity']);
        $this->storageModel->deliver($characterId, $resourceId, $row['quantity'], $fromCell);
        $db->transComplete();

        $this->logActivity($characterId, 'BASE_STORAGE_DEPOSIT', "res={$row['name']} qty={$row['quantity']}");

        $emoji = ResourceIconHelper::for($row['name']);
        $qty   = number_format($row['quantity'], 0, '.', ' ');

        $text  = "📥 *Убрано на склад*\n\n";
        $text .= "  {$emoji} *{$row['name']}* × *{$qty}* шт.\n\n";
        $text .= "Ресурс никуда не делся — он на складе базы, забрать можно там же, стоя на базе.";

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [
                    ['text' => '📥 Положить ещё', 'callback_data' => 'baseStorageDeposit'],
                    ['text' => '📦 Склад базы',   'callback_data' => 'baseStorageList'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🏠 База',      'callback_data' => 'Base'],
                ],
            ]]),
        ]);
    }

    /**
     * Сдать всё добытое разом (зеркало «🎒 Забрать всё»).
     */
    private function depositAll(int $chatId, int $characterId): ServerResponse
    {
        $rows = $this->carriedResources($characterId);
        if ($rows === []) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => '🎒 В рюкзаке пусто — складывать нечего.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $fromCell = $this->currentCell();

        $db = \Config\Database::connect();
        $db->transStart();

        $totalUnits = 0;
        $kinds      = 0;
        foreach ($rows as $r) {
            $this->resourceModel->decreaseResources($characterId, $r['resource_id'], $r['quantity']);
            $this->storageModel->deliver($characterId, $r['resource_id'], $r['quantity'], $fromCell);
            $totalUnits += $r['quantity'];
            $kinds++;
        }

        $db->transComplete();

        $this->logActivity($characterId, 'BASE_STORAGE_DEPOSIT_ALL', "kinds={$kinds} units={$totalUnits}");

        $text  = "📥 *Убрано на склад: " . number_format($totalUnits, 0, '.', ' ') . " шт.*\n\n";
        $text .= "Видов ресурсов: *{$kinds}*. Рюкзак пуст, всё лежит на складе базы — забрать можно там же.";

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                ['text' => '🏠 База',       'callback_data' => 'Base'],
            ]]]),
        ]);
    }

    /**
     * Экран для случая «не на базе»: фича не прячется, а объясняет путь.
     */
    private function offBaseScreen(int $chatId): ServerResponse
    {
        $text  = "🔒 *Положить на склад можно только на базе*\n\n";
        $text .= "Склад стоит на твоей клейм-клетке — руками занести туда добычу получится, "
            . "только когда ты сам на базе.\n\n";
        $text .= "Из поля работает карго-дрон: он заберёт до 30 кг с любой клетки и отвезёт их на склад сам.";

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [
                    ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'],
                    ['text' => '🗺 Карта',      'callback_data' => 'move'],
                ],
                [
                    ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                ],
            ]]),
        ]);
    }

    /**
     * Добытые ресурсы в рюкзаке (`character_resources`), от большего к меньшему.
     *
     * @return list<array{resource_id:int, name:string, quantity:int}>
     */
    private function carriedResources(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT cr.id_resources AS resource_id, cr.quantity, r.name
             FROM character_resources cr
             INNER JOIN resources r ON r.id = cr.id_resources
             WHERE cr.id_characters = ? AND cr.quantity > 0
             ORDER BY cr.quantity DESC',
            [$characterId]
        );
        if (! is_object($q) || ! method_exists($q, 'getResultArray')) {
            return [];
        }

        $out = [];
        foreach ($q->getResultArray() as $r) {
            if (! is_array($r)) {
                continue;
            }
            $resId = is_numeric($r['resource_id'] ?? null) ? (int) $r['resource_id'] : 0;
            $qty   = is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0;
            $name  = is_string($r['name'] ?? null) ? $r['name'] : '';
            if ($resId <= 0 || $qty <= 0 || $name === '') {
                continue;
            }
            $out[] = ['resource_id' => $resId, 'name' => $name, 'quantity' => $qty];
        }

        return $out;
    }

    /**
     * Клетка, с которой пришла партия — ярлык происхождения в `base_storage`
     * (`arrived_from_cell`), тот же смысл, что у карго-дрона.
     */
    private function currentCell(): ?int
    {
        [, $character] = $this->getUserAndCharacter();
        if (! $character) {
            return null;
        }
        return is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : null;
    }

    /**
     * На своей ли базе игрок. Тот же канонический критерий, что у выдачи со склада
     * и у дронов (`claimed_cells.map_cell_id == cell_number`).
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
