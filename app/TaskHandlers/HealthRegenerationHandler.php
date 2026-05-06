<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\MapModel;
use Config\GameBalance;

/**
 * Класс HealthRegenerationHandler
 *
 * Предназначен для:
 * 1) Регулярной регенерации здоровья (health) и усталости (tired) у персонажей
 *    при условии, что их уровень ниже определённого порога (в данном случае < 20).
 * 2) Переопределения уровня персонажа на основе его характеристик (experience, strength, agility, intellect).
 * 3) Синхронизации biome_id персонажа с реальными данными из таблицы map:
 *    - Если у персонажа в поле cell_number другое значение, чем в таблице map.biome_id,
 *      класс исправляет поле biome_id у персонажа, приводя их в соответствие.
 *
 * Класс содержит следующий функционал:
 * - process(): основной метод, вызывающий логику регенерации и затем (через паузу в 3 секунды) верификацию биома.
 * - calculateLevel(): вспомогательный метод для определения уровня персонажа на основе его статов.
 * - verifyCharactersBiome(): метод, проверяющий, корректно ли установлено поле biome_id у персонажа согласно таблице map.
 */
class HealthRegenerationHandler
{
    /**
     * @var CharacterModel $characterModel
     * Модель работы с таблицей characters (хранит основных данных о персонаже).
     */
    protected $characterModel;

    /**
     * @var CharacterBuildingModel $characterBuildingModel
     * Модель, хранящая сведения о построенных зданиях персонажа (в данном примере не используется напрямую,
     * но инициализируется, чтобы при необходимости в будущем легко добавить логику, зависящую от построек).
     */
    protected $characterBuildingModel;

    /**
     * @var CharacterResourceModel $characterResourceModel
     * Модель, хранящая данные о ресурсах персонажа (аналогично — не задействована в текущем коде,
     * но может пригодиться в задачах, связанных с расходом или прибавкой ресурсов).
     */
    protected $characterResourceModel;

    /**
     * @var MapModel $mapModel
     * Модель карты (таблица map). Используется при верификации биома персонажа
     * по значению его cell_number.
     */
    protected $mapModel;

    private GameBalance $cfg;

    /**
     * Конструктор: создаёт экземпляры необходимых моделей,
     * чтобы методы класса могли работать с таблицами characters, map и пр.
     *
     * F2.10 wire-in: $cfg инжектируется опционально (для тестов), по умолчанию
     * читается из config('GameBalance') — централизованный balance config.
     */
    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg                    = $cfg ?? config('GameBalance');
        $this->characterModel         = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();

        // Используется при проверке соответствия cell_number → biome_id
        $this->mapModel = new MapModel();
    }

    /**
     * Основной метод, который запускается для регенерации здоровья и усталости.
     * Шаги внутри метода:
     * 1) Получаем список всех персонажей из таблицы characters.
     * 2) Для каждого персонажа пересчитываем уровень (calculateLevel()) и записываем его в БД.
     * 3) Если уровень >= 20, пропускаем дальнейшую регенерацию (допустим, персонаж «высокого» уровня).
     * 4) Если уровень < 20, восстанавливаем часть здоровья (health) и усталости (tired),
     *    но не выше 100 (max).
     * 5) После завершения цикла вызываем verifyCharactersBiome() для верификации bioma.
     *
     * В результате: низкоуровневые персонажи немного регенерируют показатели
     * каждые раз при запуске этого кода, а затем обновляют своё поле biome_id
     * в соответствии с данными из таблицы map.
     */
    public function process()
    {
        // Получаем всех персонажей
        $characters = $this->characterModel->findAll();

        foreach ($characters as $character) {

            // 1) Вычисляем или «переопределяем» уровень персонажа
            $level = $this->calculateLevel($character);

            // Сохраняем новый уровень, если он отличается от старого
            $this->characterModel->update($character['id'], ['level' => $level]);

            // 2) Если уровень >= regenLevelCap (20 default), то считаем, что больше регенерации не нужно
            if ($level >= $this->cfg->regenLevelCap) {
                continue;
            }

            // 3) Регенерация здоровья (healthRegenPerTick = 0.05 default)
            if ($character['health'] < 100.0) {
                $newHealth = min(100.0, $character['health'] + $this->cfg->healthRegenPerTick);
                $this->characterModel->update($character['id'], ['health' => $newHealth]);
            }

            // 4) Регенерация усталости (tiredRegenPerTick = 0.1 default)
            if ($character['tired'] < 100.0) {
                $newTired = min(100.0, $character['tired'] + $this->cfg->tiredRegenPerTick);
                $this->characterModel->update($character['id'], ['tired' => $newTired]);
            }
        }

        // Затем запускаем метод проверки соответствия biome_id
        $this->verifyCharactersBiome();
    }

    /**
     * Подсчёт уровня персонажа:
     * - берем сумму (experience + strength + agility + intellect),
     * - делим на 4, полученный результат округляем вниз (floor),
     * - минимальный уровень — 1 (если среднее < 1, оставляем 1).
     *
     * @param array|\App\Entities\CharacterEntity $character Строка персонажа из БД
     * @return int итоговое значение уровня
     */
    private function calculateLevel(array|\App\Entities\CharacterEntity $character): int
    {
        $total = $character['experience']
            + $character['strength']
            + $character['agility']
            + $character['intellect'];

        $average = $total / 4.0;
        $level   = floor($average);

        // Если уровень ушёл в 0 или отрицательно, ставим 1
        return max(1, (int)$level);
    }

    /**
     * Проверка соответствия biome_id у персонажей:
     * 1) Получаем всех персонажей заново (вдруг там есть изменения).
     * 2) Для каждого персонажа смотрим поле cell_number (номер ячейки).
     * 3) В таблице map ищем запись, где map.id = cellNumber
     *    (если у вас другая связь, может понадобиться where('cell_number', $cellNumber)->first()).
     * 4) Сравниваем map.biome_id и characters.biome_id:
     *    - Если не совпадает, обновляем персонажу поле biome_id.
     * 5) Таким образом, biome_id персонажа всегда будет актуальным
     *    в соответствии с данными таблицы map.
     */
    private function verifyCharactersBiome()
    {
        // Получаем всех персонажей (актуальный список)
        $allCharacters = $this->characterModel->findAll();

        foreach ($allCharacters as $char) {
            $charId       = $char['id'];
            $cellNumber   = $char['cell_number'];
            // Текущее значение биома у персонажа
            $currentBiome = (int)$char['biome_id'];

            // Если cell_number не задан, пропускаем
            if (!$cellNumber) {
                continue;
            }

            // Ищем запись в map, где map.id = cellNumber
            // Важно убедиться, что в БД map.id действительно соответствует characters.cell_number
            $mapRow = $this->mapModel->find($cellNumber);
            if (!$mapRow) {
                // Если нет такой ячейки в map, ничего не делаем
                continue;
            }

            $mapBiomeId = (int)$mapRow['biome_id'];

            // Если биомы отличаются
            if ($mapBiomeId !== $currentBiome) {
                // Обновляем персонажа
                $this->characterModel->update($charId, [
                    'biome_id' => $mapBiomeId
                ]);
            }
        }
    }
}
