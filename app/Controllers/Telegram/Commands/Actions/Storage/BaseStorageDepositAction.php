<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Storage;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Services\Bases\BaseStorageService;
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
 * W2.N6 (ADR-190) — handler только рендерер: гейт, список рюкзака и сдача живут в
 * {@see BaseStorageService} (тот же сервис у веба `/play?view=storage`).
 *
 * Media-off safe: чистый текст, весь смысл в тексте. Markdown — только парные `*`.
 */
final class BaseStorageDepositAction extends BaseAction
{
    /** Сколько ресурсов показываем кнопками (у ветеранов видов бывает много). */
    private const MAX_BUTTONS = 18;

    private BaseStorageService $storage;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->storage = new BaseStorageService();
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

        // Экранный гейт (список тоже только на базе); мутации сервис проверяет сам.
        if (! $this->storage->isOnBase($characterId)) {
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
        $rows = $this->storage->carriedResources($characterId);

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
        $result = $this->storage->depositOne($characterId, $resourceId, $this->currentCell(), $chatId);

        switch ($result['code']) {
            case BaseStorageService::OK:
                break;
            case BaseStorageService::OFF_BASE:
                return $this->offBaseScreen($chatId);
            case BaseStorageService::BAD_RESOURCE:
                return $this->errReply($chatId, 'Неверный ресурс.');
            case BaseStorageService::NOT_CARRIED:
                return $this->errReply($chatId, 'Такого ресурса в рюкзаке нет.');
            case BaseStorageService::MISSING:
                return $this->errReply($chatId, 'Этого ресурса в рюкзаке уже нет.');
            default:
                return $this->errReply($chatId, 'В рюкзаке столько не набралось — кто-то успел его потратить. Попробуй ещё раз.');
        }

        $row   = ['name' => $result['name'], 'quantity' => $result['quantity']];
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
        $result = $this->storage->depositAll($characterId, $this->currentCell(), $chatId);

        if ($result['code'] === BaseStorageService::OFF_BASE) {
            return $this->offBaseScreen($chatId);
        }
        if ($result['code'] === BaseStorageService::EMPTY) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => '🎒 В рюкзаке пусто — складывать нечего.',
                'parse_mode' => 'Markdown',
            ]);
        }
        // Откат и «всё утекло» сервис сводит в не-OK (exploit-fix-23: исход по transComplete()).
        if ($result['code'] !== BaseStorageService::OK) {
            return $this->errReply($chatId, 'В рюкзаке уже ничего не осталось — кто-то успел его потратить. Попробуй ещё раз.');
        }

        $totalUnits = $result['units'];
        $kinds      = $result['kinds'];
        $skipped    = $result['skipped'];

        $text  = "📥 *Убрано на склад: " . number_format($totalUnits, 0, '.', ' ') . " шт.*\n\n";
        $text .= "Видов ресурсов: *{$kinds}*. Рюкзак пуст, всё лежит на складе базы — забрать можно там же.";
        if ($skipped > 0) {
            // exploit-fix-26 (R2-minor, reviewer-2) — «2 видов» вместо «2 вида»: было бинарное
            // 1/не-1, без склонения по числу (1 вид, 2–4 вида, 5+ видов, с исключением 11–14).
            $text .= "\n\n_Часть ({$skipped} " . self::pluralizeKinds($skipped) . ") уже утекла из рюкзака до сдачи — пропущено без потерь._";
        }

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
                    ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'),      'callback_data' => 'move'],
                ],
                [
                    ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                ],
            ]]),
        ]);
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

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }

    /**
     * exploit-fix-26 (R2-minor) — русское склонение «вид/вида/видов» по числу: 1 вид,
     * 2–4 вида, 5+ видов, с исключением 11–14 (11 видов, не 11 вид).
     *
     * exploit-fix-34 (R3-minor, ревью m9): чистая функция без состояния — сделана
     * `public static`, чтобы её закрыл юнит-тест напрямую, без инстанцирования action.
     */
    public static function pluralizeKinds(int $n): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'видов';
        }

        return match ($mod10) {
            1       => 'вид',
            2, 3, 4 => 'вида',
            default => 'видов',
        };
    }
}
