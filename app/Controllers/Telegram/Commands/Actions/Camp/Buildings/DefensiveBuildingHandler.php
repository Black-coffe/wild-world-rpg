<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Services\PVE\DefenseStructureService;
use App\Services\Notifications\MediaSender;

/**
 * ADR-041 — экран оборонной постройки (WoodenWall / BarbedFence / WatchTower).
 *
 * Раньше эти здания падали в `default` BuildingHandlerAction → «Неизвестное строение»
 * (dead-end): их нельзя было открыть, апгрейд/ремонт недоступны. Этот handler рисует
 * экран с уровнем, прочностью (hp/maxHp с учётом уровня), текущим боевым эффектом и
 * кнопками «🆙 Поднять уровень» / «🔧 Ремонт» (если повреждена). Весь смысл — в тексте
 * (MEDIA-OFF инвариант).
 */
class DefensiveBuildingHandler extends BaseAction
{
    protected CharacterBuildingModel $characterBuildingModel;
    protected BuildingModel $buildingModel;
    private DefenseStructureService $defenseService;

    /** @var array<string, array{emoji:string, image:string}> */
    private const DEF = [
        'WoodenWall'  => ['emoji' => '🪵', 'image' => 'uploads/telegram/camp/wooden_wall.jpg'],
        'BarbedFence' => ['emoji' => '🌵', 'image' => 'uploads/telegram/camp/barbed_fence.jpg'],
        'WatchTower'  => ['emoji' => '🗼', 'image' => 'uploads/telegram/camp/watch_tower.jpg'],
    ];

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
        $this->defenseService         = new DefenseStructureService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // callback: building_{id}_{nameEng}
        $parts   = explode('_', $this->callbackQuery->getData());
        $nameEng = $parts[2] ?? '';
        if (!isset(self::DEF[$nameEng])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Неизвестная оборонная постройка.',
            ]);
        }

        $buildingInfo = $this->buildingModel->where('name_en', $nameEng)->first();
        if (!is_array($buildingInfo)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Информация о постройке не найдена.',
            ]);
        }
        $buildingId = $this->asInt($buildingInfo['id'] ?? null);

        // Показываем самую повреждённую инстанцию (чтобы кнопка ремонта была релевантна).
        $cb = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->orderBy('hp', 'ASC')
            ->first();
        if (!is_array($cb)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет такой постройки на базе.',
            ]);
        }

        $cbId       = $this->asInt($cb['id'] ?? null);
        $level      = max(1, $this->asInt($cb['level'] ?? null, 1));
        $amount     = max(1, $this->asInt($cb['amount'] ?? null, 1));
        $templateHp = $this->asInt($buildingInfo['hp'] ?? null);
        $maxHp      = $this->defenseService->maxHpFor($templateHp, $level);
        $curHp      = max(0, $this->asInt($cb['hp'] ?? null));
        $nameRu     = is_string($buildingInfo['name_ru'] ?? null) ? $buildingInfo['name_ru'] : $nameEng;
        $tax        = $this->asInt($cb['tax'] ?? null);
        $emoji      = self::DEF[$nameEng]['emoji'];

        // ADR-102 Ф3: стак по количеству на базе (только при включённом killswitch).
        $stackOn  = $this->defenseService->isStackEnabled();
        $stackable = $nameEng === 'WoodenWall' || $nameEng === 'BarbedFence'; // вышка не стакает

        $countLine = ($stackOn && $amount > 1)
            ? "🏗 Построено на базе: *×{$amount}*\n"
            : '';

        $text = "{$emoji} *{$nameRu}*\n\n"
            . "🆙 Уровень: *{$level}*\n"
            . $countLine
            . "❤️ Прочность: *{$curHp} / {$maxHp}* hp\n"
            . "⚔️ Эффект: {$this->effectLine($nameEng, $level, $amount, $stackOn)}\n"
            . "💰 Налог: {$tax}/день\n\n"
            . ($curHp < $maxHp
                ? "_Структура повреждена. Почини её — иначе сломается в бою и перестанет защищать._\n\n"
                : "_Структура в полном порядке._\n\n")
            . ($stackOn
                ? ($stackable
                    ? "_💡 Построй ещё такую же на этой базе — защита усилится (каждая следующая слабее, до общего потолка)._"
                    : "_💡 Вторая вышка не усилит — она работает от наличия._")
                : '');

        $topRow = [
            ['text' => '🆙 Поднять уровень', 'callback_data' => 'upgrade_building_' . $buildingId],
        ];
        if ($curHp < $maxHp && $cbId > 0) {
            $topRow[] = ['text' => '🔧 Ремонт', 'callback_data' => 'repairBuilding_' . $cbId];
        }
        $keyboard = ['inline_keyboard' => [
            $topRow,
            [['text' => '🏠 База', 'callback_data' => 'Base']],
        ]];

        $imagePath = base_url(self::DEF[$nameEng]['image']);
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return MediaSender::sendPhotoOrText([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Человекочитаемый текущий боевой эффект структуры с учётом уровня (ADR-041 scaling)
     * и стака по количеству (ADR-102 Ф3, только при включённом killswitch; вышка не стакает).
     */
    private function effectLine(string $nameEng, int $level, int $amount = 1, bool $stackOn = false): string
    {
        $factor = ($stackOn && $amount > 1) ? $this->defenseService->defenseStackFactor($amount) : 1.0;
        switch ($nameEng) {
            case 'WoodenWall':
                $p = $this->defenseService->scaledInt('defense.wall.damage_reduction_percent', 15, $level);
                if ($factor > 1.0) {
                    $cap     = $this->defenseService->totalReductionCapPercent();
                    $stacked = min((int) round($p * $factor), $cap);
                    return "−{$stacked}% получаемого урона у базы (×{$amount}, до cap {$cap}%)";
                }
                return "−{$p}% получаемого урона в PvP у базы (суммарно до cap)";
            case 'BarbedFence':
                $d = $this->defenseService->scaledInt('defense.fence.attacker_damage_per_round', 3, $level);
                if ($factor > 1.0) {
                    $stacked = (int) round($d * $factor);
                    return "контрурон атакующему {$stacked} hp/раунд (×{$amount})";
                }
                return "контрурон атакующему {$d} hp/раунд";
            case 'WatchTower':
                $i = $this->defenseService->scaledInt('defense.tower.defender_initiative_bonus_percent', 8, $level);
                return "+{$i}% инициативы защитнику + предупреждение о приближении чужаков";
            default:
                return '—';
        }
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
