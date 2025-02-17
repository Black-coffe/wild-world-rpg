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

        // Находим запись NPC в таблице npcs
        $npcModel  = new \App\Models\NpcModel();
        $npcRecord = $npcModel->find($npcData['npc_id']);

        if (!$npcRecord) {
            log_message('error', "Ошибка: NPC ID {$npcData['npc_id']} не найден в npcs!");
            return ['error' => "NPC ID {$npcData['npc_id']} не найден"];
        }

        // Назначаем имя врага
        $npcData['name'] = $npcRecord['npc_name_ru'] ?? 'Неизвестный враг';

        // Оборачиваем игрока и противника в CharacterEntity
        $player = new CharacterEntity($playerData);
        $npc    = new CharacterEntity($npcData);

        // Применяем бонусы от экипировки
        $this->equipmentService->applyEquipmentBonuses($player);

        // Запускаем бой
        $fightResult = $this->battleService->startFight($player, $npc, $biome);
        log_message('debug', "Результаты боя: " . json_encode($fightResult));

        // Если победитель не объект (или не обнаружен), заканчиваем
        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "Ошибка: Победитель боя — строка, а не объект!");
            return ['message' => "Ошибка в логике боя."];
        }

        // Выдача наград
        $rewards = $this->rewardService->grantRewards($fightResult['winner'], $fightResult['loser']);
        log_message('info', "Игрок {$player->name} получил: +{$rewards['exp']} опыта, +{$rewards['gold']} золота, "
            . "Ресурс: " . ($rewards['resource'] ?? 'Нет') . ", Крафт: " . ($rewards['craftedItem'] ?? 'Нет'));

        // Обновляем здоровье, выносливость, опыт и золото после боя
        $this->characterModel->update($player->id, [
            'health'     => max(1, $player->health),
            'tired'      => max(1, rand(1, (int) floor($player->health))),
            'experience' => $player->experience + ($rewards['exp'] ?? 0),
            'gold'       => $player->gold + ($rewards['gold'] ?? 0),
        ]);

        // Получаем свежие данные из БД (т.к. модель уже могла поменять поля)
        $updatedPlayerData = $this->characterModel->find($player->id);

        // Формируем текст сообщения (HTML)
        $mapLocation = [
            'coordinate_x' => $player->cell_number % 1000,
            'coordinate_y' => floor($player->cell_number / 1000),
        ];
        $finalText = $this->buildFightResultMessage(
            $updatedPlayerData,
            $npcData['name'],
            $fightResult,
            $mapLocation,
            $rewards
        );

        // Отправляем уведомление в Telegram
        $this->sendTelegramNotification($updatedPlayerData, $finalText);

        // Возвращаем результат боя
        return [
            'message' => "Бой завершён! Победитель: " . ($fightResult['winner']->name ?? "Ничья"),
            'rewards' => $rewards,
            'log'     => $fightResult['log'],
            'winner'  => $fightResult['winner'],
            'player'  => $updatedPlayerData,
        ];
    }

    /**
     * Формирует итоговое сообщение о бое
     */
    private function buildFightResultMessage(
        array $playerData,
        string $npcName,
        array $fightResult,
        array $mapLocation,
        array $rewards
    ): string {
        $winner       = $fightResult['winner']       ?? null;
        $loser        = $fightResult['loser']        ?? null;
        $winnerName   = $winner ? $winner->name      : '???';
        $loserName    = $loser ? $loser->name        : '???';
        $rounds       = $fightResult['rounds']       ?? 0;
        $firstAttacker= $fightResult['firstAttacker']?? 'Неизвестен';
        $x            = $mapLocation['coordinate_x'] ?? '???';
        $y            = $mapLocation['coordinate_y'] ?? '???';

        // Признак, что NPC гораздо слабее (можно сравнивать уровень,
        // или «сумму статов», как хотите)
        $npcMuchWeaker = ($loser && $winner && $winner->level >= 2 * $loser->level);

        // --- Лорная предыстория
        $loreText = "Долгие странствия привели тебя в этот зловещий край.\n"
            . "И на пути ты встретил: <b>{$npcName}</b>\n"
            . "Не имея времени, ты вынужден сражаться!\n\n";

        // --- Сводка боя
        $battleText  = "<u>Сводка боя:</u>\n";
        $battleText .= "• <b>Раундов:</b> {$rounds} ⚔️\n";
        $battleText .= "• <b>Первым атаковал:</b> {$firstAttacker} 🎯\n";
        $battleText .= "• <b>Проигравший:</b> {$loserName} 💀\n";
        $battleText .= "• <b>Победитель:</b> {$winnerName} 🏆\n";
        $battleText .= "• <b>Координаты:</b> X={$x}, Y={$y}\n\n";

        // --- Итог боя
        $resultText  = "<b>Итог боя:</b>\n";
        $resultText .= "В ожесточённой схватке <b>{$loserName}</b> пал, "
            . "а <b>{$winnerName}</b> одержал верх!\n\n"
            . "⚔️ <i>Ты проявил отвагу, {$playerData['name']}!</i>\n\n";

        // --- Текущее здоровье/выносливость
        $currentHealth = number_format($playerData['health'], 2, '.', '');
        $currentTired  = number_format($playerData['tired'], 2, '.', '');
        $resultText .= "У тебя осталось: 💖 Здоровье: {$currentHealth}, 🥱 Выносливость: {$currentTired}\n\n";

        // --- Награды
        // Вместо жёстких строк — собираем динамически
        $rewardLines = [];
        if (!empty($rewards['exp'])) {
            // Если опыт > 0
            $rewardLines[] = "• ✨ Опыт: +{$rewards['exp']}";
        }
        if (!empty($rewards['gold'])) {
            // Если золото > 0
            $rewardLines[] = "• 💰 Золото: +{$rewards['gold']}";
        }
        if (!empty($rewards['resource'])) {
            $rewardLines[] = "• 📦 Ресурс: <b>{$rewards['resource']}</b>";
        }
        if (!empty($rewards['craftedItem'])) {
            $rewardLines[] = "• 🛠 Крафт-предмет: <b>{$rewards['craftedItem']}</b>";
        }

        // Если NPC был сильно слабее, и наград очень мало — пишем коммент
        // (или если у rewardLines вообще пусто)
        $extraComment = '';
        if ($npcMuchWeaker) {
            $extraComment = "Противник был в разы слабее, поэтому ты получил не так уж много наград.\n\n";
        }

        // Формируем блок наград:
        // Если rewardLines не пустой — выводим заголовок + сами строки
        // Если вообще нет наград, можно указать, что «Ты ничего не получил»,
        // либо просто оставить «Противник слишком слаб»
        $rewardText = '';
        if (!empty($rewardLines)) {
            $rewardText = "🎖 <b>Награды за победу:</b>\n" . implode("\n", $rewardLines) . "\n\n";
        } else {
            // Полная пустота (все нули) — можно выводить что-то вроде:
            $rewardText = "Противник был слишком слаб, ничего ценного не досталось.\n\n";
        }

        // Склеиваем всё
        return "🤖 <b>PvE-бой завершён!</b>\n\n"
            . $loreText
            . $battleText
            . $resultText
            . $extraComment
            . $rewardText;
    }

    /**
     * Отправка уведомления в Telegram (HTML-форматирование).
     */
    private function sendTelegramNotification(array $playerData, string $finalText): void
    {
        log_message('debug', "Пытаемся отправить сообщение в Telegram для {$playerData['name']}");

        // Предполагаем, что у персонажа в поле telegram_user_id хранится ID записи в telegram_users
        if (empty($playerData['telegram_user_id'])) {
            log_message('error', "Ошибка: `telegram_user_id` отсутствует у игрока {$playerData['name']}");
            return;
        }

        $tgUserModel = new TelegramUserModel();
        $tgUser      = $tgUserModel->find($playerData['telegram_user_id']);

        if (!$tgUser) {
            log_message('error', "Ошибка: Telegram-пользователь не найден (ID={$playerData['telegram_user_id']})");
            return;
        }

        if (empty($tgUser['telegram_id'])) {
            log_message('error', "Ошибка: `telegram_id` отсутствует для пользователя {$tgUser['username']}");
            return;
        }

        log_message('debug', "Отправка сообщения в Telegram: chat_id={$tgUser['telegram_id']}");

        // Telegram обычно ограничивает ~4096 символов, если нужно — обрезаем
        if (strlen($finalText) > 4000) {
            log_message('warning', "Сообщение слишком длинное для Telegram! Обрезаем до 4000 символов.");
            $finalText = substr($finalText, 0, 4000) . "...";
        }

        // Отправляем сообщение
        $result = Request::sendMessage([
            'chat_id'    => $tgUser['telegram_id'],
            'text'       => $finalText,
            'parse_mode' => 'HTML',
        ]);

        if (!$result->isOk()) {
            log_message('error', "Ошибка отправки сообщения в Telegram: " . $result->getDescription());
        } else {
            log_message('info', "Сообщение успешно отправлено в Telegram пользователю ID={$tgUser['telegram_id']}");
        }
    }
}
