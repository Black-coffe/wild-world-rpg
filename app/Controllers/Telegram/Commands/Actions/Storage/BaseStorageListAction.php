<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Storage;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Services\Bases\BaseStorageService;
use App\Services\Display\MarkdownSafe;
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
 * W2.N6 (ADR-190) — handler только рендерер: модель склада, гейт «на базе» и забор
 * живут в {@see BaseStorageService} (тот же сервис рисует веб `/play?view=storage`).
 *
 * Media-off safe (caption самодостаточен).
 */
class BaseStorageListAction extends BaseAction
{
    /** Сколько видов показываем кнопками (зеркало `BaseStorageDepositAction::MAX_BUTTONS`). */
    private const MAX_BUTTONS = 18;

    private BaseStorageService $storage;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->storage = new BaseStorageService();
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
        $model   = $this->storage->storageModel($characterId, $mode);
        $entries = $model['rows'];
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

        $onBase = $model['on_base'];

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
            $emoji     = ResourceIconHelper::for($name);
            $safeName  = MarkdownSafe::name($name, 'ресурс');
            $text     .= "{$emoji} {$safeName} — *{$qty}* шт.\n";
        }
        $text .= "\nИтого: *{$totalUnits}* шт.\n\n";

        $rows = [$this->sortRow($mode)]; // W8: переключатель сортировки

        if ($onBase) {
            $text .= "Ты на базе — можно забрать всё в инвентарь, забрать один вид, или, наоборот, сложить сюда добычу из рюкзака.";

            // storage-craft-insurance-03: выбор одного вида — зеркало депозита.
            // Кнопки ограничены тем же пределом, что у сдачи; «Забрать всё» страхует хвост.
            $built = $this->buildResourceButtonRows($entries, $mode);
            foreach ($built['rows'] as $row) {
                $rows[] = $row;
            }
            if ($built['hiddenCount'] > 0) {
                $text .= "\n\n_…и ещё " . $built['hiddenCount'] . " видов — их заберёт кнопка «Забрать всё»._";
            }

            $rows[] = [
                ['text' => '🎒 Забрать всё',       'callback_data' => 'baseStorageList_all'],
                ['text' => '📥 Положить на склад', 'callback_data' => 'baseStorageDeposit'],
            ];
            $rows[] = [['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'], ['text' => '🏠 База', 'callback_data' => 'Base']];
        } else {
            $text .= "_Склад физически на базе. Вернись на свою клейм-клетку, чтобы забрать или сложить руками — "
                . "а из поля груз домой носит карго-дрон._";
            $rows[] = [['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'), 'callback_data' => 'move'], ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList']];
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
        $result = $this->storage->withdrawAll($characterId);

        if ($result['code'] === BaseStorageService::OFF_BASE) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $this->offBaseDenialText(),
                'parse_mode' => 'Markdown',
            ]);
        }
        if ($result['code'] === BaseStorageService::EMPTY) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "📦 Склад уже пуст — забирать нечего.",
                'parse_mode' => 'Markdown',
            ]);
        }
        // Исход транзакции проверяет сервис: при откате игрок не читает «Забрано».
        if ($result['code'] !== BaseStorageService::OK) {
            return $this->errReply($chatId, 'Не удалось забрать ресурсы со склада — попробуй ещё раз.');
        }

        $totalUnits = $result['units'];

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
     * Списание и зачисление — {@see BaseStorageService::withdrawOne()}: одна транзакция,
     * условный `withdraw()` берёт «сколько есть», а не заранее прочитанное.
     */
    private function retrieveOne(int $chatId, int $characterId, int $resourceId, string $mode): ServerResponse
    {
        $result = $this->storage->withdrawOne($characterId, $resourceId, $chatId);

        if ($result['code'] === BaseStorageService::BAD_RESOURCE) {
            return $this->errReply($chatId, 'Неверный ресурс.');
        }
        if ($result['code'] === BaseStorageService::OFF_BASE) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $this->offBaseDenialText(),
                'parse_mode' => 'Markdown',
            ]);
        }
        if ($result['code'] === BaseStorageService::FAILED) {
            return $this->errReply($chatId, 'Не удалось забрать ресурс со склада — попробуй ещё раз.');
        }
        if ($result['code'] !== BaseStorageService::OK) {
            return $this->errReply($chatId, 'Такого ресурса на складе уже нет.');
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $this->formatRetrieveMessage($result['name'], $result['withdrawn']),
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
     * Текст результата забора. Имя ресурса экранируется тем же способом, что
     * на экране страховки ({@see MarkdownSafe::name()}) — пустое или
     * содержащее `*`/`_` имя раньше давало непарный маркер в legacy-Markdown,
     * Telegram отвечал 400, и сообщение молча не уходило уже ПОСЛЕ того, как
     * ресурс был перемещён со склада в рюкзак.
     */
    private function formatRetrieveMessage(string $name, int $withdrawn): string
    {
        $safeName = MarkdownSafe::name($name, 'ресурс');
        $emoji     = ResourceIconHelper::for($name);
        $units     = number_format($withdrawn, 0, '.', ' ');

        $text  = "🎒 *Забрано со склада*\n\n";
        $text .= "  {$emoji} *{$safeName}* × *{$units}* шт.\n\n";
        $text .= "Ресурс теперь в рюкзаке.";

        return $text;
    }

    /**
     * Кнопки выбора одного вида ресурса — зеркало депозита. Кнопки ограничены
     * `self::MAX_BUTTONS`, но внутри среза попадаются мусорные строки (нулевой
     * id/пустое имя/нулевое количество) — их отсеивает `$buttons`. Раньше
     * «…и ещё N видов» считало `count($entries) - MAX_BUTTONS`, т.е. хвост
     * ЗА пределами среза, а кнопки строились из фильтрованного среза — при
     * мусоре внутри среза число в тексте расходилось с фактическим числом
     * кнопок. `hiddenCount` теперь считает от того же `$buttons`, что и ряды.
     *
     * @param  list<array<string,mixed>> $entries
     * @return array{rows:list<list<array{text:string,callback_data:string}>>, hiddenCount:int}
     */
    private function buildResourceButtonRows(array $entries, string $mode): array
    {
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
                'text'          => ResourceIconHelper::for($ename) . ' ' . MarkdownSafe::name($ename, 'ресурс'),
                'callback_data' => "baseStorageList_res_{$rid}_{$mode}",
            ];
        }

        return [
            'rows'        => ButtonPacker::pack($buttons),
            'hiddenCount' => count($entries) - count($buttons),
        ];
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
     * Отказ «не на базе» — общий текст для «Забрать всё» и забора одного вида.
     * Объясняет, что делать (правило UX-discoverability), не отдаёт голое «ошибка».
     */
    private function offBaseDenialText(): string
    {
        return "🚫 Чтобы забрать со склада, нужно быть на своей клейм-клетке. Вернись на базу.";
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
