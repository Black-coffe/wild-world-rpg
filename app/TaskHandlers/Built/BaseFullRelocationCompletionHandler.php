<?php

namespace App\TaskHandlers\Built;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterBuildingModel;
use App\Models\TelegramUserModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\I18n\Time;

/**
 * Класс обрабатывает завершение Задачи "FullRelocation" (полноценный переезд) за 24 часа.
 * Вызывается воркером, когда end_time <= now().
 *
 * v0.51.22 (F2.9 batch-4): extends BaseTaskHandler (per F2.9 contract).
 * Bonus: PSR-4 namespace casing fixed (`app\` → `App\`).
 * Telegram lazy-init, Request::sendMessage/sendPhoto → safeSendMessage/safeSendPhoto.
 * `handle(array $taskRow)` → `handle(array $task = []): void`.
 */
class BaseFullRelocationCompletionHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $claimedCellModel;
    protected $characterBuildingModel;
    protected $telegramUserModel;
    protected $mapModel;
    protected $biomeModel;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
    }

    /**
     * Метод handle вызывается воркером, когда FullRelocation end_time підійшов.
     *
     * @param array<string,mixed> $task Запись из character_tasks (раніше параметр звався $taskRow).
     */
    public function handle(array $task = []): void
    {
        $taskRow = $task;
        $db = \Config\Database::connect();
        $db->reconnect();

        // 1) Проверяем наличие персонажа
        $characterId = $taskRow['character_id'] ?? null;
        if (!$characterId) {
            log_message('error', "BaseFullRelocationCompletionHandler: нет character_id в задаче ID {$taskRow['id']}.");
            return;
        }
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "BaseFullRelocationCompletionHandler: персонаж ID {$characterId} не найден.");
            return;
        }

        // 2) Проверяем, что задача действительно 'in_work'
        $rowInDb = $this->characterTaskModel
            ->where('id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if (!$rowInDb) {
            log_message('debug', "BaseFullRelocationCompletionHandler: Задача ID {$taskRow['id']} не найдена или не 'in_work'. Пропускаем.");
            return;
        }

        // 3) Проверяем, наступило ли end_time
        $now     = Time::now();
        $endTime = new Time($rowInDb['end_time'] ?? 'now');
        if ($endTime->isAfter($now)) {
            // Ещё не время
            return;
        }

        // 4) Читаем task_settings: там должен лежать "new_map_cell_id"
        $settings = json_decode($rowInDb['task_settings'] ?? '{}', true);
        $newMapCellId = $settings['new_map_cell_id'] ?? null;
        if (!$newMapCellId) {
            log_message('error', "BaseFullRelocationCompletionHandler: нет new_map_cell_id в task_settings задачи ID {$taskRow['id']}.");
            // Завершаем задачу, но без переноса
            $this->characterTaskModel->update($rowInDb['id'], ['status'=>'completed']);
            $this->notifyUser($character, "Переезд завершён, но new_map_cell_id не найден! База осталась на месте?", null);
            return;
        }

        // 5) Завершаем задачу (status='completed')
        $this->characterTaskModel->update($rowInDb['id'], ['status' => 'completed']);

        // -- (ВСТАВКА) Перенос персонажа в новую ячейку --
        $this->characterModel->update($characterId, ['cell_number' => $newMapCellId]);
        // ----------------------------------------------

        // 6) Ищем (и меняем) запись claimed_cells
        $oldClaimed = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->first();

        if ($oldClaimed) {
            // Меняем map_cell_id
            $this->claimedCellModel->update($oldClaimed['id'], [
                'map_cell_id' => $newMapCellId,
                'status'      => 'active',
                'claimed_at'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            // Если не нашли — создаём
            $this->claimedCellModel->insert([
                'character_id' => $characterId,
                'map_cell_id'  => $newMapCellId,
                'status'       => 'active',
                'claimed_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        // 7) Обновляем character_buildings: переносим на new_map_cell_id
        $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->set(['map_cell_id' => $newMapCellId])
            ->update();

        // 8) Получаем подробности о новой ячейке (для вывода игроку):
        //    - ищем в таблице map, затем biome
        $mapRow = $this->mapModel->where('cell_number', $newMapCellId)->first();
        if (!$mapRow) {
            // Если ячейка не найдена – логируем, но всё равно уведомим
            log_message('error', "BaseFullRelocationCompletionHandler: mapRow не найден для cell_number={$newMapCellId}");
            $this->notifyUser($character, "✅ Переезд завершён! Но локация {$newMapCellId} не найдена в карте.", null);
            return;
        }

        $biomeId = $mapRow['biome_id'] ?? null;
        $biomeRow = null;
        if ($biomeId) {
            $biomeRow = $this->biomeModel->find($biomeId);
        }

        // Составляем текст о новом биоме
        $biomeText = '';
        if ($biomeRow) {
            $biomeText = "\n*Биом*: {$biomeRow['name']} — {$biomeRow['description']}\n"
                . "Уровень опасности: {$biomeRow['danger_level']} / 10\n"
                . "Сложность выживания: {$biomeRow['survival_difficulty']} / 10";
        }

        $coordX = $mapRow['coordinate_x'] ?? '?';
        $coordY = $mapRow['coordinate_y'] ?? '?';

        // 9) Финальное уведомление
        $msg = "✅ *Полноценный переезд завершён!*\n\n"
            . "Твой персонаж и база успешно переместились в новую локацию:\n"
            . "• Координаты: X={$coordX}, Y={$coordY}\n"
            . "• Номер ячейки: {$newMapCellId}\n"
            . $biomeText . "\n\n"
            . "Следующий переезд будет доступен только через 10 дней!";

        // Отправляем игроку *фото* и текст
        $imagePath = base_url('uploads/telegram/camp/relocation_finish.jpg'); // Путь к картинке
        $this->notifyUser($character, $msg, $imagePath);
    }

    /**
     * Уведомляем игрока в Telegram
     *
     * @param array  $character Запись персонажа (из characterModel)
     * @param string $msg       Текст сообщения
     * @param string|null $photoPath Путь к картинке (если нужно отправить фото)
     */
    private function notifyUser(array|\App\Entities\CharacterEntity $character, string $msg, ?string $photoPath = null): void
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser || empty($telegramUser['telegram_id'])) {
            log_message('error', "BaseFullRelocationCompletionHandler: не найден telegram_id у персонажа {$character['id']}.");
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        if (!empty($photoPath)) {
            $this->safeSendPhoto($chatId, $photoPath, $msg, ['parse_mode' => 'Markdown']);
        } else {
            $this->safeSendMessage($chatId, $msg, ['parse_mode' => 'Markdown']);
        }
    }
}
