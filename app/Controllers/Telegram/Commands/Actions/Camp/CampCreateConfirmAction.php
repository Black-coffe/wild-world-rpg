<?php
namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Services\Bases\BaseCallbackSuffix;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class CampCreateConfirmAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1. Закрываем "часики" на инлайн-кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 2. Достаём $chatId, $character
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: не удалось определить персонажа.'
            ]);
        }

        // ADR-095 Фаза 1a: defense-in-depth лимит баз по уровню — на случай прямого
        // callback 'Camp' в обход гейта EntrenchAction.
        $baseLimit = new \App\Services\Bases\BaseLimitService();
        $level     = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;
        $rawCount  = (new ClaimedCellModel())->where('character_id', $character['id'])->countAllResults();
        $campCount = is_numeric($rawCount) ? (int) $rawCount : 0;
        if ($campCount >= $baseLimit->maxBasesForLevel($level)) {
            $nextLevel = $baseLimit->nextBaseLevel($campCount);
            $msg = $nextLevel === null
                ? "🚫 У тебя максимально возможное число баз ({$campCount}). Больше построить нельзя."
                : "🚫 Лимит баз для уровня {$level} достигнут ({$campCount}). Следующая база откроется на {$nextLevel}-м уровне.";
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg, 'parse_mode' => 'Markdown']);
        }

        // 3. Убеждаемся, что у игрока нет базы
        $cellNumber = $character['cell_number'] ?? 0;
        if (!$cellNumber) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => 'Ошибка: у вашего персонажа нет координаты cell_number!',
                'parse_mode' => 'Markdown'
            ]);
        }

        $mapModel = new MapModel();
        $mapRow   = $mapModel->where('cell_number', $cellNumber)->first();
        if (!$mapRow) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "Ошибка: не найдена карта для cell_number={$cellNumber}",
                'parse_mode' => 'Markdown'
            ]);
        }

        // 4. Сохраняем в claimed_cells новую запись (лагерь)
        $claimedCellModel = new ClaimedCellModel();
        $newCampData = [
            'character_id'    => $character['id'],
            'map_cell_id'     => $mapRow['id'], // важно: 'map_cell_id' хранит ID в таблице map, а не cell_number
            'claimed_at'      => date('Y-m-d H:i:s'),
            'last_visited_at' => date('Y-m-d H:i:s'), // ADR-095 Фаза 2: старт TTL с момента создания
            'status'          => 'active',
        ];
        $claimedCellModel->save($newCampData);
        $insertId  = $claimedCellModel->getInsertID();
        $newBaseId = is_numeric($insertId) ? (int) $insertId : 0;

        // 5. Теперь проверяем, есть ли завершённая задача "BaseRelocation"
        //    c непустым task_settings (где хранится список построек).
        //    5.1) Ищем в таблице tasks строку name='BaseRelocation'
        $taskModel = new TaskModel();
        $relocationTask = $taskModel->where('name', 'BaseRelocation')->first();

        // Если нет такой записи в tasks, просто завершаем
        if (!$relocationTask) {
            // Сообщение об успехе (без восстановления построек)
            return $this->sendCampCreatedMsg($chatId, $mapRow['coordinate_x'], $mapRow['coordinate_y'], $newBaseId);
        }

        $relocationTaskId = $relocationTask['id'];

        // 5.2) Ищем в character_tasks запись со status='completed' и task_id = $relocationTaskId
        $characterTaskModel = new CharacterTaskModel();
        $relocationRow = $characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $relocationTaskId)
            ->where('status', 'completed')
            ->where('task_settings !=', '') // чтобы не было пустой строки
            ->first();

        // Если не нашли, тоже завершаем
        if (!$relocationRow) {
            // Сообщение об успехе (без восстановления построек)
            return $this->sendCampCreatedMsg($chatId, $mapRow['coordinate_x'], $mapRow['coordinate_y'], $newBaseId);
        }

        // 6) Если мы тут, значит есть запись с task_settings
        $json = $relocationRow['task_settings'];
        $decoded = json_decode($json, true);
        if (empty($decoded['character_buildings']) || !is_array($decoded['character_buildings'])) {
            // Если нет массива построек — тоже завершаем
            return $this->sendCampCreatedMsg($chatId, $mapRow['coordinate_x'], $mapRow['coordinate_y'], $newBaseId);
        }

        $buildingsArray = $decoded['character_buildings'];

        // 7) Восстанавливаем постройки в character_buildings
        //    Подставим новый map_cell_id = $mapRow['id']
        //    (и всё остальное, что было сохранено)
        $characterBuildingModel = new CharacterBuildingModel();
        $buildingModel          = new BuildingModel();

        $restoredNames = []; // чтобы собрать список названий для финального сообщения

        foreach ($buildingsArray as $bRow) {
            // Перезапишем некоторые поля:
            $bRow['character_id'] = $character['id'];
            $bRow['map_cell_id']  = $mapRow['id'];
            $bRow['built_at']     = date('Y-m-d H:i:s');  // или можно оставить старое (на ваше усмотрение)

            // Защита от «лишних» полей, если модель не пропускает их
            // (depends on $allowedFields — можно подчищать):
            $safeData = [
                'character_id'                    => $bRow['character_id'] ?? $character['id'],
                'building_id'                     => $bRow['building_id']  ?? 0,
                'faction_id'                      => $bRow['faction_id']   ?? null,
                'map_cell_id'                     => $bRow['map_cell_id'],
                'amount'                          => $bRow['amount']       ?? 1,
                'character_level_during_construction' => $bRow['character_level_during_construction'] ?? $character['level'],
                'hp'                              => $bRow['hp']           ?? 100,
                'level'                           => $bRow['level']        ?? 1,
                'built_at'                        => $bRow['built_at'],
                'building_type'                   => $bRow['building_type'] ?? 'resource',
                'tax'                              => $bRow['tax']          ?? 0,
                'usage'                            => $bRow['usage']        ?? 'personal',
            ];

            // Вставляем новую запись
            $newId = $characterBuildingModel->insert($safeData);

            // Для удобства получим название здания (в русском)
            if ($safeData['building_id']) {
                $foundBuilding = $buildingModel->find($safeData['building_id']);
                if ($foundBuilding && !empty($foundBuilding['name_ru'])) {
                    $restoredNames[] = [
                        'name'  => $foundBuilding['name_ru'],
                        'level' => $safeData['level'],
                    ];
                }
            }
        }

        // 8) Отправляем сообщение, что база создана + здания восстановлены
        return $this->sendCampCreatedWithBuildingsMsg(
            $chatId,
            $mapRow['coordinate_x'],
            $mapRow['coordinate_y'],
            $restoredNames,
            $newBaseId
        );
    }

    /**
     * W-REG.2 (F8): текст и кнопки экрана «Лагерь разбит» — один источник для обоих вариантов.
     * Без кнопок экран был тупиком; ряд «🏠 База» · «🏗 Строить» ведёт дальше в обоих клиентах.
     *
     * @param list<array<string, mixed>>|null $restoredNames null — лагерь без переезда
     * @return array{text: string, reply_markup: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}}
     */
    public static function campCreatedMessage(int|string $coordX, int|string $coordY, ?array $restoredNames, int $baseId): array
    {
        $text = "Ты успешно разбил лагерь на клетке (X={$coordX}, Y={$coordY}).\n";

        if ($restoredNames === null) {
            $text .= 'Теперь это твоя база!';
        } else {
            $text .= "Все *сооружения* из переезда сохранены и восстановлены!\n\n";
            if ($restoredNames === []) {
                $text .= 'Похоже, что никаких зданий не было перенесено.';
            } else {
                $text .= "Перенесены здания:\n";
                foreach ($restoredNames as $b) {
                    $lvl   = is_scalar($b['level'] ?? null) ? (string) $b['level'] : '1';
                    $bName = is_scalar($b['name'] ?? null) ? (string) $b['name'] : '???';
                    $text .= "• {$bName}, уровень {$lvl}\n";
                }
            }
        }

        $text = rtrim($text) . "\n\nЧто дальше: «🏗 Строить» — Склад, Теплица и другие постройки; «🏠 База» — экран базы.";

        return [
            'text'         => $text,
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => '🏠 База', 'callback_data' => 'Base'],
                ['text' => '🏗 Строить', 'callback_data' => $baseId > 0 ? BaseCallbackSuffix::append('Build', $baseId) : 'Build'],
            ]]],
        ];
    }

    /**
     * Отправляет обычное сообщение: лагерь разбит без восстановления зданий.
     */
    private function sendCampCreatedMsg(int $chatId, int|string $coordX, int|string $coordY, int $baseId): ServerResponse
    {
        $msg = self::campCreatedMessage($coordX, $coordY, null, $baseId);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $msg['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $msg['reply_markup'],
        ]);
    }

    /**
     * Отправляет сообщение: лагерь разбит и здания восстановлены.
     * Выводим список построек (название + уровень).
     *
     * @param list<array<string, mixed>> $restoredNames
     */
    private function sendCampCreatedWithBuildingsMsg(
        int $chatId,
        int|string $coordX,
        int|string $coordY,
        array $restoredNames,
        int $baseId
    ): ServerResponse
    {
        $msg = self::campCreatedMessage($coordX, $coordY, $restoredNames, $baseId);

        // Путь к картинке (файл: public/uploads/telegram/camp/new_camp.png — см. Config\ImageRegistry «camp/new_camp»)
        $imagePath = base_url('uploads/telegram/camp/new_camp.png');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $msg['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $msg['reply_markup'],
        ]);
    }

}
