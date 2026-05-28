<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Services\Craft\ItemModifierService;
use App\Services\Notifications\MediaSender;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W19 (ADR-074) + W20 (ADR-075) — экран «🔧 Модернизация».
 * (player-facing термин — «Модернизация»: реалистичный постапок без магии; callback/класс — техн. legacy-имя.)
 *
 * Модернизирует ЭКИПИРОВАННОЕ оружие (+урон) или броню (+защита): накапливаемые тиры (+step до cap),
 * цена ×тир. Per-instance, перезапись строки модификатора. Callbacks:
 *   `enchant` — листинг модернизируемых предметов (оружие + слоты брони);
 *   `enchantSel_<type>_<id>` — превью предмета (текущий/следующий тир, цена);
 *   `enchantApply_<type>_<id>` — применить.
 * Killswitch `craft.modifier.enabled`: при dormant — alert. Caption самодостаточен (media-off safe). edit-in-place.
 */
final class EnchantAction extends BaseAction
{
    private ItemModifierService $modifiers;
    private CharacterResourceModel $resources;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->modifiers = new ItemModifierService();
        $this->resources = new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        if (! $this->modifiers->enabled()) {
            return $this->editText($chatId, "🔧 *Модернизация временно недоступна*\n\n_Раздел отключён администрацией._", [
                [['text' => '◀️ Перс', 'callback_data' => 'character']],
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $data        = (string) $this->callbackQuery->getData();

        // enchantSel_<type>_<id> / enchantApply_<type>_<id>
        if (preg_match('/^(enchantSel|enchantApply)_(weapon|outfit)_(\d+)$/', $data, $m) === 1) {
            $type = $m[2];
            $id   = (int) $m[3];
            if ($m[1] === 'enchantApply') {
                $result = $this->modifiers->enchant($characterId, $type, $id);
                return $this->renderResult($chatId, $characterId, $type, $id, $result);
            }
            return $this->renderPreview($chatId, $characterId, $type, $id);
        }

        return $this->renderList($chatId, $characterId);
    }

    private function renderList(int $chatId, int $characterId): ServerResponse
    {
        $items = $this->modernizableItems($characterId);
        $text  = "🔧 *Модернизация снаряжения*\n\n";

        if (empty($items)) {
            $text .= "У тебя нет экипированного оружия или брони. Надень снаряжение в разделе персонажа, чтобы усилить его.";
            return $this->editText($chatId, $text, [[['text' => '◀️ Перс', 'callback_data' => 'character']]]);
        }

        $text .= "Выбери предмет для усиления (оружие → +урон, броня → +защита).\nКаждая модернизация добавляет +"
            . $this->modifiers->bonusStep() . "% (до +" . $this->modifiers->maxBonusPct() . "%), цена растёт с тиром.\n";

        $rows = [];
        foreach ($items as $it) {
            $cur   = $this->modifiers->modifierBonusPct($it['type'], $it['id'], $it['stat']);
            $label = ($it['type'] === 'weapon' ? '🗡 ' : '🛡 ') . $it['name'] . ($cur > 0 ? " (+{$cur}%)" : '');
            $rows[] = [['text' => $label, 'callback_data' => "enchantSel_{$it['type']}_{$it['id']}"]];
        }
        $rows[] = [['text' => '◀️ Перс', 'callback_data' => 'character']];

        return $this->editText($chatId, $text, $rows);
    }

    private function renderPreview(int $chatId, int $characterId, string $type, int $id): ServerResponse
    {
        $info = $this->itemInfo($type, $id, $characterId);
        if ($info === null) {
            return $this->renderList($chatId, $characterId);
        }
        $p        = $this->modifiers->previewFor($type, $id);
        $statWord = $type === 'weapon' ? 'урон' : 'защита';   // именительный (для «защита X»)
        $statAcc  = $type === 'weapon' ? 'урон' : 'защиту';   // винительный (для «усилить X»)
        $base     = $info['base'];
        $curEff   = $base * (1 + $p['current'] / 100.0);

        $text  = "🔧 *Модернизация: {$info['name']}*\n\n";
        $text .= ($type === 'weapon' ? 'Базовый урон' : 'Базовая защита') . ': ' . $this->fmt($base) . "\n";
        if ($p['current'] > 0) {
            $text .= "Текущая модернизация: *+{$p['current']}%* ({$statWord} " . $this->fmt($curEff) . ")\n";
        } else {
            $text .= "Текущая модернизация: нет\n";
        }

        if ($p['atMax']) {
            $text .= "\n✅ Достигнут максимальный тир (+{$p['current']}%). Дальше усиливать нельзя.";
            return $this->editText($chatId, $text, [
                [['text' => '◀️ К списку', 'callback_data' => 'enchant']],
                [['text' => '◀️ Перс', 'callback_data' => 'character']],
            ]);
        }

        $newEff = $base * (1 + $p['next'] / 100.0);
        $text .= "\nТир {$p['tier']}: усилить {$statAcc} до *+{$p['next']}%* → станет " . $this->fmt($newEff) . ".\n";
        $text .= "\n💰 Стоимость: *{$p['gold']}* золота";
        $resName = $this->modifiers->resourceName();
        if ($resName !== '' && $p['resourceQty'] > 0) {
            $have = $this->resourceHave($resName, $characterId);
            $text .= " + *{$p['resourceQty']}× {$resName}* (есть: {$have})";
        }
        $gold = $this->characterGold($characterId);
        $text .= "\n💵 У тебя золота: {$gold}";

        $canAfford = $gold >= $p['gold']
            && ($resName === '' || $p['resourceQty'] <= 0 || $this->resourceHave($resName, $characterId) >= $p['resourceQty']);

        $rows = [];
        if ($canAfford) {
            $rows[] = [['text' => "🔧 Модернизировать (+{$this->modifiers->bonusStep()}%)", 'callback_data' => "enchantApply_{$type}_{$id}"]];
        } else {
            $text .= "\n\n_Недостаточно ресурсов для модернизации._";
        }
        $rows[] = [['text' => '◀️ К списку', 'callback_data' => 'enchant']];

        return $this->editText($chatId, $text, $rows);
    }

    /**
     * @param array{ok:bool, error:?string, bonus_pct:int, tier:int} $result
     */
    private function renderResult(int $chatId, int $characterId, string $type, int $id, array $result): ServerResponse
    {
        $info     = $this->itemInfo($type, $id, $characterId);
        $name     = $info !== null ? $info['name'] : ($type === 'weapon' ? 'оружие' : 'броня');
        $statWord = $type === 'weapon' ? 'урону' : 'защите';

        if ($result['ok'] === true) {
            $text = "🔧 *Модернизация выполнена!*\n\n"
                . "*{$name}* — тир {$result['tier']}: теперь *+{$result['bonus_pct']}%* к {$statWord}.\n\n"
                . "_Бонус действует в PvE и PvP, пока предмет экипирован._";
        } else {
            $text = "🔧 *Модернизация не выполнена*\n\n" . $this->errorText($result['error']);
        }

        return $this->editText($chatId, $text, [
            [['text' => '🔧 К списку', 'callback_data' => 'enchant']],
            [['text' => '◀️ Перс', 'callback_data' => 'character']],
        ]);
    }

    private function errorText(?string $error): string
    {
        switch ($error) {
            case 'no_gold':     return 'Недостаточно золота.';
            case 'no_resource': return 'Недостаточно ресурса-катализатора.';
            case 'not_equipped':return 'Предмет не экипирован.';
            case 'max_tier':    return 'Достигнут максимальный тир модернизации.';
            case 'disabled':    return 'Раздел отключён.';
            default:            return 'Не удалось выполнить модернизацию. Попробуй позже.';
        }
    }

    /**
     * Экипированные модернизируемые предметы: оружие + каждый слот брони.
     *
     * @return list<array{type:string, id:int, name:string, stat:string}>
     */
    private function modernizableItems(int $characterId): array
    {
        $out = [];
        $db  = Database::connect();

        $wq = $db->table('characters_weapons')
            ->select('characters_weapons.id AS cid, weapons.name AS name')
            ->join('weapons', 'weapons.id = characters_weapons.weapon_id', 'inner')
            ->where('characters_weapons.character_id', $characterId)
            ->where('characters_weapons.equipped', 1)
            ->get();
        foreach (($wq === false ? [] : $wq->getResultArray()) as $r) {
            $id = is_numeric($r['cid'] ?? null) ? (int) $r['cid'] : 0;
            if ($id > 0) {
                $out[] = ['type' => 'weapon', 'id' => $id, 'name' => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : 'Оружие', 'stat' => 'damage'];
            }
        }

        $oq = $db->table('characters_outfits')
            ->select('characters_outfits.id AS cid, outfits.name AS name')
            ->join('outfits', 'outfits.id = characters_outfits.outfit_id', 'inner')
            ->where('characters_outfits.character_id', $characterId)
            ->where('characters_outfits.equipped', 1)
            ->get();
        foreach (($oq === false ? [] : $oq->getResultArray()) as $r) {
            $id = is_numeric($r['cid'] ?? null) ? (int) $r['cid'] : 0;
            if ($id > 0) {
                $out[] = ['type' => 'outfit', 'id' => $id, 'name' => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : 'Броня', 'stat' => 'armor'];
            }
        }

        return $out;
    }

    /**
     * Имя + базовое значение стата экипированного предмета (или null если не найден/не экипирован).
     *
     * @return array{name:string, base:float}|null
     */
    private function itemInfo(string $type, int $id, int $characterId): ?array
    {
        $db = Database::connect();
        if ($type === 'weapon') {
            $q = $db->table('characters_weapons')
                ->select('weapons.name AS name, weapons.damage_value AS val')
                ->join('weapons', 'weapons.id = characters_weapons.weapon_id', 'inner')
                ->where('characters_weapons.id', $id)
                ->where('characters_weapons.character_id', $characterId)
                ->where('characters_weapons.equipped', 1)
                ->get();
        } else {
            $q = $db->table('characters_outfits')
                ->select('outfits.name AS name, outfits.armor_value AS val')
                ->join('outfits', 'outfits.id = characters_outfits.outfit_id', 'inner')
                ->where('characters_outfits.id', $id)
                ->where('characters_outfits.character_id', $characterId)
                ->where('characters_outfits.equipped', 1)
                ->get();
        }
        $row = $q === false ? null : $q->getRowArray();
        if (! is_array($row)) {
            return null;
        }
        return [
            'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : ($type === 'weapon' ? 'Оружие' : 'Броня'),
            'base' => is_numeric($row['val'] ?? null) ? (float) $row['val'] : 0.0,
        ];
    }

    private function characterGold(int $characterId): int
    {
        $q   = Database::connect()->table('characters')->select('gold')->where('id', $characterId)->get();
        $row = $q === false ? null : $q->getRowArray();
        return is_array($row) && is_numeric($row['gold'] ?? null) ? (int) $row['gold'] : 0;
    }

    private function resourceHave(string $resName, int $characterId): int
    {
        $row = $this->resources->getResourceForCraft($resName, $characterId);
        return is_array($row) && is_numeric($row['charResQty'] ?? null) ? (int) $row['charResQty'] : 0;
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    /**
     * @param list<list<array<string,string>>> $rows
     */
    private function editText(int $chatId, string $text, array $rows): ServerResponse
    {
        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }
}
