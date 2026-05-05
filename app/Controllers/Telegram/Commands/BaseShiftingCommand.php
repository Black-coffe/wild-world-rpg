<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use CodeIgniter\I18n\Time;

use App\Models\TelegramUserModel;
use App\Models\CharacterModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\MapModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;

class BaseShiftingCommand extends UserCommand
{
    protected $name = 'base_shifting'; // /base_shifting
    protected $description = 'Полноценный переезд базы на новые координаты (X=..., Y=...)';
    protected $usage = '/base_shifting X=123Y=456';
    protected $version = '1.0.0';

    /**
     * Выполняется, когда игрок вводит: /base_shifting X=...Y=...
     */
    public function execute(): ServerResponse
    {
        $message   = $this->getMessage();
        $chatId    = $message->getChat()->getId();
        $from      = $message->getFrom();
        $userText  = $message->getText(true) ?? '';

        // 1) Парсим "X=123Y=456"
        if (!preg_match('/X=(\d+)Y=(\d+)/', $userText, $m)) {
            $txt = "⚠️ Формат команды: `/base_shifting X=123Y=456`\nБез лишних пробелов!";
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $txt,
                'parse_mode' => 'Markdown',
            ]);
        }
        $x = (int)$m[1];
        $y = (int)$m[2];

        // 2) Ищем пользователя/персонажа
        $telegramId = $from->getId();
        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Ошибка: телеграм-профиль не найден.",
            ]);
        }
        $charModel = new CharacterModel();
        $character = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет персонажа! Сначала создайте персонажа.",
            ]);
        }

        // 3) Проверяем диапазон
        if ($x<1 || $x>1000 || $y<1 || $y>1000) {
            $txt = "❌ Координаты X,Y должны быть 1..1000.\nВы ввели: X={$x}, Y={$y}";
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $txt,
                'parse_mode' => 'Markdown'
            ]);
        }

        // 4) Проверяем кулдаун, активную задачу и т.д.
        $canProceed = $this->checkPreconditions($character, $chatId);
        if (!$canProceed['ok']) {
            // Если там текст — возвращаем
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $canProceed['error'],
                'parse_mode' => 'Markdown',
            ]);
        }
        $frTaskId = $canProceed['fullRelocTaskId']; // ID задачи FullRelocation

        // 5) Проверяем карту
        $mapModel = new MapModel();
        $mapRow   = $mapModel->where('coordinate_x',$x)->where('coordinate_y',$y)->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❌ Нет ячейки с координатами X={$x}, Y={$y}!",
            ]);
        }
        $mapCellId = $mapRow['id'];

        // 6) Проверяем занятость ячейки / резерв
        $reason    = '';
        if (!$this->isCellAvailableForRelocation($character['id'], $mapCellId, $reason)) {
            // Ячейка недоступна => ищем ближайшую
            $closestInfo = $this->findClosestExploredFreeCell($character['id'], $x, $y);
            if (!$closestInfo) {
                // Ни одной альтернативы
                // Сформируем текст с "причиной"
                $txt = "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
                    . "Причина: _{$reason}_\n\n"
                    . "И увы, поблизости нет других свободных и изученных локаций.\n"
                    . "Придётся поискать другие координаты позже…";
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $txt,
                    'parse_mode' => 'Markdown'
                ]);
            } else {
                // Предлагаем игроку другой вариант
                $altX = $closestInfo['x'];
                $altY = $closestInfo['y'];
                // Можно найти название биома, если хотите (через BiomeModel)
                $altBiomeName = $this->getBiomeNameByMapId($closestInfo['map_id']);

                $txt = "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
                    . "Причина: _{$reason}_\n\n"
                    . "Но мы нашли *ближайшую* подходящую ячейку:\n"
                    . "X={$altX}, Y={$altY}\n"
                    . "Биом: *{$altBiomeName}*\n\n"
                    . "Если тебя это устраивает, просто скопируй команду:\n"
                    . "`/base_shifting X={$altX}Y={$altY}`\n\n"
                    . "Удачи в пути, сталкер! 🤠";

                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $txt,
                    'parse_mode' => 'Markdown'
                ]);
            }
        }

        // 7) Проверяем, изучил ли игрок данную точку
        if (!$this->isCellExploredBy($character['id'], $mapCellId)) {
            // Не изучена => ищем альтернативу
            $closestInfo = $this->findClosestExploredFreeCell($character['id'], $x, $y);
            if (!$closestInfo) {
                $txt = "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
                    . "Причина: _Не изучена_\n\n"
                    . "И поблизости нет других свободных локаций.\n";
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $txt,
                    'parse_mode' => 'Markdown'
                ]);
            } else {
                $altX = $closestInfo['x'];
                $altY = $closestInfo['y'];
                $altBiomeName = $this->getBiomeNameByMapId($closestInfo['map_id']);

                $txt = "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
                    . "Причина: _Не изучена_\n\n"
                    . "Но вот ближайшая изученная:\n"
                    . "X={$altX}, Y={$altY}\n"
                    . "Биом: *{$altBiomeName}*\n\n"
                    . "Хочешь туда? Скопируй:\n"
                    . "`/base_shifting X={$altX}Y={$altY}`";
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $txt,
                    'parse_mode' => 'Markdown'
                ]);
            }
        }

        // 8) Всё ок, ячейка доступна и изучена => предлагаем подтверждение
        $biomeName = $this->getBiomeNameByMapId($mapCellId);

        // Сообщение
        $text = "✅ *Ты переезжаешь в выбранную ячейку* (X={$x}, Y={$y})\n"
            . "Биом: *{$biomeName}*\n\n"
            . "Если всё устраивает — просто жми кнопку \"Поехали!\" 🚀\n"
            . "_Задача займёт ~24 часа. Следующий переезд не раньше, чем через 10 дней._";

        // Inline-кнопка
        $callbackData = "StartRelocationConfirm_{$x}_{$y}_{$mapCellId}";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Поехали 🚀', 'callback_data' => $callbackData]
                ]
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Обработчик колбэков (если используете Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand)
     * Можно вызывать этот метод вручную из CallbackqueryCommand
     */
    public function handleCallback(CallbackQuery $callbackQuery): ServerResponse
    {
        $callbackData = $callbackQuery->getData();
        $chatId       = $callbackQuery->getMessage()->getChat()->getId();

        // Пример: "StartRelocationConfirm_123_456_9999"
        if (strpos($callbackData, 'StartRelocationConfirm_') === 0) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);
            $parts = explode('_', $callbackData); // [StartRelocationConfirm, X, Y, mapCellId]
            if (count($parts) < 4) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ошибка: неправильные данные колбэка!",
                ]);
            }
            $x = (int)$parts[1];
            $y = (int)$parts[2];
            $mapCellId = (int)$parts[3];

            // Повторно проверим, что сейчас всё ещё ок
            // (на случай, если что-то поменялось)
            [$user,$character] = $this->getUserAndCharacterByCallback($callbackQuery);
            if (!$user || !$character) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ошибка: пользователь/персонаж не найден.",
                ]);
            }

            // Снова проверим кулдаун и т.д.
            $canProceed = $this->checkPreconditions($character, $chatId);
            if (!$canProceed['ok']) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => $canProceed['error'],
                    'parse_mode' => 'Markdown',
                ]);
            }
            $frTaskId = $canProceed['fullRelocTaskId'];

            // Проверим, не занял ли кто-то эту ячейку за то время
            $reason = '';
            if (!$this->isCellAvailableForRelocation($character['id'], $mapCellId, $reason)) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Увы, пока ты думал, ячейка стала недоступна. Причина: {$reason}.\nПопробуй другую!",
                    'parse_mode' => 'Markdown',
                ]);
            }
            // Проверим, не изучал ли. (Вероятно да, но на всякий случай.)
            if (!$this->isCellExploredBy($character['id'], $mapCellId)) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ячейка оказалась не изучена тобой! Выбери другую.",
                ]);
            }

            // Всё нормально => создаём задачу
            $text = "🚀 Поехали! Переезд в X={$x},Y={$y} запущен.\nПродлится 24 часа.";

            $this->startRelocationTask($chatId, $user['id'], $character, $frTaskId, $mapCellId, $x, $y, $text);

            return Request::emptyResponse(); // или sendMessage() уже внутри startRelocationTask
        }

        return Request::emptyResponse();
    }

    // ----------------------------------------------
    // Вспомогательные методы
    // ----------------------------------------------

    /**
     * Проверяем кулдаун, нет ли активной задачи FullRelocation.
     * Возвращаем ['ok'=>true,'fullRelocTaskId'=>int] или ['ok'=>false,'error'=>'текст']
     */
    private function checkPreconditions(array $character, int $chatId): array
    {
        $taskModel = new TaskModel();
        $frTask = $taskModel->where('name','FullRelocation')->first();
        if (!$frTask) {
            // Создадим
            $frTaskId = $taskModel->insert([
                'name' => 'FullRelocation',
                'name_rus' => 'Полноценный переезд',
                'description' => '24h + 10d cooldown',
                'min_duration'=>1440,'max_duration'=>1440,
                'type'=>'exclusive','difficulty_level'=>7,
                'execution_limit'=>0,'parallel_execution_allowed'=>0,
                'interruptible'=>1
            ]);
        } else {
            $frTaskId = $frTask['id'];
        }

        // Активная задача?
        $charTaskModel = new CharacterTaskModel();
        $active = $charTaskModel
            ->where('character_id',$character['id'])
            ->where('task_id',$frTaskId)
            ->where('status','in_work')
            ->first();
        if ($active) {
            return ['ok'=>false,'error'=>"❌ У вас уже идёт полный переезд! Дождитесь окончания."];
        }

        // Кулдаун?
        $lastCompl = $charTaskModel
            ->where('character_id',$character['id'])
            ->where('task_id',$frTaskId)
            ->where('status','completed')
            ->orderBy('created_at','DESC')
            ->first();
        if ($lastCompl && !empty($lastCompl['end_time'])) {
            $endTime = new Time($lastCompl['end_time']);
            $now     = Time::now();
            $diffDays= $endTime->difference($now)->getDays();
            if ($diffDays < 10) {
                $need = 10-$diffDays;
                return ['ok'=>false,'error'=>"❌ С прошлого переезда прошло {$diffDays} дн. Нужно 10 дн. ожидания, осталось ~{$need} дн."];
            }
        }

        return ['ok'=>true,'fullRelocTaskId'=>$frTaskId];
    }

    /**
     * Проверка, можно ли переехать в ячейку (mapCellId) - нет ли active claimed_cells и active FullRelocation->new_map_cell_id
     * Возвращаем true/false, а в $reason пишем «Занята», «Резерв» и т.п.
     */
    private function isCellAvailableForRelocation(int $charId, int $mapCellId, string &$reason): bool
    {
        $reason = '';

        // 1) claimed_cells
        $claimedCellModel = new ClaimedCellModel();
        $row = $claimedCellModel
            ->where('map_cell_id',$mapCellId)
            ->where('status','active')
            ->where('character_id !=',$charId)
            ->first();
        if ($row) {
            $reason = "Занята другим игроком";
            return false;
        }

        // 2) active FullRelocation-> new_map_cell_id = $mapCellId?
        $taskModel        = new TaskModel();
        $frTask           = $taskModel->where('name','FullRelocation')->first();
        if ($frTask) {
            $frTaskId       = $frTask['id'];
            $charTaskModel  = new CharacterTaskModel();
            $busy = $charTaskModel
                ->where('status','in_work')
                ->where('task_id',$frTaskId)
                ->like('task_settings','"new_map_cell_id":'.$mapCellId,'both')
                ->first();
            if ($busy && $busy['character_id'] != $charId) {
                $reason = "Туда уже переезжает другой игрок";
                return false;
            }
        }

        return true; // все ок
    }

    /**
     * Проверяем, изучал ли персонаж ячейку
     */
    private function isCellExploredBy(int $charId, int $mapCellId): bool
    {
        $exploredModel = new ExploredCellsModel();
        $row = $exploredModel
            ->where('character_id',$charId)
            ->where('map_cell_id',$mapCellId)
            ->first();
        return (bool)$row;
    }

    /**
     * Запускаем саму задачу (24h).
     */
    private function startRelocationTask(
        int $chatId,
        int $telegramUserId,
        array $character,
        int $frTaskId,
        int $mapCellId,
        int $x,
        int $y,
        string $textToPlayer
    ): ServerResponse {
        $charTaskModel = new CharacterTaskModel();
        $now = Time::now();
        $end = $now->addHours(24);

        $settings = [
            'new_map_cell_id' => $mapCellId,
            'note' => "Переезд в X={$x},Y={$y} (map_id={$mapCellId})",
        ];

        $charTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $telegramUserId,
            'task_id'          => $frTaskId,
            'start_time'       => $now->toDateTimeString(),
            'end_time'         => $end->toDateTimeString(),
            'status'           => 'in_work',
            'task_settings'    => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $textToPlayer,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Находим ближайшую свободную изученную ячейку (экспоненциальное расширение).
     */
    private function findClosestExploredFreeCell(int $charId, int $X, int $Y): ?array
    {
        $maxRadius = 1024;
        $r = 16;
        $closest = null;
        $closestDist = PHP_INT_MAX;

        $db = \Config\Database::connect();
        $taskModel = new TaskModel();
        $frTask    = $taskModel->where('name','FullRelocation')->first();
        $frTaskId  = $frTask ? $frTask['id'] : null;

        while ($r <= $maxRadius) {
            $sql = "SELECT m.id AS map_id, m.coordinate_x, m.coordinate_y
                    FROM explored_cells ec
                    JOIN map m ON m.id = ec.map_cell_id
                    WHERE ec.character_id=:charId:
                      AND m.coordinate_x BETWEEN :xmin: AND :xmax:
                      AND m.coordinate_y BETWEEN :ymin: AND :ymax:";
            $bind = [
                'charId' => $charId,
                'xmin' => $X-$r,
                'xmax' => $X+$r,
                'ymin' => $Y-$r,
                'ymax' => $Y+$r,
            ];
            $query   = $db->query($sql, $bind);
            if (!$query instanceof \CodeIgniter\Database\BaseResult) {
                return null;
            }
            $results = $query->getResultArray();

            if (!empty($results)) {
                // Фильтр на "свободна"
                foreach ($results as $row) {
                    $mapId = $row['map_id'];
                    // Проверим занятость
                    $reason='';
                    if (!$this->isCellAvailableForRelocation($charId,$mapId,$reason)) {
                        // занята/резерв
                        continue;
                    }
                    // вычисляем дистанцию
                    $dx = $row['coordinate_x'] - $X;
                    $dy = $row['coordinate_y'] - $Y;
                    $dist = sqrt($dx*$dx + $dy*$dy);
                    if ($dist < $closestDist) {
                        $closestDist = $dist;
                        $closest = [
                            'map_id' => $mapId,
                            'x' => $row['coordinate_x'],
                            'y' => $row['coordinate_y'],
                            'distance' => $dist
                        ];
                    }
                }
                if ($closest) {
                    break; // выходим из while
                }
            }
            $r *= 2;
        }
        return $closest; // null если не нашли
    }

    /**
     * Получаем имя биома (рус) по map_id -> map.biome_id -> biome.name
     */
    private function getBiomeNameByMapId(int $mapId): string
    {
        $db = \Config\Database::connect();
        $query = $db->table('map')
            ->select('biomes.name AS biome_name')
            ->join('biomes','biomes.id=map.biome_id','left')
            ->where('map.id',$mapId)
            ->get();
        if ($query === false) {
            return "Неизвестный биом";
        }
        $row = $query->getRowArray();
        if ($row && !empty($row['biome_name'])) {
            return $row['biome_name'];
        }
        return "Неизвестный биом";
    }

    /**
     * Получаем [user,character] из callbackQuery (если надо)
     */
    private function getUserAndCharacterByCallback(CallbackQuery $callbackQuery): array
    {
        $tid = $callbackQuery->getFrom()->getId();
        $userModel = new TelegramUserModel();
        $urow = $userModel->where('telegram_id',$tid)->first();
        if (!$urow) return [null,null];

        $charModel = new CharacterModel();
        $crow = $charModel->where('telegram_user_id',$urow['id'])->first();
        if (!$crow) return [null,null];

        return [$urow,$crow];
    }
}
