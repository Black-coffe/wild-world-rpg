<?php

namespace App\Services\Player;

use App\Services\PVE\BattleService;
use App\Services\PVE\RewardService;
use App\Services\PVE\EquipmentService;
use App\Services\PVE\PveBattleLogService;
use App\Services\PVE\PveMessageFormatter;
use App\Models\CharacterModel;
use App\Models\NpcSpawnModel;
use App\Models\NpcModel;
use App\Models\MapModel;
use App\Entities\BattleCharacter;
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
    private PveMessageFormatter $messageFormatter;

    public function __construct(
        BattleService $battleService,
        RewardService $rewardService,
        EquipmentService $equipmentService,
        LoggerInterface $logger,
        CharacterModel $characterModel,
        NpcSpawnModel $npcSpawnModel,
        NpcModel $npcModel,
        MapModel $mapModel,
        ?PveMessageFormatter $messageFormatter = null
    ) {
        $this->battleService    = $battleService;
        $this->rewardService    = $rewardService;
        $this->equipmentService = $equipmentService;
        $this->logger           = $logger;
        $this->characterModel   = $characterModel;
        $this->npcSpawnModel    = $npcSpawnModel;
        $this->npcModel         = $npcModel;
        $this->mapModel         = $mapModel;
        $this->messageFormatter = $messageFormatter ?? new PveMessageFormatter();
    }

    /**
     * Выполняет PvE бой между персонажем и NPC.
     *
     * @param array $playerData Данные персонажа из таблицы characters.
     * @param array $npcData    Данные NPC из npc_spawns (идентификатор, координаты, текущее здоровье).
     * @param string $biome     Текущий биом.
     * @return array Итог боя, награды, подробный лог и обновленные данные персонажа.
     */
    public function attack(array $playerData, array $npcData, string $biome): array
    {
        log_message('debug', "Атака: Игрок {$playerData['name']} против NPC ID={$npcData['npc_id']}");

        // Получаем данные NPC-спавна (динамические данные, включая current_health)
        $npcSpawnData = $this->npcSpawnModel->find($npcData['id']);
        if (!$npcSpawnData) {
            log_message('error', "⛔ Ошибка: NPC спавн ID {$npcData['id']} не найден!");
            return ['error' => "NPC спавн ID {$npcData['id']} не найден"];
        }

        // Проверяем, что игрок и NPC находятся в одной клетке
        if ($playerData['cell_number'] !== $npcSpawnData['cell_number']) {
            log_message('warning', "⚠️ Игрок ID={$playerData['id']} и NPC спавн ID={$npcData['id']} в разных клетках! Бой не начинается.");
            return ['message' => "Игрок и NPC находятся в разных клетках"];
        }

        // Получаем статические параметры NPC из таблицы npcs
        $npcRecord = $this->npcModel->find($npcSpawnData['npc_id']);
        if (!$npcRecord) {
            log_message('error', "⛔ Ошибка: NPC ID {$npcSpawnData['npc_id']} не найден в npcs!");
            return ['error' => "NPC ID {$npcSpawnData['npc_id']} не найден"];
        }

        // Объединяем данные: статические параметры из npcs и динамические из npc_spawns
        $npcData = array_merge($npcRecord, $npcSpawnData);
        // Значение здоровья берём исключительно из npc_spawns
        $npcData['health'] = $npcSpawnData['current_health'];
        $npcData['name'] = $npcRecord['npc_name_ru'] ?? 'Неизвестный враг';

        // Оборачиваем данные игрока и NPC в объекты BattleCharacter
        $player = new BattleCharacter($playerData);
        $npc = new BattleCharacter($npcData);

        // Применяем бонусы от экипировки к игроку
        $this->equipmentService->applyEquipmentBonuses($player);

        // Запускаем бой через BattleService
        $fightResult = $this->battleService->startFight($player, $npc, $biome);
        log_message('debug', "Результаты боя: " . json_encode($fightResult));

        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "Ошибка: Победитель боя — строка, а не объект!");
            return ['message' => "Ошибка в логике боя."];
        }

        // Подробное логирование боя через PveBattleLogService
        $battleLogService = new PveBattleLogService();
        $battleLogService->init($playerData, $npcData);
        $roundNumber = 1;
        if (isset($fightResult['log']) && is_array($fightResult['log'])) {
            foreach ($fightResult['log'] as $round) {
                $battleLogService->addRoundLog(
                    $roundNumber,
                    $round['attacker'] ?? '',
                    $round['defender'] ?? '',
                    $round['base_damage'] ?? 0,
                    $round['level_difference'] ?? 0,
                    $round['strength_bonus'] ?? 0,
                    $round['agility_bonus'] ?? 0,
                    $round['total_armor'] ?? 0,
                    $round['armor_effect'] ?? 0,
                    $round['final_damage'] ?? 0,
                    $round['defender_health_after'] ?? 0,
                    $round['luckyStrike'] ?? false
                );
                $roundNumber++;
            }
        }
        $battleLogService->setOutcome([
            'type'     => 'normal',
            'winnerId' => (int)$fightResult['winner']->id,
            'loserId'  => (isset($fightResult['loser']) && is_object($fightResult['loser'])) ? (int)$fightResult['loser']->id : null,
        ]);
        $battleLogService->saveLog(
            'PVE',
            (int)$playerData['id'],
            isset($npcData['npc_id']) ? (int)$npcData['npc_id'] : null,
            (int)$fightResult['winner']->id
        );

        // Выдача наград через RewardService
        $rewards = $this->rewardService->grantRewards($fightResult['winner'], $fightResult['loser']);
        log_message('info', "Игрок {$player->name} получил: +{$rewards['exp']} опыта, +{$rewards['gold']} золота");

        // Обновляем данные игрока
        $this->characterModel->update($player->id, [
            'health'     => max(1, $player->health),
            'tired'      => max(1, rand(1, (int)floor($player->health))),
            'experience' => $player->experience + ($rewards['exp'] ?? 0),
            'gold'       => $player->gold + ($rewards['gold'] ?? 0),
        ]);
        $updatedPlayerData = $this->characterModel->find($player->id);

        // Формируем итоговое сообщение для Telegram
        $mapLocation = [
            'coordinate_x' => $player->cell_number % 1000,
            'coordinate_y' => floor($player->cell_number / 1000),
        ];
        $finalText = $this->messageFormatter->buildFightResultMessage(
            $updatedPlayerData,
            $npcData['name'],
            $fightResult,
            $mapLocation,
            $rewards
        );
        $this->sendTelegramNotification($updatedPlayerData, $finalText);

        return [
            'message' => "Бой завершён! Победитель: " . ($fightResult['winner']->name ?? "Ничья"),
            'rewards' => $rewards,
            'log'     => $fightResult['log'],
            'winner'  => $fightResult['winner'],
            'player'  => $updatedPlayerData,
        ];
    }

    /**
     * Отправляет уведомление в Telegram (HTML-форматирование).
     */
    private function sendTelegramNotification(array $playerData, string $finalText): void
    {
        log_message('debug', "Пытаемся отправить сообщение в Telegram для {$playerData['name']}");

        if (empty($playerData['telegram_user_id'])) {
            log_message('error', "Ошибка: `telegram_user_id` отсутствует у игрока {$playerData['name']}");
            return;
        }

        $tgUserModel = new TelegramUserModel();
        $tgUser = $tgUserModel->find($playerData['telegram_user_id']);

        if (!$tgUser) {
            log_message('error', "Ошибка: Telegram-пользователь не найден (ID={$playerData['telegram_user_id']})");
            return;
        }

        if (empty($tgUser['telegram_id'])) {
            log_message('error', "Ошибка: `telegram_id` отсутствует для пользователя {$tgUser['username']}");
            return;
        }

        log_message('debug', "Отправка сообщения в Telegram: chat_id={$tgUser['telegram_id']}");

        if (strlen($finalText) > 4000) {
            log_message('warning', "Сообщение слишком длинное для Telegram! Обрезаем до 4000 символов.");
            $finalText = substr($finalText, 0, 4000) . "...";
        }

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
