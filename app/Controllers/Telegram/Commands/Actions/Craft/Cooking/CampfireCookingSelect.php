<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Cooking;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Notifications\MediaSender;
use App\Services\Telegram\ButtonPacker;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V8 (vNext, Фаза 2) — меню готовки на костре. Farm-to-table payoff для V6/V7:
 * блюда из урожая земледелия.
 *
 * 2026-08-16 — экран разбит НАДВОЕ: `cook` = 🍲 горячее (perishable),
 * `cookPreserves` = 🥫 консервы (preserved). Причина — замер подписи: одним
 * списком при живом `cooking.fish_dishes.enabled` выходило 1124–1206 символов
 * при лимите 1024, из-за чего MediaSender штатно уводил экран в текст и
 * картинку не видел НИКТО. Плюс деление честное по смыслу: горячее портится и
 * едят у костра, консервы не портятся и берут в дорогу.
 *
 * Дополнительно подпись «садится» под лимит сама: {@see fitCaption()} по одной
 * отбрасывает необязательные строки шапки, НИКОГДА не трогая строки рецептов —
 * состав блюда игрок обязан видеть (media-off, ADR-020).
 *
 * Caption самодостаточен (media-off): состав + что восстанавливает.
 */
class CampfireCookingSelect extends BaseAction
{
    /** Лимит подписи фото в Telegram (символы после парсинга разметки). */
    public const CAPTION_LIMIT = 1024;

    /** callback_data экрана консервов (горячее — исторический `cook`). */
    public const CB_PRESERVES = 'cookPreserves';

    /** @var list<string> 🍲 горячее — портится (`perishable`), порядок меню. */
    public const HOT_RECIPES = [
        'MushroomSoup',
        'BerryBrew',
        'BakedFruit',
        'GrainPorridge',
        'HeartyStew',
    ];

    /** @var list<string> 🥫 консервы — shelf-stable (`preserved`), V10. */
    public const PRESERVE_RECIPES = [
        'StewPreserve',
        'DryRation',
    ];

    /**
     * Все блюда готовки (горячее + консервы). Оставлен как единый перечень для
     * anti-drift проверок «ключ меню ↔ рецепт»; на экраны идут HOT/PRESERVE.
     *
     * @var list<string>
     */
    public const COOKING_RECIPES = [
        'MushroomSoup',
        'BerryBrew',
        'BakedFruit',
        'GrainPorridge',
        'HeartyStew',
        'StewPreserve',
        'DryRation',
    ];

    /** @var list<string> W23 — рыбное горячее (killswitch `cooking.fish_dishes.enabled`). */
    public const FISH_HOT_RECIPES = [
        'FishSoup',
        'GrilledFish',
    ];

    /** @var list<string> W23 — рыбная консерва (тот же killswitch). */
    public const FISH_PRESERVE_RECIPES = [
        'FishPreserve',
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

        $preserves = $this->callbackQuery->getData() === self::CB_PRESERVES;

        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');
        $gs  = new GameSettingsService();

        // W23: рыбные добавляются только при включённом killswitch (dormant-safe).
        $fishRaw = $gs->get('cooking.fish_dishes.enabled', false);
        $fishOn  = is_bool($fishRaw) ? $fishRaw : (is_numeric($fishRaw) && (int) $fishRaw === 1);

        $recipeKeys = $preserves ? self::PRESERVE_RECIPES : self::HOT_RECIPES;
        if ($fishOn) {
            $recipeKeys = array_merge(
                $recipeKeys,
                $preserves ? self::FISH_PRESERVE_RECIPES : self::FISH_HOT_RECIPES,
            );
        }

        $scope = new \App\Services\Tasks\ActionScopeService();
        $fb    = new \App\Services\Food\FoodBuffService();

        [, $character] = $this->getUserAndCharacter();
        $wfu = $character !== null ? ($character['well_fed_until'] ?? null) : null;

        $wellFedMinutes = null;
        if ($fb->isWellFed($wfu)) {
            $tsEnd          = is_string($wfu) ? strtotime($wfu) : false;
            $wellFedMinutes = $tsEnd !== false ? max(1, (int) ceil(($tsEnd - time()) / 60)) : 0;
        }

        $dishes      = [];
        $dishButtons = [];
        foreach ($recipeKeys as $key) {
            $recipe = $cfg->get($key);
            if ($recipe === null) {
                continue;
            }
            $icon = isset($recipe['icon_emoji']) && is_string($recipe['icon_emoji']) ? $recipe['icon_emoji'] : '🍲';
            $name = isset($recipe['item_name_rus']) && is_string($recipe['item_name_rus']) ? $recipe['item_name_rus'] : $key;

            // Heal-эффект (data-driven, как UsePharmacyAction резолвит).
            $snake = self::toSnakeCase($key);
            $hpRaw = $gs->get("medical.{$snake}.heal_health", 0);
            $tdRaw = $gs->get("medical.{$snake}.heal_tired", 0);

            $dishes[] = [
                'icon'  => $icon,
                'name'  => $name,
                'cost'  => self::costOf($recipe),
                'hp'    => is_numeric($hpRaw) ? (int) $hpRaw : 0,
                'tired' => is_numeric($tdRaw) ? (int) $tdRaw : 0,
            ];
            $dishButtons[] = ['text' => "{$icon} {$name}", 'callback_data' => "genericCraft_{$key}_1"];
        }

        // Блюда — по 2-3 в ряд (ButtonPacker), а не колонкой: семь кнопок в столбик
        // на телефоне превращают меню в простыню прокрутки.
        $rows = ButtonPacker::pack($dishButtons);

        $text = self::renderText(
            $preserves,
            $dishes,
            $scope->occupancyWarning($scope->isRecipeBackground('MushroomSoup')),
            (! $preserves && $fb->freshnessEnabled()) ? $fb->freshDays() : null,
            $wellFedMinutes,
            $fb->combatEnabled(),
        );

        // Хвост: перекрёстная ссылка на вторую половину готовки + навигация.
        // Пары, а не одиночки в ряду — правило упаковки рядов.
        $rows[] = [
            $preserves
                ? ['text' => '🍲 Горячее', 'callback_data' => 'cook']
                : ['text' => '🥫 Консервы', 'callback_data' => self::CB_PRESERVES],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];
        $rows[] = [
            ['text' => '🔨 К общему крафту', 'callback_data' => 'generalCraft'],
            ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
        ];

        $imagePath = base_url('uploads/telegram/craft/general_crafting_img.png');

        return MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * Собирает текст экрана готовки. Чистая функция от данных — поэтому длину
     * подписи можно гейтить тестом, а не ловить на проде по пропавшей картинке.
     *
     * @param  bool                                                                    $preserves      экран консервов (иначе горячее)
     * @param  list<array{icon:string,name:string,cost:string,hp:int,tired:int}>        $dishes         строки блюд, в порядке показа
     * @param  string                                                                  $occupancy      предупреждение о занятости (⏳/🔒)
     * @param  int|null                                                                $freshDays      срок свежести, null — строку не показывать
     * @param  int|null                                                                $wellFedMinutes остаток «Сытости», null — не сыт
     * @param  bool                                                                    $combatEnabled  сытость влияет на бой (ADR-121)
     */
    public static function renderText(
        bool $preserves,
        array $dishes,
        string $occupancy,
        ?int $freshDays,
        ?int $wellFedMinutes,
        bool $combatEnabled,
    ): string {
        // Шапка: заголовок + предупреждение о занятости — остаются ВСЕГДА.
        $header = ($preserves ? "🥫 *Костёр — консервы*\n" : "🔥 *Костёр — горячее*\n")
            . $occupancy . "\n";

        // Необязательные строки шапки — в порядке ПОКАЗА (ключ → строка).
        $optional          = [];
        $optional['intro'] = $preserves
            ? "_Заготовки в дорогу: не портятся и держат сытость дольше горячего, но дороже по сырью._\n"
            : "_Блюда из урожая и улова: восстанавливают здоровье и особенно выносливость._\n";

        // Свежесть касается только горячего — консервы по определению всегда свежи.
        if (! $preserves && $freshDays !== null) {
            $optional['fresh'] = "_Свежее блюдо ({$freshDays} дн.) даёт полную сытость, залежалое — меньше. Лечит всегда._\n";
        }

        if ($wellFedMinutes !== null) {
            // E21 Ф1 (ADR-121): боевое измерение — сытость даёт бонус в PvE (охота/север).
            $combatNote          = $combatEnabled ? ', в бою сильнее' : '';
            $optional['wellfed'] = "🍖 _Сытость активна (ещё ~{$wellFedMinutes} мин): крафт быстрее, добыча щедрее{$combatNote}._\n";
        } elseif ($combatEnabled) {
            // Мотивация нужна только тем, у кого сытости СЕЙЧАС нет: сытому про бой
            // уже сказано строкой выше, дубль сжирал бы лимит подписи впустую.
            $optional['combat'] = "_⚔️ Поешь перед охотой на элиток и походом на север._\n";
        }

        // Строки блюд — неприкосновенны: это и есть содержание экрана.
        $body = "\n";
        foreach ($dishes as $d) {
            $body .= "{$d['icon']} *{$d['name']}* — ❤️+{$d['hp']} / ⚡+{$d['tired']}\n"
                . "   _{$d['cost']}_\n";
        }

        return self::fitCaption($header, $optional, $body, ['combat', 'fresh', 'intro', 'wellfed']);
    }

    /**
     * Состав рецепта строкой «Ресурс×N, Ресурс×N» — ровно то, что видит игрок.
     *
     * @param array<string,mixed> $recipe
     */
    public static function costOf(array $recipe): string
    {
        $parts = [];
        if (isset($recipe['resources']) && is_array($recipe['resources'])) {
            foreach ($recipe['resources'] as $resName => $qty) {
                $parts[] = (is_string($resName) ? $resName : '') . '×' . (is_numeric($qty) ? (int) $qty : 0);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Собирает подпись, укладывая её в лимит фото: `$header` и `$body` остаются
     * всегда, необязательные строки шапки отбрасываются ПО ОДНОЙ в порядке
     * `$dropOrder`, пока подпись не сядет под лимит.
     *
     * Почему так, а не «обрезать хвост»: обрезка съела бы состав блюд, а он —
     * весь смысл экрана в media-off режиме (ADR-020). Гарнир из мотивирующих
     * строк потерять не жалко, данные — нельзя. Если после отбрасывания всего
     * необязательного подпись всё ещё длинная, дальше сработает штатная
     * деградация MediaSender (то же содержимое уходит текстом, лимит 4096).
     *
     * @param  array<string,string> $optional  ключ → строка, в порядке ПОКАЗА
     * @param  list<string>         $dropOrder ключи в порядке ОТБРАСЫВАНИЯ
     */
    public static function fitCaption(string $header, array $optional, string $body, array $dropOrder): string
    {
        $build = static fn (array $opt): string => $header . implode('', $opt) . $body;

        $caption = $build($optional);
        foreach ($dropOrder as $key) {
            if (! MediaSender::captionExceedsPhotoLimit($caption)) {
                return $caption;
            }
            unset($optional[$key]);
            $caption = $build($optional);
        }

        return $caption;
    }

    /** name_eng → setting-key segment (зеркало UsePharmacyAction::toSnakeCase). */
    private static function toSnakeCase(string $name): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $s = str_replace([' ', '-'], '_', $s);
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        return strtolower(trim($s, '_'));
    }
}
