<?php

namespace App\Services\World;

use App\Models\MapModel;
use App\Models\ExploredCellsModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\NpcSpawnModel;

/**
 * Текстовый рендерер экрана «Мир» бота: 12×12 карта вокруг игрока, легенда и строка расстояния
 * до базы.
 *
 * W2.N2-01 (ADR-190): клетки, маркеры и ближайшая база больше не считаются здесь — их отдаёт
 * модель {@see LiveMapService}, из которой рисует и `/play`. Этот сервис только превращает модель
 * в строки Telegram. Модели мира он по-прежнему держит у себя и передаёт в модель (тесты
 * подменяют их через reflection).
 */
class TextMapService
{
    protected MapModel $mapModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected CharacterModel $characterModel;
    protected ClaimedCellModel $claimedCellModel;
    protected NpcSpawnModel $npcSpawnModel;

    public function __construct()
    {
        $this->mapModel           = new MapModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->characterModel     = new CharacterModel();
        $this->claimedCellModel   = new ClaimedCellModel();
        $this->npcSpawnModel      = new NpcSpawnModel();
    }

    /** Модель карты поверх моделей этого сервиса. */
    public function liveMap(): LiveMapService
    {
        return new LiveMapService(
            $this->mapModel,
            $this->exploredCellsModel,
            $this->claimedCellModel,
            $this->npcSpawnModel
        );
    }

    /**
     * Генерация 12×12 карты (эмоджи) вокруг персонажа.
     *
     * @param array|\App\Entities\CharacterEntity $characterRow Информация о персонаже (из CharacterModel)
     * @return string Текстовая карта
     */
    public function buildMapOnly(array|\App\Entities\CharacterEntity $characterRow): string
    {
        return self::gridText($this->liveMap()->grid($characterRow));
    }

    /**
     * Строки карты из модели: значки клеток по рядам; модель с ошибкой — прежний текст ошибки.
     *
     * @param array{error:?string, cells:list<list<array{marker:string}>>} $grid
     */
    public static function gridText(array $grid): string
    {
        if ($grid['error'] !== null) {
            return $grid['error'];
        }
        $mapText = '';
        foreach ($grid['cells'] as $row) {
            foreach ($row as $cell) {
                $mapText .= $cell['marker'];
            }
            $mapText .= "\n";
        }

        return $mapText;
    }

    /**
     * Выводит легенду без карты.
     *
     * Если переданы координаты игрока — к каждому биому добавляется компас
     * ({@see BiomeCompassService}): сторона света до ближайшего скопления и порядок
     * расстояния. Это ответ на живой вопрос игрока «а где найти вулканы?» (22.07.2026):
     * раньше легенда объясняла, что значит значок, но не где искать сам биом.
     * Без координат / при выключенном killswitch легенда рендерится как раньше.
     */
    public function getLegend(?int $playerX = null, ?int $playerY = null): string
    {
        $hints = [];
        if ($playerX !== null && $playerY !== null) {
            $compass = new BiomeCompassService();
            if ($compass->enabled()) {
                $hints = $compass->hintsFor($playerX, $playerY);
            }
        }

        $text = "Легенда:\n";
        foreach (LiveMapService::MARKER_LEGEND as [$marker, $label]) {
            $text .= $marker . ' — ' . $label . "\n";
        }
        $text .= "\n";

        $n = 0;
        foreach (LiveMapService::BIOMES as $biomeId => [$emoji, $name]) {
            $n++;
            $text .= $n . ') ' . $emoji . ' — ' . $name;
            if (isset($hints[$biomeId]) && $hints[$biomeId] !== '') {
                $text .= ' · ' . $hints[$biomeId];
            }
            $text .= "\n";
        }

        if ($hints !== []) {
            $text .= "\n_Стороны света — от твоей клетки до ближайшего скопления биома. "
                . "Это компас, а не маршрут: точных координат он не даёт, идти и открывать карту всё равно тебе._\n";
        }

        return $text;
    }

    /**
     * Возвращает строку о расстоянии до базы, вида "От 🙎‍♂️ до 🏕 = N ходов."
     * Если базы нет — вернётся пустая строка. Ближайшая база — {@see LiveMapService::nearestBase()}.
     */
    public function getDistanceLine(array|\App\Entities\CharacterEntity $characterRow): string
    {
        return self::distanceText($this->liveMap()->nearestBase($characterRow));
    }

    /**
     * Строка «От 🙎‍♂️ до 🏕 = N ходов ↗️» из модели; базы нет — пустая строка.
     *
     * @param array{distance:int, arrow:string}|null $base
     */
    public static function distanceText(?array $base): string
    {
        return $base === null ? '' : "От 🙎‍♂️ до 🏕 = {$base['distance']} ходов {$base['arrow']}\n";
    }
}
