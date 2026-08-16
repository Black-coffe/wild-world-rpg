<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Seasonal;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\World\SeasonalCraftService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S28 (ADR-032) — меню сезонного крафта.
 *
 * Callback: `seasonalCraft`. Показывает рецепты ТЕКУЩЕГО сезона (динамически
 * через SeasonalCraftService). Каждый → `craftPreviewSeasonal_<RecipeKey>`.
 * Killswitch/нет сезона/нет рецептов — соответствующий текст.
 */
class SeasonalCraftSelect extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $svc       = new SeasonalCraftService();
        $seasonKey = $svc->getActiveSeasonKey();
        $imagePath = base_url(self::imageRel());

        $backRow = [
            ['text' => '🔨 К общему крафту', 'callback_data' => 'generalCraft'],
            ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
        ];

        if ($seasonKey === '') {
            return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
                'chat_id'      => $chatId,
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => "🗓 *Сезонный крафт*\n\nСейчас сезонные рецепты недоступны (межсезонье или временно выключено). Загляни позже.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [$backRow]]),
            ]);
        }

        $label      = $svc->getSeasonLabel($seasonKey);
        $daysLeft   = $svc->daysRemainingInSeason();
        $recipeKeys = $svc->getRecipeKeysForActiveSeason();

        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');

        if ($recipeKeys === []) {
            return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
                'chat_id'      => $chatId,
                'photo'        => Request::encodeFile($imagePath),
                'caption'      => "🗓 *Сезон: {$label}*\n\nСезон активен (осталось дней: {$daysLeft}), но рецепты для него ещё готовятся — скоро добавим.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [$backRow]]),
            ]);
        }

        $scope = new \App\Services\Tasks\ActionScopeService();

        $text = "🗓 *Сезонный крафт: {$label}*\n"
              . "Осталось дней в сезоне: *{$daysLeft}*\n"
              . "_Рецепты доступны только в этот сезон. Не успеешь — вернутся в следующем году._\n\n"
              . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n";

        $rows = [];
        foreach ($recipeKeys as $key) {
            $recipe = $cfg->get($key);
            if ($recipe === null) {
                continue;
            }
            $iconRaw = $recipe['icon_emoji'] ?? '🗓';
            $icon    = is_string($iconRaw) ? $iconRaw : '🗓';
            $nameRaw = $recipe['item_name_rus'] ?? $key;
            $name    = is_string($nameRaw) ? $nameRaw : $key;
            $text   .= "{$icon} *{$name}*\n";
            $rows[]  = [['text' => "{$icon} {$name}", 'callback_data' => "craftPreviewSeasonal_{$key}"]];
        }

        $rows[] = $backRow;

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * Картинка хаба сезонного крафта.
     *
     * До 2026-08-16 здесь стоял `general_crafting_img.png` — верстак с ножами и
     * молотком, хотя за экраном 20 рецептов еды и питья: отвары, медовуха, квас,
     * варенье, соленья. Экран превью конкретного рецепта уже брал собственную
     * картинку рецепта, врал только хаб.
     *
     * Файла нет → откат на общий крафт: `Request::encodeFile` на несуществующем
     * пути бросает исключение и убивает экран целиком.
     */
    private static function imageRel(): string
    {
        $rel = 'uploads/telegram/craft/seasonal/seasonal_craft.jpg';

        if (defined('FCPATH') && ! is_file(FCPATH . $rel)) {
            return 'uploads/telegram/craft/general_crafting_img.png';
        }

        return $rel;
    }
}
