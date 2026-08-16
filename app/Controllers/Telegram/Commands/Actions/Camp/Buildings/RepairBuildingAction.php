<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Services\PVE\DefenseStructureService;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Notifications\MediaSender;

/**
 * ADR-041 — МГНОВЕННЫЙ ремонт оборонной структуры (восстановление character_buildings.hp).
 *
 * Двухшаговый: askForRepair (показать стоимость) → confirmRepair (списать + восстановить hp).
 * Стоимость пропорциональна потерянному hp: ceil(build_resources × cost_fraction ×
 * missingHp/maxHp). Restore сразу до maxHp (с учётом уровня). Без task — оборона
 * чинится мгновенно (смысл фичи — держаться под осадой). Graceful «здание исчезло»
 * (удалено налогом). callback: repairBuilding_{cb_id} / confirmRepairBuilding_{cb_id}.
 */
class RepairBuildingAction extends BaseAction
{
    use GameSettingsReaderTrait;

    protected CharacterBuildingModel $characterBuildingModel;
    protected BuildingModel $buildingModel;
    protected ResourceModel $resModel;
    protected CharacterResourceModel $characterResourceModel;
    private DefenseStructureService $defenseService;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
        $this->resModel               = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->defenseService         = new DefenseStructureService();
    }

    /**
     * Абстрактный контракт BaseAction. Вход всегда через ask/confirm (CallbackPrefixDispatcher),
     * но handle() обязателен — дефолтим на превью ремонта.
     */
    public function handle(): ServerResponse
    {
        return $this->askForRepair();
    }

    public function askForRepair(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$ok, $charId, $cb, $buildingInfo] = $this->resolve();
        if (!$ok || !is_array($cb) || !is_array($buildingInfo)) {
            return $this->msg($chatId, 'Постройка не найдена (возможно, снесена за неуплату налога).');
        }

        $plan = $this->computePlan($cb, $buildingInfo, $charId);
        if (!$plan['needed']) {
            return $this->msg($chatId, '✅ Эта структура в полном порядке — ремонт не нужен.');
        }

        $cbId  = $this->asInt($cb['id'] ?? null);
        $lines = '';
        foreach ($plan['lines'] as $l) {
            $mark = $l['ok'] ? '' : ' ❗';
            $lines .= "• {$l['name']}: *{$l['qty']}* (есть {$l['have']}){$mark}\n";
        }
        $text = "🛡 *Ремонт: {$plan['nameRu']}*\n\n"
            . "❤️ Прочность: *{$plan['curHp']} / {$plan['maxHp']}* hp\n\n"
            . "🔧 Стоимость ремонта (пропорционально урону):\n{$lines}\n"
            . ($plan['affordable']
                ? "Восстановит прочность до *{$plan['maxHp']}* мгновенно."
                : "❗ Недостаточно ресурсов для ремонта.");

        $rows = [];
        if ($plan['affordable']) {
            $rows[] = [['text' => '✅ Починить', 'callback_data' => 'confirmRepairBuilding_' . $cbId]];
        }
        $rows[] = [['text' => '🏠 База', 'callback_data' => 'Base']];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    public function confirmRepair(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$ok, $charId, $cb, $buildingInfo] = $this->resolve();
        if (!$ok || !is_array($cb) || !is_array($buildingInfo)) {
            return $this->msg($chatId, 'Постройка не найдена (возможно, снесена за неуплату налога).');
        }

        $plan = $this->computePlan($cb, $buildingInfo, $charId);
        if (!$plan['needed']) {
            return $this->msg($chatId, '✅ Эта структура уже в полном порядке.');
        }
        if (!$plan['affordable']) {
            return $this->msg($chatId, '❗ Недостаточно ресурсов для ремонта.');
        }

        // Списываем ресурсы и мгновенно восстанавливаем hp до maxHp.
        foreach ($plan['lines'] as $l) {
            if ($l['qty'] > 0 && $l['resId'] > 0) {
                $this->characterResourceModel->decreaseResources($charId, $l['resId'], $l['qty']);
            }
        }
        $this->characterBuildingModel->update($this->asInt($cb['id'] ?? null), ['hp' => $plan['maxHp']]);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => '🔧 Структура отремонтирована!',
        ]);
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => "🔧 *{$plan['nameRu']}* отремонтирована!\n\n❤️ Прочность восстановлена до *{$plan['maxHp']}* hp.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🏠 База', 'callback_data' => 'Base']]]]),
        ]);
    }

    /**
     * Загружает структуру по callback repairBuilding_{cb_id}. Проверяет владение + что
     * она оборонная. charId считается здесь (где $character типизирован), наружу — int.
     *
     * @return array{0:bool,1:int,2:?array<array-key,mixed>,3:?array<array-key,mixed>}
     */
    private function resolve(): array
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return [false, 0, null, null];
        }
        $charId = $this->asInt($character['id'] ?? null);

        $parts = explode('_', $this->callbackQuery->getData());
        $cbId  = $this->asInt($parts[1] ?? null);
        if ($cbId <= 0) {
            return [false, $charId, null, null];
        }

        $cb = $this->characterBuildingModel->find($cbId);
        if (!is_array($cb)
            || $this->asInt($cb['character_id'] ?? null) !== $charId
            || ($cb['building_type'] ?? '') !== 'defensive') {
            return [false, $charId, null, null];
        }

        $buildingInfo = $this->buildingModel->find($this->asInt($cb['building_id'] ?? null));
        if (!is_array($buildingInfo)) {
            return [false, $charId, null, null];
        }
        return [true, $charId, $cb, $buildingInfo];
    }

    /**
     * @param array<array-key,mixed> $cb
     * @param array<array-key,mixed> $buildingInfo
     * @return array{needed:bool, affordable:bool, maxHp:int, curHp:int, nameRu:string, lines:list<array{name:string,resId:int,qty:int,have:int,ok:bool}>}
     */
    private function computePlan(array $cb, array $buildingInfo, int $charId): array
    {
        $level      = max(1, $this->asInt($cb['level'] ?? null, 1));
        $templateHp = $this->asInt($buildingInfo['hp'] ?? null);
        $maxHp      = $this->defenseService->maxHpFor($templateHp, $level);
        $curHp      = max(0, $this->asInt($cb['hp'] ?? null));
        $nameRu     = is_string($buildingInfo['name_ru'] ?? null) ? $buildingInfo['name_ru'] : 'Постройка';
        $nameEn     = is_string($buildingInfo['name_en'] ?? null) ? $buildingInfo['name_en'] : '';

        $needed = $maxHp > 0 && $curHp < $maxHp;
        if (!$needed) {
            return ['needed' => false, 'affordable' => false, 'maxHp' => $maxHp, 'curHp' => $curHp, 'nameRu' => $nameRu, 'lines' => []];
        }

        $missingFraction = ($maxHp - $curHp) / $maxHp;
        $costFraction    = $this->gsFloat('defense.repair.cost_fraction', 0.50);

        $cfg       = config('Buildings');
        $recipe    = (is_object($cfg) && method_exists($cfg, 'get')) ? $cfg->get($nameEn) : null;
        $resources = (is_array($recipe) && isset($recipe['resources']) && is_array($recipe['resources']))
            ? $recipe['resources']
            : [];

        $lines      = [];
        $affordable = true;
        foreach ($resources as $resNameEn => $baseQtyRaw) {
            $baseQty = is_numeric($baseQtyRaw) ? (int) $baseQtyRaw : 0;
            $qty     = (int) ceil($baseQty * $costFraction * $missingFraction);
            if ($qty <= 0) {
                continue;
            }
            $resRow = $this->resModel->where('name_en', (string) $resNameEn)->first();
            if ($resRow === null) {
                continue;
            }
            $resId     = $this->asInt($resRow['id'] ?? null);
            $nameRuRes = is_string($resRow['name'] ?? null) ? $resRow['name'] : (string) $resNameEn;
            $haveRow   = $this->characterResourceModel
                ->where(['id_characters' => $charId, 'id_resources' => $resId])
                ->first();
            $have = is_array($haveRow) ? $this->asInt($haveRow['quantity'] ?? null) : 0;
            $ok   = $have >= $qty;
            if (!$ok) {
                $affordable = false;
            }
            $lines[] = ['name' => $nameRuRes, 'resId' => $resId, 'qty' => $qty, 'have' => $have, 'ok' => $ok];
        }

        return ['needed' => true, 'affordable' => $affordable, 'maxHp' => $maxHp, 'curHp' => $curHp, 'nameRu' => $nameRu, 'lines' => $lines];
    }

    private function msg(int|string $chatId, string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🏠 База', 'callback_data' => 'Base']]]]),
        ]);
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
