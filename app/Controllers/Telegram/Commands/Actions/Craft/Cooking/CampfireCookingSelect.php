<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Cooking;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\GameSettings\GameSettingsService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V8 (vNext, Фаза 2) — меню готовки на костре (callback `cook`).
 *
 * Farm-to-table: блюда из урожая V6 (Ягоды/Грибы/Фрукты/Зерновые). Каждая кнопка →
 * `genericCraft_<RecipeKey>_1` (прямой старт, как seed-крафт в V6). Готовое блюдо
 * лечит здоровье+выносливость через UsePharmacyAction (💊 Аптечка / 🍲).
 *
 * Caption самодостаточен (media-off): состав + что восстанавливает.
 */
class CampfireCookingSelect extends BaseAction
{
    /** @var list<string> recipe-keys блюд (Config\CraftRecipes), порядок меню. */
    public const COOKING_RECIPES = [
        'MushroomSoup',
        'BerryBrew',
        'BakedFruit',
        'GrainPorridge',
        'HeartyStew',
        // V10 — консервы (shelf-stable, не портятся).
        'StewPreserve',
        'DryRation',
    ];

    /**
     * W23 (ADR-078) — рыбные блюда (дают «Рыбе» применение). Показываются только
     * при killswitch cooking.fish_dishes.enabled (dormant до активации).
     *
     * @var list<string>
     */
    public const FISH_RECIPES = [
        'FishSoup',
        'GrilledFish',
        'FishPreserve',
    ];

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');
        $gs  = new GameSettingsService();

        $text = "🔥 *Костёр — готовка*\n"
            . "_Преврати урожай теплицы и собранные дары в сытные блюда. Еда восстанавливает "
            . "здоровье и особенно выносливость. Готовить можно где угодно._\n";

        // V9 (ADR-034): текущая «Сытость» (если активна) — крафт быстрее + добыча щедрее.
        $fb = new \App\Services\Food\FoodBuffService();
        if ($fb->freshnessEnabled()) {
            $text .= "_Свежее блюдо ({$fb->freshDays()} дн.) даёт полную сытость; залежалое — меньше (но лечит и не пропадает). 🥫🎒 Консервы не портятся._\n";
        }
        [, $character] = $this->getUserAndCharacter();
        $wfu = $character !== null ? ($character['well_fed_until'] ?? null) : null;
        if ($fb->isWellFed($wfu)) {
            $tsEnd    = is_string($wfu) ? strtotime($wfu) : false;
            $minsLeft = $tsEnd !== false ? max(1, (int) ceil(($tsEnd - time()) / 60)) : 0;
            // E21 Ф1 (ADR-121): боевое измерение — сытость даёт бонус в PvE (охота/север).
            $combatNote = $fb->combatEnabled() ? ', в бою сильнее' : '';
            $text .= "🍖 _Сытость активна (ещё ~{$minsLeft} мин): крафт быстрее, добыча щедрее{$combatNote}._\n";
        }
        // E21 Ф1: подсказка-мотивация перед готовкой — еда помогает на охоте (когда баф активен).
        if ($fb->combatEnabled()) {
            $text .= "_⚔️ Сытость держит и в бою: поешь перед охотой на элиток и походом на север._\n";
        }
        $text .= "\n";

        // W23: рыбные блюда добавляются в меню только при включённом killswitch (dormant-safe).
        $recipeKeys = self::COOKING_RECIPES;
        $fishRaw    = $gs->get('cooking.fish_dishes.enabled', false);
        $fishOn     = is_bool($fishRaw) ? $fishRaw : (is_numeric($fishRaw) && (int) $fishRaw === 1);
        if ($fishOn) {
            $recipeKeys = array_merge($recipeKeys, self::FISH_RECIPES);
        }

        $rows = [];
        foreach ($recipeKeys as $key) {
            $recipe = $cfg->get($key);
            if ($recipe === null) {
                continue;
            }
            $icon = isset($recipe['icon_emoji']) && is_string($recipe['icon_emoji']) ? $recipe['icon_emoji'] : '🍲';
            $name = isset($recipe['item_name_rus']) && is_string($recipe['item_name_rus']) ? $recipe['item_name_rus'] : $key;

            // Состав (ингредиенты).
            $parts = [];
            if (isset($recipe['resources']) && is_array($recipe['resources'])) {
                foreach ($recipe['resources'] as $resName => $qty) {
                    $parts[] = (is_string($resName) ? $resName : '') . '×' . (is_numeric($qty) ? (int) $qty : 0);
                }
            }
            $cost = $parts === [] ? '' : implode(', ', $parts);

            // Heal-эффект (data-driven, как UsePharmacyAction резолвит).
            $snake = $this->toSnakeCase($key);
            $hpRaw = $gs->get("medical.{$snake}.heal_health", 0);
            $tdRaw = $gs->get("medical.{$snake}.heal_tired", 0);
            $hp    = is_numeric($hpRaw) ? (int) $hpRaw : 0;
            $td    = is_numeric($tdRaw) ? (int) $tdRaw : 0;

            $text .= "{$icon} *{$name}* — ❤️+{$hp} / ⚡+{$td}\n"
                . "   _{$cost}_\n";
            $rows[] = [['text' => "{$icon} {$name}", 'callback_data' => "genericCraft_{$key}_1"]];
        }

        $rows[] = [
            ['text' => '🔨 К общему крафту', 'callback_data' => 'generalCraft'],
            ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
        ];

        $imagePath = base_url('uploads/telegram/craft/general_crafting_img.png');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /** name_eng → setting-key segment (зеркало UsePharmacyAction::toSnakeCase). */
    private function toSnakeCase(string $name): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $s = str_replace([' ', '-'], '_', $s);
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        return strtolower(trim($s, '_'));
    }
}
