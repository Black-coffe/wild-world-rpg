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
 * W19 (ADR-074) — экран «✨ Зачарование». Callback `enchant` (превью) / `enchantConfirm` (применить).
 *
 * Зачаровывает ЭКИПИРОВАННОЕ оружие персонажа: +X% к урону (per-instance, перезапись).
 * Стоимость gold + ресурс — из GameSettings. Killswitch `craft.modifier.enabled`: при dormant — alert.
 * Caption самодостаточен (media-off safe). edit-in-place.
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
            return $this->editText($chatId, "✨ *Зачарование временно недоступно*\n\n_Раздел отключён администрацией._", [
                [['text' => '◀️ Перс', 'callback_data' => 'character']],
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $weapon      = $this->equippedWeapon($characterId);

        if ($weapon === null) {
            return $this->editText($chatId, "✨ *Зачарование*\n\nУ тебя не экипировано оружие. Надень оружие в разделе персонажа, чтобы усилить его.", [
                [['text' => '◀️ Перс', 'callback_data' => 'character']],
            ]);
        }

        $cwId      = is_numeric($weapon['cw_id'] ?? null) ? (int) $weapon['cw_id'] : 0;
        $data      = (string) $this->callbackQuery->getData();
        $isConfirm = $data === 'enchantConfirm';

        if ($isConfirm) {
            $result = $this->modifiers->enchant($characterId, $cwId);
            return $this->renderResult($chatId, $weapon, $result);
        }

        return $this->renderPreview($chatId, $characterId, $weapon, $cwId);
    }

    /**
     * @param array<string,mixed> $weapon
     */
    private function renderPreview(int $chatId, int $characterId, array $weapon, int $cwId): ServerResponse
    {
        $goldCost = $this->modifiers->goldCost();
        $resName  = $this->modifiers->resourceName();
        $resQty   = $this->modifiers->resourceQty();
        $bonus    = $this->modifiers->bonusPct();

        $name      = is_string($weapon['name'] ?? null) && $weapon['name'] !== '' ? $weapon['name'] : 'оружие';
        $baseDmg   = is_numeric($weapon['damage_value'] ?? null) ? (float) $weapon['damage_value'] : 0.0;
        $curBonus  = $this->modifiers->modifierBonusPct('weapon', $cwId, 'damage');
        $effDmg    = $baseDmg * (1 + $curBonus / 100.0);
        $newBonus  = $bonus; // 1 модификатор на предмет → перезапись на текущий bonus_pct.
        $newEffDmg = $baseDmg * (1 + $newBonus / 100.0);

        $text  = "✨ *Зачарование оружия*\n\n";
        $text .= "Оружие: *{$name}*\n";
        $text .= "Базовый урон: " . $this->fmt($baseDmg) . "\n";
        if ($curBonus > 0) {
            $text .= "Текущее зачарование: *+{$curBonus}%* (урон " . $this->fmt($effDmg) . ")\n";
        } else {
            $text .= "Текущее зачарование: нет\n";
        }
        $text .= "\nНаложить *+{$newBonus}%* к урону → станет " . $this->fmt($newEffDmg) . ".\n";
        if ($curBonus > 0) {
            $text .= "_(заменит текущее зачарование этого оружия)_\n";
        }
        $text .= "\n💰 Стоимость: *{$goldCost}* золота";
        if ($resName !== '' && $resQty > 0) {
            $have = $this->resourceHave($resName, $characterId);
            $text .= " + *{$resQty}× {$resName}* (есть: {$have})";
        }

        $gold     = $this->characterGold($characterId);
        $canAfford = $gold >= $goldCost
            && ($resName === '' || $resQty <= 0 || $this->resourceHave($resName, $characterId) >= $resQty);

        $text .= "\n💵 У тебя золота: {$gold}";

        $rows = [];
        if ($canAfford) {
            $rows[] = [['text' => "✨ Зачаровать (+{$newBonus}%)", 'callback_data' => 'enchantConfirm']];
        } else {
            $text .= "\n\n_Недостаточно ресурсов для зачарования._";
        }
        $rows[] = [['text' => '◀️ Перс', 'callback_data' => 'character']];

        return $this->editText($chatId, $text, $rows);
    }

    /**
     * @param array<string,mixed> $weapon
     * @param array{ok:bool, error:?string, bonus_pct:int} $result
     */
    private function renderResult(int $chatId, array $weapon, array $result): ServerResponse
    {
        $name = is_string($weapon['name'] ?? null) && $weapon['name'] !== '' ? $weapon['name'] : 'оружие';

        if ($result['ok'] === true) {
            $baseDmg = is_numeric($weapon['damage_value'] ?? null) ? (float) $weapon['damage_value'] : 0.0;
            $eff     = $baseDmg * (1 + $result['bonus_pct'] / 100.0);
            $text    = "✨ *Зачарование наложено!*\n\n"
                . "*{$name}* теперь *+{$result['bonus_pct']}%* к урону (" . $this->fmt($eff) . ").\n\n"
                . "_Бонус действует в PvE и PvP, пока это оружие экипировано._";
        } else {
            $text = "✨ *Зачарование не выполнено*\n\n" . $this->errorText($result['error']);
        }

        return $this->editText($chatId, $text, [
            [['text' => '✨ Зачарование', 'callback_data' => 'enchant']],
            [['text' => '◀️ Перс', 'callback_data' => 'character']],
        ]);
    }

    private function errorText(?string $error): string
    {
        switch ($error) {
            case 'no_gold':     return 'Недостаточно золота.';
            case 'no_resource': return 'Недостаточно ресурса-катализатора.';
            case 'not_equipped':return 'Оружие не экипировано.';
            case 'disabled':    return 'Раздел отключён.';
            default:            return 'Не удалось наложить зачарование. Попробуй позже.';
        }
    }

    /**
     * Экипированное оружие персонажа: cw_id (characters_weapons.id) + name + damage_value.
     *
     * @return array<string,mixed>|null
     */
    private function equippedWeapon(int $characterId): ?array
    {
        $q = Database::connect()->table('characters_weapons')
            ->select('characters_weapons.id AS cw_id, weapons.name AS name, weapons.damage_value AS damage_value')
            ->join('weapons', 'weapons.id = characters_weapons.weapon_id', 'inner')
            ->where('characters_weapons.character_id', $characterId)
            ->where('characters_weapons.equipped', 1)
            ->get();
        $row = $q === false ? null : $q->getRowArray();
        return is_array($row) ? $row : null;
    }

    private function characterGold(int $characterId): int
    {
        $q   = Database::connect()->table('characters')->select('gold')->where('id', $characterId)->get();
        $row = $q === false ? null : $q->getRowArray();
        return is_array($row) && is_numeric($row['gold'] ?? null) ? (int) $row['gold'] : 0;
    }

    private function resourceHave(string $resName, int $characterId): int
    {
        $row  = $this->resources->getResourceForCraft($resName, $characterId);
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
