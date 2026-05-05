<?php

namespace app\Services\Player;

use App\Models\NpcModel;
use App\Models\CharacterModel;
use App\Models\BattleLogModel;
use App\Services\Player\DeathService;
use App\Services\NotificationService;


class NpcCombatService
{
    protected $deathService;
    protected $notificationService;

    public function __construct(DeathService $deathService, NotificationService $notificationService)
    {
        $this->deathService = $deathService;
        $this->notificationService = $notificationService;
    }

    /**
     * Запуск боя между NPC и игроком.
     *
     * @param NPC $npc     Экземпляр NPC (содержит характеристики и поведение).
     * @param Player $player Экземпляр игрока (содержит здоровье и прочие параметры).
     * @return void
     */
    public function startCombat(NPC $npc, Player $player): void
    {
        // Инициализация начальных значений здоровья
        $npcHealth = $npc->health;
        $playerHealth = $player->health;
        $npcFled = false;  // флаг, что NPC убежал

        // Определяем, кто атакует первым в зависимости от поведения NPC
        $npcTurn = ($npc->ai_behavior === 'aggressive');  // true, если NPC агрессивен и начинает первым

        // Логируем начало боя
        $this->logEvent($npc, $player, "Бой начат: {$npc->name} vs {$player->name}", 'start');

        // Основной цикл боя
        while ($npcHealth > 0 && $playerHealth > 0) {
            if ($npcTurn) {
                // Ход NPC: NPC атакует игрока (если NPC не сбежал ранее)
                if (!$npcFled) {
                    $damage = $this->calculateDamage($npc);
                    // Проверяем промах на основе ловкости или случайного шанса
                    if ($this->isMiss($npc)) {
                        // NPC промахнулся
                        $this->logEvent($npc, $player, "{$npc->name} промахнулся по {$player->name}", 'miss');
                    } else {
                        // Рассчитываем окончательный урон с учетом брони игрока (если есть)
                        $damageDealt = $damage - ($player->armor ?? 0);
                        if ($damageDealt < 0) {
                            $damageDealt = 0;
                        }
                        $playerHealth -= $damageDealt;
                        $this->logEvent($npc, $player, "{$npc->name} атакует {$player->name} и наносит {$damageDealt} урона", 'hit');
                        // Проверяем, убит ли игрок этой атакой
                        if ($playerHealth <= 0) {
                            $playerHealth = 0;
                            $this->logEvent($npc, $player, "{$player->name} погиб от рук {$npc->name}", 'player_dead');
                            break;
                        }
                    }
                }
                // После хода NPC, следующий ход за игроком (если бой не окончен)
                $npcTurn = false;
            } else {
                // Ход игрока: игрок атакует NPC
                // Для простоты считаем, что урон игрока определяется некоторым методом Player::attackDamage()
                $playerDamage = $player->attackDamage();  // допустим, метод возвращает урон с учетом характеристик и оружия
                // Учитываем броню NPC при расчете полученного урона
                $damageDealt = $playerDamage - $npc->armor;
                if ($damageDealt < 0) {
                    $damageDealt = 0;
                }
                $npcHealth -= $damageDealt;
                $this->logEvent($player, $npc, "{$player->name} атакует {$npc->name} и наносит {$damageDealt} урона", 'hit');
                // Проверяем, убит ли NPC этой атакой
                if ($npcHealth <= 0) {
                    $npcHealth = 0;
                    $this->logEvent($player, $npc, "{$npc->name} повержен {$player->name}", 'npc_dead');
                    break;
                }
                // Если здоровье NPC опустилось ниже 25%, есть шанс, что он попытается убежать
                if ($npcHealth > 0 && $npcHealth / $npc->max_health < 0.25) {
                    if (mt_rand(1, 100) <= 25) {  // 25% шанс на побег
                        $npcFled = true;
                        $this->logEvent($npc, $player, "{$npc->name} пытается убежать с поля боя!", 'flee');
                        break;
                    }
                }
                // После хода игрока, следующий ход за NPC
                $npcTurn = true;
            }
        }

        // Завершение боя: определяем исход и выполняем соответствующие действия
        if ($playerHealth <= 0) {
            // Игрок проиграл бой
            $this->deathService->handlePlayerDeath($player);
            // Отправляем уведомление игроку о поражении
            $this->notificationService->send($player, "Вы проиграли битву против {$npc->name}.");
        } elseif ($npcFled) {
            // NPC сбежал — бой заканчивается без победителя
            // Отправляем уведомление игроку, что NPC убежал
            $this->notificationService->send($player, "{$npc->name} сбежал, бой завершен.");
        } elseif ($npcHealth <= 0) {
            // NPC погиб от рук игрока
            // Побежденный NPC не дает награды, можно явно указать это, если требуется:
            // (например, не вызываем сервис наград или не начисляем опыт)
            $this->notificationService->send($player, "Вы победили {$npc->name}, но не получили награды.");
        }

        // Логируем окончание боя
        $this->logEvent($npc, $player, "Бой между {$npc->name} и {$player->name} завершен", 'end');
    }

    /**
     * Расчет базового урона NPC с учетом его характеристик.
     * Здесь мы используем strength, agility, intellect и damage_value для вычисления урона.
     *
     * @param NPC $npc
     * @return int рассчитанный урон
     */
    protected function calculateDamage(NPC $npc): int
    {
        // Базовый урон от значения damage_value
        $damage = $npc->damage_value;

        // Усиление урона за счет силы (например, +0.5% за каждую единицу силы)
        $damage += $npc->strength * 0.5;

        // Интеллект может влиять на урон (если NPC магического типа, но в простой реализации можно игнорировать или добавить небольшой эффект)
        $damage += $npc->intellect * 0.2;

        // Вводим разброс урона +/- 10% для непредсказуемости
        $minDamage = $damage * 0.9;
        $maxDamage = $damage * 1.1;
        $damage = (int) round(mt_rand($minDamage, $maxDamage));

        return $damage;
    }

    /**
     * Определение промаха NPC.
     * Можно учитывать ловкость NPC или просто случайность.
     *
     * @param NPC $npc
     * @return bool true, если атака NPC промахнулась
     */
    protected function isMiss(NPC $npc): bool
    {
        // Базовый шанс промаха, увеличивающийся при низкой ловкости NPC.
        // Например, при среднем значении ловкости шанс промаха 10%,
        // при очень низкой ловкости шанс промаха выше.
        $baseMissChance = 10; // 10% базово
        if ($npc->agility < 5) {
            $baseMissChance += 10; // мало ловкости -> промахи чаще
        }
        // Ограничиваем общий шанс промаха максимум 50%
        $missChance = min(50, $baseMissChance);
        // Возвращаем true с вероятностью $missChance%
        return mt_rand(1, 100) <= $missChance;
    }

    /**
     * Логирование события боя в базу данных (таблицу battle_logs).
     *
     * @param mixed $attacker Объект атакующего (может быть NPC или Player).
     * @param mixed $target Объект цели (NPC или Player).
     * @param string $description Описание события.
     * @param string $eventType Тип события (например: start, hit, miss, player_dead, npc_dead, flee, end).
     * @return void
     */
    protected function logEvent($attacker, $target, string $description, string $eventType): void
    {
        // Определяем типы участников для логов (Player или NPC)
        $attackerType = ($attacker instanceof NPC) ? 'NPC' : 'Player';
        $targetType   = ($target instanceof NPC)   ? 'NPC' : 'Player';

        $attackerId = is_object($attacker) && isset($attacker->id) ? $attacker->id : null;
        $targetId   = is_object($target)   && isset($target->id)   ? $target->id   : null;

        // Создаем запись лога боя
        BattleLog::create([
            'battle_type'   => 'PVE',                 // тип боя (PVE - игрок против окружения)
            'attacker_id'   => $attackerId,           // ID атакующего (если есть)
            'attacker_type' => $attackerType,
            'target_id'     => $targetId,             // ID цели (если есть)
            'target_type'   => $targetType,
            'event_type'    => $eventType,
            'description'   => $description,
            'created_at'    => now(),
        ]);
    }
}