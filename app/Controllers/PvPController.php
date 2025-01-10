<?php

namespace App\Controllers;

use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use CodeIgniter\Controller;

class PvPController extends BaseController
{
    protected $characterModel;
    protected $biomeModel;
    protected $characterFactionModel;
    protected $factionModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->biomeModel = new BiomeModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->factionModel = new FactionModel();
    }

    // Страница с формой для начала боя
    public function index()
    {
        // Получение всех персонажей и биомов
        $characters = $this->characterModel->findAll();
        $biomes = $this->biomeModel->findAll();

        // Передача данных на фронтенд
        return view('front/pvp_form', [
            'characters' => $characters,
            'biomes' => $biomes
        ]);
    }

    // Обработка формы и начало боя
    public function startFight()
    {
        $character1_id = $this->request->getPost('character1');
        $character2_id = $this->request->getPost('character2');
        $biome_id = $this->request->getPost('biome');

        // Получение данных о персонажах и биоме
        $character1 = $this->characterModel->find($character1_id);
        $character2 = $this->characterModel->find($character2_id);
        $biome = $this->biomeModel->find($biome_id);

        // Получение фракций персонажей
        $character1['faction'] = $this->getCharacterFaction($character1['id']);
        $character2['faction'] = $this->getCharacterFaction($character2['id']);

        // Логика боя, расчет урона и логирование
        $fightLogs = $this->simulateFight($character1, $character2, $biome);

        // Возвращаем данные в формате JSON
        return $this->response->setJSON([
            'fightLogs' => $fightLogs
        ])->setHeader('X-CSRF-Hash', csrf_hash());
    }

    // Метод для получения фракции персонажа
    private function getCharacterFaction($characterId)
    {
        // Используем модель CharacterFactionModel для поиска фракции по character_id
        $characterFaction = $this->characterFactionModel->where('character_id', $characterId)->first();

        if (!$characterFaction) {
            // Если персонаж не состоит ни в одной фракции, возвращаем null
            return null;
        }

        // Получаем информацию о фракции с помощью FactionModel
        return $this->factionModel->find($characterFaction['faction_id']);
    }

    // Метод симуляции боя (пошаговый бой)
    private function simulateFight($character1, $character2, $biome)
    {
        $logs = [];
        $round = 1;

        // Определяем инициативу
        $attacker = $this->determineInitiative($character1, $character2);
        $defender = ($attacker['id'] == $character1['id']) ? $character2 : $character1;

        while ($attacker['health'] > 0 && $defender['health'] > 0) {
            $roundLog = [];
            $roundLog['round'] = $round;
            $roundLog['attacker'] = $attacker;
            $roundLog['defender'] = $defender;

            // Сохраняем усталость перед атакой
            $roundLog['attacker_tired_before'] = $attacker['tired'];
            $roundLog['defender_tired_before'] = $defender['tired'];

            // Атака
            $attackResult = $this->executeAttack($attacker, $defender, $biome);

            $roundLog['attack'] = $attackResult;

            // Обновляем здоровье защитника
            $defender['health'] = max(0, $defender['health'] - $attackResult['damage']);

            // Уменьшаем усталость атакующего
            $attacker['tired'] = max(0, $attacker['tired'] - $this->calculateTirednessDecrease($attacker, 'attack'));

            // Уменьшаем усталость защитника
            if ($attackResult['action'] === 'miss') {
                // Если защитник уклонился, уменьшаем усталость за уклонение
                $defender['tired'] = max(0, $defender['tired'] - $this->calculateTirednessDecrease($defender, 'dodge'));
            } else {
                // Если защитник получил урон, уменьшаем усталость за получение урона
                $defender['tired'] = max(0, $defender['tired'] - $this->calculateTirednessDecrease($defender, 'defense'));
            }

            // Сохраняем усталость после атаки
            $roundLog['attacker_tired_after'] = $attacker['tired'];
            $roundLog['defender_tired_after'] = $defender['tired'];

            // Проверяем, побежден ли защитник
            if ($defender['health'] <= 0) {
                $roundLog['result'] = "{$defender['name']} проиграл!";
                $logs[] = $roundLog;
                break;
            }

            // Меняем роли
            $temp = $attacker;
            $attacker = $defender;
            $defender = $temp;

            $logs[] = $roundLog;
            $round++;
        }

        return $logs;
    }

    // Метод для вычисления уменьшения усталости
    private function calculateTirednessDecrease($character, $action)
    {
        switch ($action) {
            case 'attack':
                return 5; // Уменьшение усталости при атаке
            case 'dodge':
                return 2; // Уменьшение усталости при уклонении
            case 'defense':
                return 3; // Уменьшение усталости при получении урона
            default:
                return 0;
        }
    }

    // Определение инициативы на основе ловкости и уровня
    private function determineInitiative($character1, $character2)
    {
        $initiative1 = $character1['agility'] + ($character1['level'] * 0.5);
        $initiative2 = $character2['agility'] + ($character2['level'] * 0.5);

        return ($initiative1 >= $initiative2) ? $character1 : $character2;
    }

    // Выполнение атаки и расчет урона
    // Выполнение атаки и расчет урона
    private function executeAttack($attacker, $defender, $biome)
    {
        $log = [];

        // Расчет модификаторов
        $levelCoefficient = 1 + ($attacker['level'] / 1000);
        $tiredModifier = $attacker['tired'] / 100;

        // Уровневой модификатор
        $levelModifier = (1000 / $attacker['level']) * 0.25;

        // Базовый урон с учетом уровневого модификатора
        $baseDamage = ($attacker['strength'] * 0.5) * $levelCoefficient * $tiredModifier * $levelModifier;

        // Шанс критического удара
        $critChance = ($attacker['agility'] / 10) + ($attacker['level'] / 100);
        $critRoll = mt_rand(0, 100);
        $isCrit = ($critRoll <= $critChance);
        $critMultiplier = $isCrit ? 2 : 1;

        // Модификатор биома
        $biomeModifier = 1 + (($biome['danger_level'] - 5) * 0.02);

        // Модификатор фракции
        $factionModifier = 1;
        if ($attacker['faction']) {
            // Пример: добавляем бонус к урону в зависимости от фракции
            $factionModifier = ($attacker['faction']['name'] == 'Militia') ? 1.1 : 1.0;
        }

        // Итоговый урон до защиты
        $totalDamage = $baseDamage * $critMultiplier * $biomeModifier * $factionModifier;

        // Модификатор усталости защитника
        $defenderTiredModifier = $defender['tired'] / 100;

        // Защита противника с учётом усталости
        $defense = ($defender['agility'] * 0.3) * $defenderTiredModifier;

        // Шанс уклонения с учётом усталости
        $dodgeChance = ($defender['agility'] / 10) * $defenderTiredModifier;
        $dodgeRoll = mt_rand(0, 100);
        $isDodge = ($dodgeRoll <= $dodgeChance);

        $damageDealt = 0;
        if ($isDodge) {
            $log['action'] = 'miss';
            $damageDealt = 0;
        } else {
            $damageDealt = max(0, $totalDamage - $defense);
            $log['action'] = 'hit';
        }

        // Логирование подробностей
        $log['calculations'] = [
            'levelCoefficient' => $levelCoefficient,
            'tiredModifier' => $tiredModifier,
            'levelModifier' => $levelModifier,
            'baseDamage' => $baseDamage,
            'critChance' => $critChance,
            'critRoll' => $critRoll,
            'isCrit' => $isCrit,
            'critMultiplier' => $critMultiplier,
            'biomeModifier' => $biomeModifier,
            'factionModifier' => $factionModifier,
            'totalDamage' => $totalDamage,
            'defenderTiredModifier' => $defenderTiredModifier,
            'defense' => $defense,
            'dodgeChance' => $dodgeChance,
            'dodgeRoll' => $dodgeRoll,
            'isDodge' => $isDodge,
            'damageDealt' => $damageDealt,
        ];

        $log['damage'] = $damageDealt;

        return $log;
    }

}
