<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Models\CharacterModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\Endgame\EndgameProgressionService;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeApplier;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeValidator;
use Config\BuildingUpgrades;

/**
 * w2-n4-base-02 (ADR-190) — ядро апгрейда постройки без `chat_id`: превью (что нужно, хватает ли)
 * и применение. Из него рисуют бот (`Camp\Buildings\UpgradeBuildingAction`) и веб (`/play?view=base`).
 *
 * Постройка — тип `buildings.id` на базе: явный `baseId` перепроверяется {@see BuildingUpgradeValidator}
 * через `resolveForBase()`, без него — прежнее правило `BaseScopeResolver::resolve()`.
 *
 * Атомарность (ADR-181) — в {@see BuildingUpgradeApplier::apply()}: ресурсы пулом, золото условной записью,
 * уровень переводится только из текущего — два параллельных подтверждения дают +1 уровень и одну оплату,
 * апгрейд без золота не проходит. Хуки после апгрейда (очки фракции, квест «Урожай фермера») — здесь,
 * общие для обоих клиентов.
 *
 * @phpstan-type Requirements array{level: int, gold: int, resources: array<string, int>}
 * @phpstan-type Result array{ok: bool, code: string, message: string, missing: list<string>, next_level: int, building_id: int, name: string|null, name_en: string|null, current_level: int, level: int, requirements: Requirements, char_building: array<string, mixed>, character: array{level: mixed, gold: mixed}}
 */
final class BuildingUpgradeService
{
    public const PREVIEW      = 'preview';
    public const APPLIED      = 'applied';
    public const REFUSED      = 'refused';
    public const MISSING      = 'missing_resources';
    public const RACE         = 'race';
    public const NO_CHARACTER = 'no_character';

    public const TEXT_RACE = 'Ресурсы разошлись, пока ты подтверждал — проверь запас и попробуй ещё раз.';

    private BuildingUpgradeValidator $validator;
    private BuildingUpgradeApplier $applier;

    public function __construct(?BuildingUpgradeValidator $validator = null, ?BuildingUpgradeApplier $applier = null)
    {
        $this->validator = $validator ?? new BuildingUpgradeValidator();
        $this->applier   = $applier ?? new BuildingUpgradeApplier();
    }

    /**
     * Превью апгрейда. `preview` — можно подтверждать; `missing_resources` — список нехватки (`missing`,
     * `next_level`); `refused` — одна причина (`message`).
     *
     * @return Result
     */
    public function preview(int $characterId, ?int $baseId, int $buildingId): array
    {
        $character = $this->character($characterId);
        if ($character === []) {
            return self::result(self::NO_CHARACTER, $buildingId);
        }

        return $this->check($character, $baseId, $buildingId);
    }

    /**
     * Применить апгрейд: перепроверка (запас мог измениться после превью) → условная запись → хуки.
     * Успех — `applied` с `current_level`/`level`/`name`.
     *
     * @return Result
     */
    public function apply(int $characterId, ?int $baseId, int $buildingId): array
    {
        $character = $this->character($characterId);
        if ($character === []) {
            return self::result(self::NO_CHARACTER, $buildingId);
        }
        $check = $this->check($character, $baseId, $buildingId);
        if (! $check['ok']) {
            return $check;
        }

        try {
            $this->applier->apply($character, $check['char_building'], $check['level'], $check['requirements']);
        } catch (\RuntimeException|\CodeIgniter\Database\Exceptions\DatabaseException) {
            return ['ok' => false, 'code' => self::RACE, 'message' => self::TEXT_RACE] + $check;
        }

        $nameEn = $check['name_en'];
        if ($nameEn !== null) {
            $endgame = new EndgameProgressionService();
            $endgame->recordBuildingUpgrade($nameEn);
            // v0.51.118: Теплица до 3+ закрывает активный квест FarmersHarvest.
            if ($nameEn === 'Greenhouse' && $check['level'] >= 3) {
                $this->completeFarmersHarvest($characterId, $endgame);
            }
        }

        return ['ok' => true, 'code' => self::APPLIED] + $check;
    }

    /**
     * @param array<string, mixed> $character
     * @return Result
     */
    private function check(array $character, ?int $baseId, int $buildingId): array
    {
        $res = $this->validator->validate($character, $buildingId, config(BuildingUpgrades::class)->requirements, $baseId);
        if (($res['ok'] ?? false) !== true) {
            $missing = [];
            foreach ((array) ($res['missingResources'] ?? []) as $line) {
                $missing[] = is_scalar($line) ? (string) $line : '';
            }
            if ($missing !== []) {
                $out               = self::result(self::MISSING, $buildingId);
                $out['missing']    = $missing;
                $out['next_level'] = self::int($res['nextLevel'] ?? 0);

                return $out;
            }

            $out            = self::result(self::REFUSED, $buildingId);
            $out['message'] = is_string($res['error'] ?? null) ? $res['error'] : '';

            return $out;
        }

        $ctx    = self::arr($res['context'] ?? null);
        $info   = self::arr($ctx['buildingInfo'] ?? null);
        $nameEn = null;
        foreach (['name_eng', 'name_en'] as $k) {
            if (isset($info[$k]) && is_string($info[$k])) {
                $nameEn = $info[$k];
                break;
            }
        }
        $req       = self::arr($ctx['requirements'] ?? null);
        $resources = [];
        foreach (self::arr($req['resources'] ?? null) as $name => $qty) {
            $resources[$name] = self::int($qty);
        }

        $out                  = self::result(self::PREVIEW, $buildingId);
        $out['ok']            = true;
        $out['name']          = is_string($info['name_ru'] ?? null) ? $info['name_ru'] : null;
        $out['name_en']       = $nameEn;
        $out['current_level'] = self::int($ctx['currentLevel'] ?? 0);
        $out['level']         = self::int($ctx['nextLevel'] ?? 0);
        $out['requirements']  = ['level' => self::int($req['level'] ?? 0), 'gold' => self::int($req['gold'] ?? 0), 'resources' => $resources];
        $out['char_building'] = self::arr($ctx['charBuilding'] ?? null);
        $out['character']     = ['level' => $character['level'] ?? null, 'gold' => $character['gold'] ?? null];

        return $out;
    }

    /** @return Result */
    private static function result(string $code, int $buildingId): array
    {
        return [
            'ok' => false, 'code' => $code, 'message' => '', 'missing' => [], 'next_level' => 0, 'building_id' => $buildingId,
            'name' => null, 'name_en' => null, 'current_level' => 0, 'level' => 0,
            'requirements' => ['level' => 0, 'gold' => 0, 'resources' => []], 'char_building' => [],
            'character' => ['level' => null, 'gold' => null],
        ];
    }

    private function completeFarmersHarvest(int $characterId, EndgameProgressionService $endgame): void
    {
        $quest = self::arr((new QuestModel())->where('title_en', 'FarmersHarvest')->first());
        if ($quest === []) {
            return;
        }
        $steps = new QuestStepsModel();
        $step  = self::arr($steps->where('quest_id', self::int($quest['id'] ?? 0))->where('character_id', $characterId)->where('is_completed', 0)->first());
        if ($step === []) {
            return;
        }
        $steps->update(self::int($step['id'] ?? 0), ['is_completed' => 1]);

        $reward = self::int($quest['reward'] ?? 0);
        if ($reward > 0) {
            (new CharacterModel())->increaseGold($characterId, $reward);
        }
        $endgame->recordQuestCompletion($characterId);

        log_message('info', "[BuildingUpgradeService] Auto-completed FarmersHarvest for char_id={$characterId} (+{$reward} gold)");
    }

    /** @return array<string, mixed> */
    private function character(int $characterId): array
    {
        return self::arr((new CharacterModel())->find($characterId));
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $row): array
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        if (! is_array($row)) {
            return [];
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
