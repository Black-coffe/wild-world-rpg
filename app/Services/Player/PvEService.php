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
        // Загружаем данные NPC (для названия)
        $npcRecord = $this->npcModel->find($npcData['npc_id']);
        $npcName   = $npcRecord['npc_name_ru'] ?? 'Неизвестный враг';
        // Присваиваем NPC'шке то же имя, которое хотим видеть в отчёте
        $npcData['name'] = $npcName;

        // Создаём игровые сущности
        $player = new CharacterEntity($playerData);
        $npc    = new CharacterEntity($npcData);

        // Применяем экипировку перед боем
        $this->equipmentService->applyEquipmentBonuses($player);

        // Достаём данные о локации (для координат, если есть)
        $mapLocation = $this->mapModel
            ->where('cell_number', $player->cell_number)
            ->first() ?? [];

        // Запускаем бой
        $fightResult = $this->battleService->startFight($player, $npc, $biome);

        if (!isset($fightResult['winner'])) {
            $fightResult['winner']  = null;
            $fightResult['loser']   = null;
            $fightResult['message'] = "Бой завершён, но победителя нет (ничья).";
        }

        // После боя обновляем здоровье и усталость
        $player->health = max(1, $player->health);
        $player->tired  = max(1, rand(1, (int) floor($player->health)));

        // Начисляем награды победителю
        $rewards = [];
        if ($fightResult['winner']) {
            $rewards = $this->rewardService->grantRewards(
                $fightResult['winner'],
                $fightResult['loser']
            );
        }

        // Сохраняем новые параметры игрока
        $this->characterModel->update($player->id, [
            'health'     => $player->health,
            'tired'      => $player->tired,
            'experience' => $player->experience + ($rewards['exp'] ?? 0),
            'gold'       => $player->gold + ($rewards['gold'] ?? 0),
        ]);

        // Если игрок победил (убил NPC), отмечаем NPC как dead
        if (!empty($fightResult['winner']) && $fightResult['winner']->name === $player->name) {
            $this->npcSpawnModel->update($npc->id, ['status' => 'dead']);
        }

        // Формируем текст (HTML-разметку)
        $finalText = $this->buildFightResultMessage($player, $npcName, $fightResult, $mapLocation);

        // Отправляем уведомление в Telegram
        $this->sendTelegramNotification($playerData, $finalText);

        return [
            'message' => "Бой завершён! Победитель: " . ($fightResult['winner'] ? $fightResult['winner']->name : "Ничья"),
            'rewards' => $rewards,
            'log'     => $fightResult['log'],
            'winner'  => $fightResult['winner'] ? $fightResult['winner']->name : null,
        ];
    }

    /**
     * Формируем итоговое сообщение с использованием HTML и небольшого "лорного" описания.
     */
    private function buildFightResultMessage(
        CharacterEntity $player,
        string $npcName,
        array $fightResult,
        array $mapLocation
    ): string {
        $winner       = $fightResult['winner'] ?? null;
        $loser        = $fightResult['loser']  ?? null;
        $winnerName   = $winner ? $winner->name : '???';
        $loserName    = $loser  ? $loser->name  : '???';
        $rounds       = $fightResult['rounds'] ?? 0;
        // Вместо '???' тут используем нормальное имя:
        $firstAttacker= $fightResult['firstAttacker'] ?? 'Неизвестен';

        $x            = $mapLocation['coordinate_x'] ?? '???';
        $y            = $mapLocation['coordinate_y'] ?? '???';

        // Лор-предыстория
        $loreText = "<i>Долгие странствия привели тебя в этот зловещий край.</i>\n"
            . "<i>И на пути ты встретил:</i> <b>{$npcName}</b>\n"
            . "<i>Не имея времени, ты вынужден сражаться!</i>\n\n";

        // Добавим ещё немного эмодзи 🎯⚔️🛡️💀 для разнообразия
        $battleText  = "<u>Сводка боя:</u>\n";
        $battleText .= "• <b>Раундов:</b> {$rounds} ⚔️\n";
        $battleText .= "• <b>Первым атаковал:</b> {$firstAttacker} 🎯\n";
        $battleText .= "• <b>Проигравший:</b> {$loserName} 💀\n";
        $battleText .= "• <b>Победитель:</b> {$winnerName} 🏆\n";
        $battleText .= "• <b>Координаты:</b> X={$x}, Y={$y}\n\n";

        $resultText  = "<b>Итог боя:</b>\n";
        $resultText .= "В ожесточённой схватке <b>{$loserName}</b> пал, "
            . "а <b>{$winnerName}</b> одержал верх!\n\n"
            . "⚔️ <i>Ты проявил отвагу, {$player->name}!</i>";

        return "🤖 <b>PvE-бой завершён!</b>\n\n"
            . $loreText
            . $battleText
            . $resultText;
    }

    /**
     * Отправка уведомления в Telegram (HTML-форматирование).
     */
    private function sendTelegramNotification(array $playerData, string $finalText): void
    {
        // Проверяем, есть ли telegram_user_id
        if (empty($playerData['telegram_user_id'])) {
            return;
        }

        // Достаём запись из telegram_users
        $tgUserModel = new TelegramUserModel();
        $tgUser = $tgUserModel->find($playerData['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        if (empty($tgUser['telegram_id'])) {
            return;
        }

        // Пытаемся отправить сообщение
        $chatId = $tgUser['telegram_id'];
        $result = Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $finalText,
            'parse_mode' => 'HTML', // Используем HTML-разметку
        ]);
    }
}
