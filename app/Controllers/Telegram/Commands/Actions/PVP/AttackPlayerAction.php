<?php

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BattleLogModel;
use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use App\Models\WeaponModel;

use App\Services\Endgame\EndgameProgressionService;
use App\Services\Housing\BaseCampDecorService;
use App\Services\Player\DeathService;
use App\Services\Player\PvPRestrictionService;
use App\Services\PVE\DefenseStructureService;
use App\Services\PVE\PvpDamageCalculator;
use App\Services\PVE\PvpEquipmentRepository;
use App\Services\PVE\PvpFormulaService;
use App\Services\PVE\PvpActivityContextService;
use App\Services\PVE\PvpBattleLogBuilder;
use App\Services\PVE\PvpRewardOrchestrator;
use App\Services\PVE\PvpRoundOrchestrator;

use Config\GameBalance;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * Контроллер PvP-атаки.
 *
 * После F2.3b декомпозиции (v0.10.0–v0.14.1):
 *   - PvpFormulaService       — pure формулы (level/stats/dodge/range/rarity)
 *   - PvpEquipmentRepository  — DB reads (weapon / outfits / map / faction)
 *   - PvpDamageCalculator     — формула урона + biome coefficient
 *   - PvpRoundOrchestrator    — simulateFight loop + lucky strike + initiative
 *   - PvpRewardOrchestrator   — DB writes (reward / death / exhaustion)
 *
 * Все игровые константы — в `Config\GameBalance`.
 *
 * handle() — thin orchestrator:
 *   validate → fetch biome → simulateFight → branch (exhausted / death) →
 *   notify both sides via Telegram.
 */
class AttackPlayerAction extends BaseAction
{
    protected $characterModel;
    protected $mapModel;
    protected $biomeModel;
    protected $telegramUserModel;
    protected $battleLogModel;

    private PvpFormulaService $pvpFormulas;
    private PvpEquipmentRepository $equipmentRepo;
    private PvpDamageCalculator $damageCalc;
    private PvpRoundOrchestrator $roundOrchestrator;
    private PvpRewardOrchestrator $rewardOrchestrator;
    private GameBalance $cfg;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->cfg               = config('GameBalance');
        $this->characterModel    = new CharacterModel();
        $this->mapModel          = new MapModel();
        $this->biomeModel        = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->battleLogModel    = new BattleLogModel();

        // Локальные модели — нужны только для DI в сервисы.
        $charactersWeapons = new CharactersWeaponsModel();
        $weapons           = new WeaponModel();
        $charactersOutfits = new CharactersOutfitsModel();
        $outfits           = new OutfitModel();
        $characterFaction  = new CharacterFactionModel();
        $faction           = new FactionModel();
        $claimedCell       = new ClaimedCellModel();
        $exploredCells     = new ExploredCellsModel();

        $this->pvpFormulas   = new PvpFormulaService();
        $this->equipmentRepo = new PvpEquipmentRepository(
            $charactersWeapons,
            $weapons,
            $charactersOutfits,
            $outfits,
            $this->mapModel,
            $characterFaction,
            $faction
        );
        $this->damageCalc        = new PvpDamageCalculator($this->pvpFormulas, $this->equipmentRepo);
        $this->roundOrchestrator = new PvpRoundOrchestrator($this->damageCalc, $this->pvpFormulas);
        $this->rewardOrchestrator = new PvpRewardOrchestrator(
            $this->characterModel,
            $claimedCell,
            $exploredCells
        );
    }

    /**
     * Обработка PvP-атаки.
     */
    public function handle(): ServerResponse
    {
        $battleStartTime = date('Y-m-d H:i:s');

        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        $defenderId   = isset($parts[1]) ? (int) $parts[1] : 0;

        [$user, $attacker] = $this->getUserAndCharacter();
        if (!$user || !$attacker) {
            return $this->sendError("Не найден атакующий персонаж (вы).");
        }
        if ($defenderId <= 0) {
            return $this->sendError("Не указан ID цели атаки. Попробуйте ещё раз.");
        }

        $defender = $this->characterModel->find($defenderId);
        if (!$defender) {
            return $this->sendError("Цель (ID {$defenderId}) не найдена.");
        }
        if ($attacker['id'] === $defender['id']) {
            return $this->sendError("Нельзя атаковать самого себя!");
        }

        // v0.51.44 — anti-spam cooldown (Security-telegram §7).
        // Cache-based gate per attacker. Раннє повернення до DB queries
        // (PvPRestriction, MapModel, BiomeModel, simulateFight) — зменшує
        // навантаження від spammers, які жмуть кнопку 2-3× за секунду.
        $cooldownSec    = $this->cfg->pvpAttackCooldownSec;
        $cacheKey       = "pvp_attack_cd_{$attacker['id']}";
        $cache          = \Config\Services::cache();
        $lastAttackTime = $cache->get($cacheKey);
        if (is_int($lastAttackTime) && time() - $lastAttackTime < $cooldownSec) {
            $remaining = $cooldownSec - (time() - $lastAttackTime);
            return $this->sendError("Подождите {$remaining} сек. перед следующей атакой!");
        }
        $cache->save($cacheKey, time(), $cooldownSec);

        if (!$this->isCellsCloseEnough($attacker, $defender)) {
            return $this->sendError("Игрок слишком далеко. Атаковать можно только в одной или соседней ячейке!");
        }

        $pvpRestrictionService = new PvPRestrictionService();
        $check = $pvpRestrictionService->checkPvPAllowed($attacker, $defender);
        if (!$check['allowed']) {
            return $this->sendError("PvP недоступно: {$check['reason']}");
        }

        $mapRowAttacker = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (!$mapRowAttacker) {
            return $this->sendError("Не найдена локация (map) атакующего!");
        }
        $biome = $this->biomeModel->find($mapRowAttacker['biome_id']);
        if (!$biome) {
            return $this->sendError("Не найден биом для ячейки #{$mapRowAttacker['id']}.");
        }

        $attacker['faction'] = $this->equipmentRepo->getCharacterFaction((int) $attacker['id']);
        $defender['faction'] = $this->equipmentRepo->getCharacterFaction((int) $defender['id']);

        // Asana «PvP-контекст»: чем защитник (атакованная сторона) был занят в момент атаки.
        // Захватываем ДО симуляции/смерти — обработка смерти отменяет активные задачи.
        $defenderActivityPhrase = (new PvpActivityContextService())->activityPhraseFor(
            is_numeric($defender['id'] ?? null) ? (int) $defender['id'] : 0
        );

        // S26 (ADR-030): defensive structures защитника, если он стоит на своей
        // клетке со структурами (active hp>0). null → бой без защиты (как раньше).
        $defenseService = new DefenseStructureService();
        $defenseProfile = $defenseService->getDefenseProfile(
            (int) $defender['id'],
            (int) ($defender['cell_number'] ?? 0),
        );

        // F1.4 (Models→Entity): $attacker/$defender — CharacterEntity. simulateFight и
        // processMutualExhaustion строго типизированы `array` → нормализуем к plain array
        // (прод-инцидент 2026-06-14, 3× CRITICAL на поле-PvP «Атаковать»). RNG-fence цел:
        // toRawArray() даёт те же данные (включая выставленный выше 'faction'), движок не тронут.
        $attackerArr = self::toCharacterArray($attacker);
        $defenderArr = self::toCharacterArray($defender);

        // 1) Симуляция боя (с учётом защиты базы, если есть).
        $fightResult = $this->roundOrchestrator->simulateFight($attackerArr, $defenderArr, $biome, $defenseProfile);

        // S26: износ структур за отбитую атаку (decay), если защита применялась.
        if ($defenseProfile !== null) {
            $defenseService->applyDecay($defenseProfile['structure_ids']);
        }

        // Asana «Расширение логирования»: собираем обогащённый log_data (v2) СРАЗУ после боя —
        // ДО обработки смерти/респауна (она снимает экипировку и сбрасывает статы проигравшего).
        // health_after берётся из fightResult; экип/координаты — из БД (ещё боевое состояние).
        $battleLogData = (new PvpBattleLogBuilder())->build(
            $attacker,
            $defender,
            $fightResult,
            is_array($biome) ? (is_string($biome['name'] ?? null) ? $biome['name'] : null) : null
        );

        // 2) Текст итогов.
        $summaryText   = $this->formatShortFightResult($fightResult);
        $loser         = $fightResult['loser']  ?? null;
        $winner        = $fightResult['winner'] ?? null;
        $attackerName  = $attacker['name'];
        $defenderName  = $defender['name'];
        // W21: имя базы защитника для PvP-контекста (NULL если не задано / killswitch OFF).
        $defCampName = (new BaseCampDecorService())->getDefenderCampName(
            is_numeric($defender['id'] ?? null) ? (int) $defender['id'] : 0
        );
        $attackerIntro = '';
        $defenderIntro = '';

        if ($fightResult['type'] === 'exhausted') {
            $this->rewardOrchestrator->processMutualExhaustion($attackerArr, $defenderArr);
            $summaryText .= "\n\n<b>Оба бойца изнемогли</b> и решили прекратить схватку!\n"
                . "❤️ Здоровье и выносливость сброшены до 10.\n"
                . "🚶 <i>Они отступили, обдумывая ошибки...</i>";
            $attackerIntro = "Ты участвовал в битве, но оба упали без сил.";
            $defenderIntro = "Тебя атаковали, но сражение закончилось взаимным изнеможением.";
        } elseif ($loser !== null && $winner !== null) {
            $deathService   = new DeathService();
            $deathResult    = $deathService->handlePlayerDeathAndReward($loser['id'], $winner['id']);
            $penaltyPercent = (int) ($deathResult['penalty'] * 100);

            if ($penaltyPercent === 0) {
                $summaryText .= "\n\n❌ <b>{$loser['name']}</b> потерпел поражение, но страховка спасла от потери имущества!";
            } else {
                $loserBefore = $loser;
                $this->rewardOrchestrator->processDeathAndRespawn($loser);
                $loser         = $this->characterModel->find($loser['id']);
                $loserDiffText = $this->rewardOrchestrator->makeLoserDiffText($loserBefore);

                if ($deathResult['hasBase']) {
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> повержен и возродился...\n"
                        . $loserDiffText
                        . "\nТы потерял лишь <b>{$penaltyPercent}%</b> ресурсов, ведь база частично спасла запасы."
                        . "\nНо <b>враг забрал</b> эти <b>{$penaltyPercent}%</b>!";
                } else {
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> проиграл бой и был возрождён...\n"
                        . $loserDiffText
                        . "\nТы оказался <b>без базы</b>, так что потерял <b>50%</b> ресурсов/крафта/золота: "
                        . "<i>половина исчезла бесследно, половина (25%) досталась врагу.</i>";
                }
            }

            $winnerBefore = $winner;
            $this->rewardOrchestrator->giveWinnerBonus($winner['id']);
            $winner         = $this->characterModel->find($winner['id']);
            $winnerDiffText = $this->rewardOrchestrator->makeWinnerDiffText($winnerBefore);
            $summaryText   .= "\n\n🏆 <b>{$winner['name']}</b> торжествует! {$winnerDiffText}";

            // Asana «PvP-контекст»: чем защитник был занят, когда на него напали (флейвор).
            if ($defenderActivityPhrase !== null) {
                $summaryText .= "\n\n🎯 <i>{$defenderName} был застигнут {$defenderActivityPhrase}.</i>";
            }

            // v0.51.112 endgame hook: PvP kill → winner's faction score.
            (new EndgameProgressionService())->recordPvpKill($winner);

            $attackerIntro = ($attacker['id'] === $winner['id'])
                ? "Ты атаковал и разгромил врага!"
                : "Ты начал бой, но оказался слабее в этот раз...";

            $defenderIntro = ($defender['id'] === $winner['id'])
                ? "На тебя напали, но ты защитился и победил!"
                : "Тебя атаковали, и ты пал в этом бою...";
        }

        $campCtx           = $defCampName !== null ? "\n🏕️ База: «{$defCampName}»" : '';
        $attackerFinalText = "🤺 <b>{$attackerName}</b>, {$attackerIntro}{$campCtx}\n\n{$summaryText}";
        $defenderFinalText = "🛡 <b>{$defenderName}</b>, {$defenderIntro}\n\n{$summaryText}";

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // 3) Лог боя в БД.
        $endTime  = date('Y-m-d H:i:s');
        $winnerId = $winner ? $winner['id'] : null;

        // Asana «Расширение логирования»: log_data v2 уже собран ВЫШЕ (до обработки смерти).
        $battleId = $this->battleLogModel->insert([
            'battle_type' => 'PVP',
            'player1_id'  => $attacker['id'],
            'player2_id'  => $defender['id'],
            'winner_id'   => $winnerId,
            'created_at'  => $battleStartTime,
            'finished_at' => $endTime,
            'log_data'    => json_encode($battleLogData, JSON_UNESCAPED_UNICODE),
        ]);

        // W18 (ADR-072): post-combat scoring в PvP-ладдер — ПОСЛЕ battle_logs-insert, ВНЕ simulateFight
        // (0 нового mt_rand → fence byte-equivalent сохраняется). Killswitch pvp.ladder.enabled внутри
        // recordPvpAttack (dormant = no-op). Летальная PvP-победа дороже дуэли (риск death/XP-loss).
        $ladderWinnerId = is_numeric($winnerId) ? (int) $winnerId : 0;
        if ($ladderWinnerId > 0) {
            $atkId         = is_numeric($attacker['id'] ?? null) ? (int) $attacker['id'] : 0;
            $defId         = is_numeric($defender['id'] ?? null) ? (int) $defender['id'] : 0;
            $ladderLoserId = $ladderWinnerId === $atkId ? $defId : $atkId;
            (new \App\Services\PVE\PvpLadderService())->recordPvpAttack($ladderWinnerId, $ladderLoserId);

            // ADR-135 Ф3b «Доска розыска» (bounty): winner сразил доминатора (loser держал активную
            // подать над другими) → охотничий трофей (престиж). Пишем ДО liftByRematch — иначе реванш
            // единственного данника снял бы розыск раньше проверки. Killswitch внутри (dormant=no-op).
            $bountyResult = (new \App\Services\PVE\BountyService())->recordClaim($ladderWinnerId, $ladderLoserId);

            // ADR-135 «Трофейная подать»: ПОСЛЕ battle_logs-insert (счёт побед из БД),
            // killswitch `tribute.enabled` внутри (dormant=no-op), 0 нового mt_rand → fence цел.
            $tributeSvc = new \App\Services\PVE\TributeService();
            // (а) победитель доминирует проигравшего → создание подати (N побед/окно И 0 поражений).
            $tributeCreatedId = $tributeSvc->evaluateDomination($ladderWinnerId, $ladderLoserId);
            // (б) Ф3 реванш: победитель — бывший данник проигравшего → его подать снимается.
            $tributeLifted = $tributeSvc->liftByRematch($ladderWinnerId, $ladderLoserId);

            // Ф4 уведомления — приклеиваем к сообщениям бойцов (HTML). При dormant обе ветки
            // пусты (id=null, lifted=0) → текст byte-identical прежнему.
            if ($tributeCreatedId !== null && $winner && $loser) {
                $masterLine = "\n\n⚖️ <b>Трофейная подать!</b> {$loser['name']} теперь отдаёт тебе долю с добычи — 👤 Перс → ⚖️ Трофейная подать.";
                $vassalLine = "\n\n⚖️ <b>Ты под трофейной податью</b> у {$winner['name']}: часть добычи уходит ему. Сбрось реваншем в бою или выкупись — 👤 Перс → ⚖️ Трофейная подать.";
                if ((int) $winner['id'] === (int) $attacker['id']) {
                    $attackerFinalText .= $masterLine;
                    $defenderFinalText .= $vassalLine;
                } else {
                    $defenderFinalText .= $masterLine;
                    $attackerFinalText .= $vassalLine;
                }
            }
            if ($tributeLifted > 0 && $winner) {
                $freedLine = "\n\n🔓 <b>Реванш!</b> Ты сбросил трофейную подать — больше не платишь долю.";
                if ((int) $winner['id'] === (int) $attacker['id']) {
                    $attackerFinalText .= $freedLine;
                } else {
                    $defenderFinalText .= $freedLine;
                }
            }

            // Ф3b — уведомление охотника о засчитанном трофее. При dormant/не-в-розыске/кулдауне
            // recorded=false → текст byte-identical прежнему.
            if ($bountyResult['recorded'] && $winner && $loser) {
                $bountyLine = "\n\n🎯 <b>Трофей охотника!</b> Ты сразил угнетателя {$loser['name']}, "
                    . "державшего трофейную подать над другими (всего трофеев: {$bountyResult['claims']}). 🎮 Развлечения → 🎯 Доска розыска.";
                if ((int) $winner['id'] === (int) $attacker['id']) {
                    $attackerFinalText .= $bountyLine;
                } else {
                    $defenderFinalText .= $bountyLine;
                }
            }
        }

        $battleUrl = base_url('battles/view/') . $battleId;
        $attackerFinalText .= "\n\n<a href=\"{$battleUrl}\">[Посмотреть детали боя]</a>";
        $defenderFinalText .= "\n\n<a href=\"{$battleUrl}\">[Посмотреть детали боя]</a>";

        // 4) Уведомления обоим бойцам. E24 (N6): каждому — клавиатура с продолжением.
        // Если у бойца остался ПРИОСТАНОВЛЕННЫЙ поход (атакующий пришёл из паузы
        // 'player_detected') — предлагаем «▶️ Продолжить поход» (march_resume), иначе
        // обычный вход в Поход. Защитник раньше получал сообщение БЕЗ кнопок (тупик).
        $attackerId = is_numeric($attacker['id'] ?? null) ? (int) $attacker['id'] : 0;
        $defenderId = is_numeric($defender['id'] ?? null) ? (int) $defender['id'] : 0;

        Request::sendMessage([
            'chat_id'                  => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'                     => $attackerFinalText,
            'parse_mode'               => 'HTML',
            'reply_markup'             => json_encode($this->postBattleKeyboard($attackerId)),
            'disable_web_page_preview' => true,
        ]);

        try {
            $defUser = $this->telegramUserModel->find($defender['telegram_user_id']);
            if ($defUser && !empty($defUser['telegram_id'])) {
                Request::sendMessage([
                    'chat_id'                  => $defUser['telegram_id'],
                    'text'                     => $defenderFinalText,
                    'parse_mode'               => 'HTML',
                    'reply_markup'             => json_encode($this->postBattleKeyboard($defenderId)),
                    'disable_web_page_preview' => true,
                ]);
            }
        } catch (TelegramException $e) {
            log_message('error', "notifyDefender error: " . $e->getMessage());
        }

        return Request::emptyResponse();
    }

    /**
     * Нормализует персонажа (CharacterEntity ИЛИ массив) в plain array.
     *
     * Нужно для строго-array-консьюмеров боевого пути (`simulateFight` $p1/$p2,
     * `processMutualExhaustion`): с F1.4 `getUserAndCharacter()` / `CharacterModel::find()`
     * возвращают CharacterEntity, и прямой проброс ронял `TypeError` (прод 2026-06-14).
     * Pure + static → тестируется без инстанса контроллера (требует CallbackQuery).
     *
     * @param mixed $character CharacterEntity, массив или (защитно) что угодно
     * @return array<string,mixed>
     */
    public static function toCharacterArray(mixed $character): array
    {
        $raw = [];
        if (is_array($character)) {
            $raw = $character;
        } elseif ($character instanceof \CodeIgniter\Entity\Entity) {
            $raw = $character->toRawArray();
        }

        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * Соседние клетки (dx ≤ 1 && dy ≤ 1) — пред-валидация перед боем.
     */
    private function isCellsCloseEnough(array|\App\Entities\CharacterEntity $charA, array|\App\Entities\CharacterEntity $charB): bool
    {
        if ($charA['cell_number'] === $charB['cell_number']) {
            return true;
        }
        $mapA = $this->equipmentRepo->getMapCell((int) $charA['cell_number']);
        $mapB = $this->equipmentRepo->getMapCell((int) $charB['cell_number']);
        if (!$mapA || !$mapB) {
            return false;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return ($dx <= 1 && $dy <= 1);
    }

    /**
     * Формат короткого текста итогов боя для Telegram.
     */
    private function formatShortFightResult(array $res): string
    {
        $fa     = $res['firstAttacker'];
        $rounds = $res['rounds'];

        if ($res['type'] === 'exhausted') {
            return "<b>PvP-бой завершён</b>\n"
                . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
                . "🔁 <b>Раундов:</b> {$rounds}\n"
                . "<b>Оба выдохлись!</b>";
        }

        $w = $res['winner'];
        $l = $res['loser'];
        if (!$l) {
            return "<b>PvP-бой завершён</b>\n"
                . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
                . "🔁 <b>Раундов:</b> {$rounds}\n"
                . "<b>Ничья?</b>";
        }
        return "<b>PvP-бой завершён</b>\n"
            . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
            . "🔁 <b>Всего обменов ударами:</b> {$rounds}\n"
            . "❌ <b>Проиграл:</b> {$l['name']}\n"
            . "🏆 <b>Победил:</b> {$w['name']}";
    }

    /**
     * E24 (N6) — клавиатура «после боя». Если у бойца остался ПРИОСТАНОВЛЕННЫЙ поход
     * (атакующий пришёл из паузы 'player_detected') — кнопка «▶️ Продолжить поход»
     * (march_resume) вместо нового похода. Иначе обычный вход в Поход. Чистая
     * presentation — без RNG (fixture-fence не затрагивается).
     *
     * @return array{inline_keyboard: array<int, array<int, array<string,string>>>}
     */
    private function postBattleKeyboard(int $characterId): array
    {
        $marchBtn = $this->hasPausedMarch($characterId)
            ? ['text' => '▶️ Продолжить поход', 'callback_data' => 'march_resume']
            : ['text' => '🗺️ Поход',            'callback_data' => 'march'];

        return [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    $marchBtn,
                ],
            ],
        ];
    }

    /**
     * E24 (N6) — есть ли у персонажа приостановленный поход (task 'Marching', status='paused').
     */
    private function hasPausedMarch(int $characterId): bool
    {
        if ($characterId <= 0) {
            return false;
        }
        $res = \Config\Database::connect()->query(
            "SELECT ct.id FROM character_tasks ct
             JOIN tasks t ON t.id = ct.task_id
             WHERE ct.character_id = ? AND t.name = 'Marching' AND ct.status = 'paused'
             LIMIT 1",
            [$characterId]
        );
        if (! $res instanceof \CodeIgniter\Database\BaseResult) {
            return false;
        }
        return $res->getRowArray() !== null;
    }

    /**
     * Telegram error response (callback alert + chat message).
     */
    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => "⚠️ <b>Ошибка:</b> {$msg}",
            'parse_mode' => 'HTML',
        ]);
    }
}
