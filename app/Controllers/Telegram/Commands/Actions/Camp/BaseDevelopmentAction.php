<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\DefenseStructureService;
use Config\Database;
use Config\GameBalance;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E18 (ADR-118) — витрина «🏗 Развитие базы». Callback `baseDevelopment`.
 *
 * Легибельность апгрейдов: аудит (BUILDING_BUFFS_AUDIT) + данные (38 зданий на L1, крутой обрыв)
 * показали — игроки НЕ качают постройки, т.к. не видят ценность уровня. Плато L4-10 уже починено
 * (ADR-042 интерполяция), но эффект невидим. Этот экран показывает по каждой постройке: уровень +
 * ТЕКУЩИЙ эффект + что даёт СЛЕДУЮЩИЙ уровень (реюз BuildingEffectsService::effectAtLevel).
 *
 * Read-only, аддитивный (не трогает основной экран базы), edit-in-place. Media-off самодостаточен.
 */
final class BaseDevelopmentAction extends BaseAction
{
    /** Здания с уровневым множителем эффекта: name_en → [param, kind(reduce|increase), label, icon]. */
    private const EFFECTS = [
        'Workshop'            => ['craft_time_multiplier',  'reduce',   'время крафта',        '🔧'],
        'BlastFurnace'        => ['craft_yield_multiplier', 'increase', 'выход плавки',        '🔥'],
        'Laboratory'          => ['craft_time_multiplier',  'reduce',   'время медицины',      '🥼'],
        'RoboticsWorkshop'    => ['craft_time_multiplier',  'reduce',   'время роботов',       '🤖'],
        'Greenhouse'          => ['harvest_yield_multiplier','increase','урожай',              '🌱'],
        'SolarStation'        => ['craft_time_multiplier',  'reduce',   'время электроники',   '☀️'],
        'TeleportationCenter' => ['teleport_cost_multiplier','reduce',  'цена телепорта',      '🌀'],
    ];

    /** Иконки прочих зданий (эффект-строки E18 Ф2 — не-множительные, см. nonMultiplierLine). */
    private const ICONS = [
        'HandPump' => '🚰', 'Gym' => '🥊', 'Warehouse' => '🏚️', 'Arsenal' => '⚔️',
        'CommunicationTower' => '📢', 'WoodenWall' => '🪵', 'BarbedFence' => '🌵', 'WatchTower' => '🗼',
    ];

    private BuildingEffectsService $effects;
    private DefenseStructureService $defense;
    private GameBalance $gb;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->effects = new BuildingEffectsService();
        $this->defense = new DefenseStructureService();
        $this->gb      = config(GameBalance::class);
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        // Активный уровень здания для эффектов = MAX(level) по типу (как resolveBuildingLevel).
        $rows = Database::connect()->table('character_buildings cb')
            ->select('b.name_en AS name_en, b.name_ru AS name_ru, MAX(cb.level) AS lvl')
            ->join('buildings b', 'b.id = cb.building_id', 'inner')
            ->where('cb.character_id', $charId)
            ->groupBy('b.id')
            ->get();
        $built = $rows === false ? [] : $rows->getResultArray();

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $this->buildText($built),
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🏗 К базе', 'callback_data' => 'construction']],
                [['text' => '◀️ Я', 'callback_data' => 'character']],
            ]]) ?: '{}',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $built
     */
    private function buildText(array $built): string
    {
        $text = "🏗 *Развитие базы*\n\n";
        if ($built === []) {
            return $text . "У тебя пока нет построек.\n\n_Разбей лагерь («База» → «🏕 Разбить лагерь») и строй: каждый уровень постройки усиливает её эффект._";
        }

        $sum = 0;
        $cnt = 0;
        foreach ($built as $b) {
            $lvl = is_numeric($b['lvl'] ?? null) ? (int) $b['lvl'] : 1;
            $sum += $lvl;
            $cnt++;
        }
        $avg = round($sum / $cnt, 1); // $cnt ≥ 1: пустой $built отсечён выше
        $text .= "Средний уровень построек: *{$avg}/10* ({$cnt} шт.)\n\n";

        foreach ($built as $b) {
            $nameEn = is_string($b['name_en'] ?? null) ? $b['name_en'] : '';
            $nameRu = is_string($b['name_ru'] ?? null) ? $b['name_ru'] : $nameEn;
            $lvl    = is_numeric($b['lvl'] ?? null) ? (int) $b['lvl'] : 1;
            $icon   = self::EFFECTS[$nameEn][3] ?? (self::ICONS[$nameEn] ?? '🏗');

            $text .= "{$icon} *{$nameRu}* — ур. *{$lvl}/10*\n";

            if (isset(self::EFFECTS[$nameEn])) {
                [$param, $kind, $label] = self::EFFECTS[$nameEn];
                $key  = strtolower($nameEn);
                $cur  = $this->fmtEffect($this->effects->effectAtLevel($key, $lvl, $param), $kind, $label);
                $text .= "    {$cur}";
                if ($lvl < 10) {
                    $next = $this->fmtEffect($this->effects->effectAtLevel($key, $lvl + 1, $param), $kind, $label);
                    $text .= "  →  ур.{$lvl}+1: {$next}";
                }
                $text .= "\n";
            } else {
                // E18 Ф2 — эффект-строки не-множительных зданий (production / оборона / радиус /
                // флэт-роль). Честно: только активные эффекты (ёмкость Склада dormant → не показываем).
                $nm = $this->nonMultiplierLine($nameEn, $lvl);
                if ($nm !== null) {
                    $text .= "    {$nm}\n";
                }
            }
        }

        $text .= "\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._";
        return $text;
    }

    /** Множитель → читаемый эффект: reduce (m<1) «−N% label», increase (m>1) «+N% label». */
    private function fmtEffect(float $mult, string $kind, string $label): string
    {
        if ($kind === 'reduce') {
            $pct = (int) round((1.0 - $mult) * 100);
            return $pct > 0 ? "−{$pct}% {$label}" : "базовый эффект";
        }
        $pct = (int) round(($mult - 1.0) * 100);
        return $pct > 0 ? "+{$pct}% {$label}" : "базовый эффект";
    }

    /**
     * E18 Ф2 — честная эффект-строка не-множительного здания на уровне (+ след. уровень, если
     * эффект растёт). Источники авторитетные: GameBalance (HandPump/Gym), DefenseStructureService
     * (оборона, реюз scaledInt/cap — без дублирования формулы), level×100 (радиус роботов).
     * Только АКТИВНЫЕ эффекты (ёмкость Склада за dormant weight-cap НЕ показываем).
     */
    private function nonMultiplierLine(string $nameEn, int $level): ?string
    {
        $lvl  = max(1, min(10, $level));
        $next = $lvl < 10 ? $lvl + 1 : null;

        switch ($nameEn) {
            case 'HandPump':
                $cur = $this->gb->handPumpLevels[$lvl] ?? 1;
                $s   = "≈{$cur} воды/мин (зависит от биома)";
                if ($next !== null) {
                    $s .= "  →  ур.{$lvl}+1: ≈" . ($this->gb->handPumpLevels[$next] ?? $cur);
                }
                return $s;

            case 'Gym':
                $cur = $this->gb->gymStrengthByLevel[$lvl] ?? 0.01;
                $s   = "+{$cur} силы / 30 мин";
                if ($next !== null) {
                    $s .= "  →  ур.{$lvl}+1: +" . ($this->gb->gymStrengthByLevel[$next] ?? $cur);
                }
                return $s;

            case 'CommunicationTower':
                $s = "радиус роботов: " . ($lvl * 100) . " клеток";
                if ($next !== null) {
                    $s .= "  →  ур.{$lvl}+1: " . ($next * 100) . " клеток";
                }
                return $s;

            case 'WoodenWall':
                $cap = $this->defense->totalReductionCapPercent();
                $cur = min($this->defense->scaledInt('defense.wall.damage_reduction_percent', 15, $lvl), $cap);
                $s   = "−{$cur}% урона по базе при рейде (макс {$cap}%)";
                if ($next !== null) {
                    $nx = min($this->defense->scaledInt('defense.wall.damage_reduction_percent', 15, $next), $cap);
                    $s .= "  →  ур.{$lvl}+1: −{$nx}%";
                }
                return $s;

            case 'BarbedFence':
                $cur = $this->defense->scaledInt('defense.fence.attacker_damage_per_round', 3, $lvl);
                $s   = "+{$cur} контрурона атакующему/раунд";
                if ($next !== null) {
                    $nx = $this->defense->scaledInt('defense.fence.attacker_damage_per_round', 3, $next);
                    $s .= "  →  ур.{$lvl}+1: +{$nx}";
                }
                return $s;

            case 'WatchTower':
                $cur = $this->defense->scaledInt('defense.tower.defender_initiative_bonus_percent', 8, $lvl);
                $s   = "+{$cur}% инициативы в обороне + алерт о подходе врага";
                if ($next !== null) {
                    $nx = $this->defense->scaledInt('defense.tower.defender_initiative_bonus_percent', 8, $next);
                    $s .= "  →  ур.{$lvl}+1: +{$nx}%";
                }
                return $s;

            case 'Warehouse':
                return "закрытый рынок (покупка крафта) + бонус к продаже (флэт)";

            case 'Arsenal':
                return "хранение и экипировка оружия и брони (флэт)";

            default:
                return null;
        }
    }
}
