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
use App\Models\PvpStandoffModel;
use App\Models\TelegramUserModel;
use App\Models\WeaponModel;

use App\Services\Endgame\EndgameProgressionService;
use App\Services\GameSettings\GameSettingsService;
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
use App\Services\PVE\PvpStandoffService;
use App\Services\PVE\StandoffNotifier;

use Config\GameBalance;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;

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

        // ADR-186 (pvp-detection-clarity-08) — гейт окна противостояния стоит
        // ДО записи анти-спам-кулдауна: отбитый повторный тап по замороженной
        // цели не должен стоить атакующему 30 секунд (Инвариант 6). Чисто
        // DB-логика вынесена в resolveStandoffOutcome() — тестируется Reflection'ом
        // без Telegram-сети, тем же приёмом, что isCellsCloseEnough().
        $standoffService = new PvpStandoffService();
        $standoffOutcome = $this->resolveStandoffOutcome($standoffService, $attacker, $defender);

        if (is_array($standoffOutcome['justOpened'])) {
            (new StandoffNotifier())->alertDefender($standoffOutcome['justOpened']);
        }
        if ($standoffOutcome['block']) {
            // resolveStandoffOutcome() всегда несёт строку вместе с block=true —
            // is_array() здесь для phpstan, а не альтернативная ветка поведения.
            return is_array($standoffOutcome['waitStandoff'])
                ? $this->sendStandoffWaitScreen($standoffOutcome['waitStandoff'])
                : $this->sendError('Окно противостояния не удалось прочитать. Попробуйте ещё раз.');
        }

        // v0.51.44 — anti-spam cooldown (Security-telegram §7).
        // Cache-based gate per attacker. Раннє повернення до DB queries
        // (PvPRestriction, MapModel, BiomeModel, simulateFight) — зменшує
        // навантаження від spammers, які жмуть кнопку 2-3× за секунду.
        // ADR-186 §3/Инвариант 7: тап защитника по «⚔️ Ударить первым» — не
        // спам, а разовое право ответа из живого окна; свой кулдаун атакующего
        // (тут — базы) на этот тап не распространяется.
        if (! $standoffOutcome['isCounterAttack']) {
            $cooldownSec    = $this->cfg->pvpAttackCooldownSec;
            $cacheKey       = "pvp_attack_cd_{$attacker['id']}";
            $cache          = \Config\Services::cache();
            $lastAttackTime = $cache->get($cacheKey);
            if (is_int($lastAttackTime) && time() - $lastAttackTime < $cooldownSec) {
                $remaining = $cooldownSec - (time() - $lastAttackTime);
                return $this->sendError("Подождите {$remaining} сек. перед следующей атакой!");
            }
            $cache->save($cacheKey, time(), $cooldownSec);
        }

        if (!$this->isCellsCloseEnough($attacker, $defender)) {
            return $this->sendError("Игрок слишком далеко. Атаковать можно только в одной или соседней ячейке!");
        }

        $pvpRestrictionService = new PvPRestrictionService();
        $check = $pvpRestrictionService->checkPvPAllowed($attacker, $defender);
        if (!$check['allowed']) {
            // UX-DISCOVERABILITY: тап по lock-кнопке («🔒 Уровень» и т.п. из
            // PlayerDetectionService, тот же callback_data) объясняет условие,
            // а не отказывает «⚠️ Ошибка» (pvp-detection-clarity-08, находка -07).
            return $this->sendRestrictionExplanation($check);
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
        // ADR-186 §3/Инвариант 7: при контратаке из окна («⚔️ Ударить первым»)
        // роли в этом вызове развёрнуты — базой владеет тот, кто СЕЙЧАС в слоте
        // $attacker, поэтому профиль резолвится по baseOwnerId/baseOwnerCell
        // из resolveStandoffOutcome(), а не всегда по $defender.
        $defenseService = new DefenseStructureService();
        $defenseProfile = $defenseService->getDefenseProfile(
            $standoffOutcome['baseOwnerId'],
            $standoffOutcome['baseOwnerCell'],
        );

        // ADR-186 §3/Инвариант 8: «укрыться» добавляет фиксированный процент к
        // damage_reduction, сложенный ПОД общим потолком defense.total_damage_
        // reduction_max_percent — не новое поле в бою, тот же существующий профиль.
        if ($standoffOutcome['holdBonusPercent'] > 0) {
            $defenseProfile = $this->applyHoldBonus(
                $defenseProfile,
                $defenseService,
                $standoffOutcome['baseOwnerId'],
                $standoffOutcome['holdBonusPercent']
            );
        }

        // ADR-186 §3: контратака расходует окно ровно один раз — закрываем
        // ПОСЛЕ того, как все гейты выше (смежность/ограничения) пройдены и бой
        // действительно состоится, чтобы отбитая по другой причине контратака
        // не сжигала право на неё.
        if ($standoffOutcome['isCounterAttack'] && $standoffOutcome['counterStandoffId'] !== null) {
            $standoffService->close($standoffOutcome['counterStandoffId'], 'countered');
        }

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
                $masterLine = "\n\n⚖️ <b>Трофейная подать!</b> {$loser['name']} теперь отдаёт тебе долю с добычи — 🧑 Я → ⚖️ Трофейная подать.";
                $vassalLine = "\n\n⚖️ <b>Ты под трофейной податью</b> у {$winner['name']}: часть добычи уходит ему. Сбрось реваншем в бою или выкупись — 🧑 Я → ⚖️ Трофейная подать.";
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
     * ADR-186 (pvp-detection-clarity-08) — весь гейт окна противостояния в одной
     * точке, ДО записи анти-спам-кулдауна и ДО симуляции боя. Чисто DB-чтения и
     * условные переходы через `PvpStandoffService` (сам сервис — `ConditionalWriteService`
     * внутри, гонки исключены на уровне БД); отправка Telegram-сообщений — забота
     * вызывающего `handle()`, поэтому метод тестируется Reflection'ом без сети
     * (тот же приём, что {@see isCellsCloseEnough()}).
     *
     * Три исхода:
     *  - `isCounterAttack=true` — цель этого тапа сама держит открытое окно против
     *    кликающего («⚔️ Ударить первым» защитника): роли развёрнуты, базой
     *    владеет кликающий (`baseOwnerId`), кулдаун и обычный гейт окна не
     *    применяются (ADR-186 §3, Инвариант 7).
     *  - `block=true` — атака по ЭТОЙ цели отбивается (либо уже была заморожена,
     *    либо только что открылась): `waitStandoff` — строка для экрана ожидания.
     *  - иначе — бой идёт обычным путём; если самая свежая строка окна для этой
     *    пары закрыта как `held`, `holdBonusPercent` несёт добавку к снижению
     *    урона (Инвариант 8, складывается вызывающим под общим потолком).
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $attacker
     * @param array<string,mixed>|\App\Entities\CharacterEntity $defender
     * @return array{
     *     enabled: bool,
     *     block: bool,
     *     waitStandoff: array<string,mixed>|null,
     *     justOpened: array<string,mixed>|null,
     *     isCounterAttack: bool,
     *     counterStandoffId: int|null,
     *     baseOwnerId: int,
     *     baseOwnerCell: int,
     *     holdBonusPercent: int,
     * }
     */
    private function resolveStandoffOutcome(
        PvpStandoffService $standoffService,
        array|\App\Entities\CharacterEntity $attacker,
        array|\App\Entities\CharacterEntity $defender
    ): array {
        $attackerId   = is_numeric($attacker['id'] ?? null) ? (int) $attacker['id'] : 0;
        $defenderId   = is_numeric($defender['id'] ?? null) ? (int) $defender['id'] : 0;
        $attackerCell = is_numeric($attacker['cell_number'] ?? null) ? (int) $attacker['cell_number'] : 0;
        $defenderCell = is_numeric($defender['cell_number'] ?? null) ? (int) $defender['cell_number'] : 0;

        $outcome = [
            'enabled'           => $standoffService->isEnabled(),
            'block'             => false,
            'waitStandoff'      => null,
            'justOpened'        => null,
            'isCounterAttack'   => false,
            'counterStandoffId' => null,
            'baseOwnerId'       => $defenderId,
            'baseOwnerCell'     => $defenderCell,
            'holdBonusPercent'  => 0,
        ];

        if (! $outcome['enabled']) {
            return $outcome;
        }

        // Роли развёрнуты: цель этого тапа держит открытое окно против кликающего —
        // это защитник, отвечающий «⚔️ Ударить первым» на существующий attackPlayer_.
        $counter = $standoffService->activeFor($defenderId, $attackerId);
        if ($counter !== null) {
            $outcome['isCounterAttack']   = true;
            $outcome['counterStandoffId'] = is_numeric($counter['id'] ?? null) ? (int) $counter['id'] : null;
            $outcome['baseOwnerId']       = $attackerId;
            $outcome['baseOwnerCell']     = $attackerCell;

            return $outcome;
        }

        // Уже заморожена именно эта пара (attacker → defender) — тот же экран ожидания,
        // не новое окно (ADR-186 §4: «отойти на клетку и вернуться — не обход»).
        $frozen = $standoffService->activeFor($attackerId, $defenderId);
        if ($frozen !== null) {
            $outcome['block']        = true;
            $outcome['waitStandoff'] = $frozen;

            return $outcome;
        }

        // Первая попытка против защитника с живой обороной — открыть окно.
        if ($standoffService->shouldOpen($defenderId, $defenderCell)) {
            $opened = $standoffService->open($attackerId, $defenderId, $defenderCell);
            $active = $opened ?? $standoffService->activeAgainst($defenderId);
            if ($active !== null) {
                $outcome['block']        = true;
                $outcome['waitStandoff'] = $active;
                $outcome['justOpened']   = $opened;

                return $outcome;
            }
        }

        // Не заморожена и не открылась заново — но если самая свежая строка ИМЕННО
        // этой пары закрыта как `held`, надбавка «укрыться» едет в этот бой
        // (ADR-186 §3: защитник остался и отдал инициативу прошлым ходом; -09
        // закрывает окно этим статусом, эта story только читает его результат).
        //
        // 🔴 Находка главной сессии: штатный случай («не открылось заново, потому
        // что защитник ещё на кулдауне») уже закрыт — `shouldOpen()` выше вернул бы
        // `false` из-за `isDefenderOnCooldown()`, и бонус едет ровно в следующий бой.
        // Но `shouldOpen()` возвращает `false` и по ДРУГИМ причинам (защитник ушёл с
        // клетки, постройки снесены, `require_tower` без вышки) — тогда `held`-строка
        // остаётся «самой свежей» НАВСЕГДА, и надбавка утекала бы бессрочно. Граница —
        // тот же `pvp.standoff.cooldown_sec`, что и объясняет несостоявшееся открытие
        // нового окна (не новая настройка): `held` считается «живым для бонуса»
        // ровно то же время, что держит защитника на кулдауне. `cooldown_sec <= 0`
        // отключает бонус вовсе — иначе граница пропадает.
        $latest = $this->latestStandoffRow($attackerId, $defenderId);
        if (is_array($latest) && ($latest['status'] ?? null) === 'held') {
            $latestId    = is_numeric($latest['id'] ?? null) ? (int) $latest['id'] : 0;
            $cooldownSec = max(0, (int) (new GameSettingsService())->get('pvp.standoff.cooldown_sec', 900));
            if ($latestId > 0 && $cooldownSec > 0 && $this->standoffHeldWithinCooldown($latestId, $cooldownSec)) {
                $outcome['holdBonusPercent'] = max(0, (int) (new GameSettingsService())
                    ->get('pvp.standoff.hold_damage_reduction_percent', 10));
            }
        }

        return $outcome;
    }

    /**
     * Свежесть `held`-строки считается часами БД (`NOW() - INTERVAL … SECOND`), а не
     * PHP-временем — тот же приём, что `StandoffExpiryHandler` (`-10`) для
     * `expires_at <= NOW()`: решение принимают одни часы, не два независимых.
     */
    private function standoffHeldWithinCooldown(int $standoffId, int $cooldownSec): bool
    {
        try {
            $query = \Config\Database::connect()
                ->table('pvp_standoffs')
                ->select('id')
                ->where('id', $standoffId)
                ->where('status', 'held')
                ->where('updated_at >= (NOW() - INTERVAL ' . $cooldownSec . ' SECOND)', null, false)
                ->get();
            if ($query === false) {
                return false;
            }

            return is_array($query->getRowArray());
        } catch (\Throwable $e) {
            log_message('error', '[AttackPlayerAction] standoffHeldWithinCooldown failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * ADR-186 §3/Инвариант 8 — добавка «укрыться», сложенная ПОД общим потолком
     * `defense.total_damage_reduction_max_percent`: не новое поле в бою, тот же
     * существующий `damage_reduction`. Вынесено отдельно от `handle()`, чтобы
     * потолок проверялся тестом Reflection'ом без симуляции боя.
     *
     * @param array{owner_id:int,damage_reduction:float,fence_damage:int,initiative_bonus:float,structure_ids:list<int>}|null $defenseProfile
     * @return array{owner_id:int,damage_reduction:float,fence_damage:int,initiative_bonus:float,structure_ids:list<int>}
     */
    private function applyHoldBonus(
        ?array $defenseProfile,
        DefenseStructureService $defenseService,
        int $baseOwnerId,
        int $holdBonusPercent
    ): array {
        $capPercent     = $defenseService->totalReductionCapPercent();
        $currentPercent = $defenseProfile !== null ? $defenseProfile['damage_reduction'] * 100 : 0.0;
        $newPercent     = min($capPercent, $currentPercent + $holdBonusPercent);

        return [
            'owner_id'         => $baseOwnerId,
            'damage_reduction' => $newPercent / 100.0,
            'fence_damage'     => $defenseProfile['fence_damage'] ?? 0,
            'initiative_bonus' => $defenseProfile['initiative_bonus'] ?? 0.0,
            'structure_ids'    => $defenseProfile['structure_ids'] ?? [],
        ];
    }

    /**
     * Самая свежая строка `pvp_standoffs` для пары, ЛЮБОГО статуса — не входит в
     * контракт `PvpStandoffService` (тот отвечает только за «жива ли тревога
     * сейчас»), поэтому читается моделью напрямую. Используется только для
     * определения надбавки «укрыться» — не проверка «окно ещё живо».
     *
     * @return array<string,mixed>|null
     */
    private function latestStandoffRow(int $attackerId, int $defenderId): ?array
    {
        $row = (new PvpStandoffModel())
            ->where('attacker_id', $attackerId)
            ->where('defender_id', $defenderId)
            ->orderBy('id', 'DESC')
            ->first();
        if (! is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * Экран ожидания атакующего, отбитого тапа по цели под тревогой — lock-состояние
     * (UX-Discoverability), не «⚠️ Ошибка»: сколько осталось, что может сделать
     * защитник, «⏳ Проверить» / «🚶 Уйти» (ADR-186 §4).
     *
     * @param array<string,mixed> $standoff
     */
    private function sendStandoffWaitScreen(array $standoff): ServerResponse
    {
        $screen = (new StandoffNotifier())->waitScreen($standoff);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $screen['text'],
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($screen['keyboard']) ?: '{}',
        ]);
    }

    /**
     * UX-DISCOVERABILITY (pvp-detection-clarity-08, находка -07): тап по
     * lock-кнопке («🔒 Уровень» / «🔒 Южная зона» / «🔒 Молодой аккаунт» из
     * `PlayerDetectionService`, тот же `attackPlayer_<id>`) обязан объяснить
     * условие и, где возможно, путь его выполнения — а не отвечать «⚠️ Ошибка».
     * Текст берётся из `reason_code`, который уже несёт `PvPRestrictionService`.
     *
     * @param array<string,mixed> $check
     */
    private function sendRestrictionExplanation(array $check): ServerResponse
    {
        $reasonCode = is_string($check['reason_code'] ?? null) ? $check['reason_code'] : '';
        $messageRaw = $check['message'] ?? ($check['reason'] ?? 'PvP недоступно.');
        $message    = is_string($messageRaw) ? $messageRaw : 'PvP недоступно.';
        $howTo      = match ($reasonCode) {
            'level'       => 'Путь открыт: качай уровень дальше — прогресс виден в «🧑 Я».',
            'account_age' => 'Порог возраста аккаунта снимется сам со временем — здесь ничего нажимать не нужно.',
            'safe_zone'   => 'Отойди из южной зоны — там PvP отключено правилами игры для всех.',
            default       => '',
        };
        $text = '🔒 ' . esc($message, 'html') . ($howTo !== '' ? "\n\n{$howTo}" : '');

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $message,
            'show_alert'        => true,
        ]);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
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
