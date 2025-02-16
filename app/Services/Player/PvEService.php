<?php

namespace App\Services\Player;

use App\Services\PVE\BattleService;
use App\Services\PVE\RewardService;
use App\Services\PVE\EquipmentService;
use App\Models\CharacterModel;
use App\Models\NpcSpawnModel;
use App\Models\MapModel;
use App\Models\NpcModel;
use App\Entities\CharacterEntity;
use Psr\Log\LoggerInterface;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;

class PvEService
{
    private BattleService $battleService;
    private RewardService $rewardService;
    private EquipmentService $equipmentService;
    private LoggerInterface $logger;
    private CharacterModel $characterModel;
    private NpcSpawnModel $npcSpawnModel;
    private NpcModel $npcModel;
    private MapModel $mapModel;

    public function __construct(
        BattleService $battleService,
        RewardService $rewardService,
        EquipmentService $equipmentService,
        LoggerInterface $logger,
        CharacterModel $characterModel,
        NpcSpawnModel $npcSpawnModel,
        NpcModel $npcModel,
        MapModel $mapModel
    ) {
        $this->battleService    = $battleService;
        $this->rewardService    = $rewardService;
        $this->equipmentService = $equipmentService;
        $this->logger           = $logger;
        $this->characterModel   = $characterModel;
        $this->npcSpawnModel    = $npcSpawnModel;
        $this->npcModel         = $npcModel;
        $this->mapModel         = $mapModel;
    }

    public function attack(array $playerData, array $npcData, string $biome): array
    {
        log_message('debug', "Атака: Игрок {$playerData['name']} против NPC ID={$npcData['npc_id']}");

        $npcModel = new \App\Models\NpcModel();
        $npcRecord = $npcModel->find($npcData['npc_id']);

        if (!$npcRecord) {
            log_message('error', "Ошибка: NPC ID {$npcData['npc_id']} не найден в npcs!");
            return ['error' => "NPC ID {$npcData['npc_id']} не найден"];
        }

        $npcData['name'] = $npcRecord['npc_name_ru'] ?? 'Неизвестный враг';

        $player = new CharacterEntity($playerData);
        $npc = new CharacterEntity($npcData);

        $this->equipmentService->applyEquipmentBonuses($player);

        $fightResult = $this->battleService->startFight($player, $npc, $biome);

        log_message('debug', "Результаты боя: " . json_encode($fightResult));

        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "Ошибка: Победитель боя — строка, а не объект!");
            return ['message' => "Ошибка в логике боя."];
        }

        $rewards = $this->rewardService->grantRewards($fightResult['winner'], $fightResult['loser']);

        log_message('info', "Игрок {$player->name} получил: +{$rewards['exp']} опыта, +{$rewards['gold']} золота");

        // 🔹 Обновляем БД перед формированием сообщения
        $this->characterModel->update($player->id, [
            'health'     => max(1, $player->health),
            'tired'      => max(1, rand(1, (int) floor($player->health))),
            'experience' => $player->experience + ($rewards['exp'] ?? 0),
            'gold'       => $player->gold + ($rewards['gold'] ?? 0),
        ]);

        // 🔹 Получаем обновлённые данные из БД
        $updatedPlayerData = $this->characterModel->find($player->id);

        // 🔹 Лог перед вызовом `buildFightResultMessage()`
        log_message('debug', "Передаём обновлённые данные игрока в buildFightResultMessage: tired={$updatedPlayerData['tired']}");

        // 🔹 Формируем сообщение
        $mapLocation = ['coordinate_x' => $player->cell_number % 1000, 'coordinate_y' => floor($player->cell_number / 1000)];
        $finalText = $this->buildFightResultMessage($updatedPlayerData, $npcData['name'], $fightResult, $mapLocation);

        // 🔹 Лог перед отправкой в Telegram
        log_message('debug', "Вызываем `sendTelegramNotification()` для игрока {$playerData['name']}");

        // 🔹 Отправляем уведомление
        $this->sendTelegramNotification($playerData, $finalText);

        return [
            'message' => "Бой завершён! Победитель: " . ($fightResult['winner']->name ?? "Ничья"),
            'rewards' => $rewards,
            'log'     => $fightResult['log'],
            'winner'  => $fightResult['winner'],
            'player'  => $updatedPlayerData, // 🔹 Теперь возвращаем обновленные данные
        ];
    }

    /**
     * Формируем итоговое сообщение с использованием HTML и небольшого "лорного" описания.
     */
    private function buildFightResultMessage(array $playerData, string $npcName, array $fightResult, array $mapLocation): string
    {
        $winner = $fightResult['winner'] ?? null;
        $loser = $fightResult['loser'] ?? null;
        $rewards = $fightResult['rewards'] ?? ['exp' => 0, 'gold' => 0];

        $winnerName = $winner ? $winner->name : '???';
        $loserName = $loser ? $loser->name : '???';
        $rounds = $fightResult['rounds'] ?? 0;
        $firstAttacker = $fightResult['firstAttacker'] ?? 'Неизвестен';
        $x = $mapLocation['coordinate_x'] ?? '???';
        $y = $mapLocation['coordinate_y'] ?? '???';

        // 🔹 Логируем перед формированием сообщения
        log_message('debug', "Выносливость перед формированием сообщения: tired={$playerData['tired']}");

        // 🔹 Лорная предыстория
        $loreText = "Долгие странствия привели тебя в этот зловещий край.\n"
            . "И на пути ты встретил: <b>{$npcName}</b>\n"
            . "Не имея времени, ты вынужден сражаться!\n\n";

        // 🔹 Сводка боя
        $battleText  = "<u>Сводка боя:</u>\n";
        $battleText .= "• <b>Раундов:</b> {$rounds} ⚔️\n";
        $battleText .= "• <b>Первым атаковал:</b> {$firstAttacker} 🎯\n";
        $battleText .= "• <b>Проигравший:</b> {$loserName} 💀\n";
        $battleText .= "• <b>Победитель:</b> {$winnerName} 🏆\n";
        $battleText .= "• <b>Координаты:</b> X={$x}, Y={$y}\n\n";

        // 🔹 Итог боя
        $resultText  = "<b>Итог боя:</b>\n";
        $resultText .= "В ожесточённой схватке <b>{$loserName}</b> пал, "
            . "а <b>{$winnerName}</b> одержал верх!\n\n"
            . "⚔️ <i>Ты проявил отвагу, {$playerData['name']}!</i>\n\n";

        // 🔹 Сколько осталось здоровья и выносливости (используем `playerData` после обновления БД)
        $healthLeft = number_format($playerData['health'], 2, '.', '');
        $tiredLeft  = number_format($playerData['tired'], 2, '.', ''); // 🔹 Используем `tired` из `playerData`
        $resultText .= "У тебя осталось: 💖 <b>Здоровье: {$healthLeft}</b>, "
            . "🥱 <b>Выносливость: {$tiredLeft}</b>\n\n";

        // 🔹 Награды
        $rewardText = "🎖 <b>Награды за победу:</b>\n";
        $rewardText .= "• ✨ Опыт: <b>+{$rewards['exp']}</b>\n";
        $rewardText .= "• 💰 Золото: <b>+{$rewards['gold']}</b>\n\n";

        return "🤖 <b>PvE-бой завершён!</b>\n\n" . $loreText . $battleText . $resultText . $rewardText;
    }

    /**
     * Отправка уведомления в Telegram (HTML-форматирование).
     */
    private function sendTelegramNotification(array $playerData, string $finalText): void
    {
        log_message('debug', "Пытаемся отправить сообщение в Telegram для {$playerData['name']}");

        if (empty($playerData['telegram_user_id'])) {
            log_message('error', "Ошибка: `telegram_user_id` отсутствует для игрока {$playerData['name']}");
            return;
        }

        $tgUserModel = new \App\Models\TelegramUserModel();
        $tgUser = $tgUserModel->find($playerData['telegram_user_id']);

        if (!$tgUser) {
            log_message('error', "Ошибка: Telegram-пользователь не найден в БД для ID={$playerData['telegram_user_id']}");
            return;
        }

        if (empty($tgUser['telegram_id'])) {
            log_message('error', "Ошибка: `telegram_id` отсутствует для пользователя {$tgUser['username']}");
            return;
        }

        log_message('debug', "Отправка сообщения в Telegram: chat_id={$tgUser['telegram_id']}");

        // 🔹 Проверяем длину сообщения (если Telegram блокирует слишком длинные)
        if (strlen($finalText) > 4000) {
            log_message('warning', "Сообщение слишком длинное для Telegram! Обрезаем.");
            $finalText = substr($finalText, 0, 4000) . "...";
        }

        // 🔹 Отправляем сообщение через Telegram API
        $result = \Longman\TelegramBot\Request::sendMessage([
            'chat_id'    => $tgUser['telegram_id'],
            'text'       => $finalText,
            'parse_mode' => 'HTML',
        ]);

        // 🔹 Логируем ответ от Telegram API
        if (!$result->isOk()) {
            log_message('error', "Ошибка отправки сообщения в Telegram: " . $result->getDescription());
        } else {
            log_message('info', "Сообщение успешно отправлено в Telegram пользователю ID={$tgUser['telegram_id']}");
        }
    }
}
