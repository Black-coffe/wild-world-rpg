<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 3 (рефайн) — admin-tunable параметры фракционных лавок оплотов.
 * GameSettings `settlements.shop.*` (category=world).
 *
 *  - settlements.shop.enabled              — killswitch (DEFAULT false = dormant).
 *  - settlements.shop.member_discount_pct  — скидка члену своей фракции (%).
 *  - settlements.shop.other_markup_pct     — наценка не-члену (другая/без фракции) (%).
 *
 * Читается {@see App\Services\Settlement\SettlementShopService}. Idempotent. Rich rationale (ADR-024).
 * Не создаёт таблиц/player-колонок → WipeManifest не трогаем (game_settings уже KEEP).
 */
class SettlementShopGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->boolRow('settlements.shop.enabled', 'world', false, 'false', null, null, null, null,
            'Killswitch фракционных лавок оплотов («unique-stock» — покупка тематичных предметов фракции за золото). DEFAULT false — dormant до балансовой валидации владельца.',
            'SettlementShopService::enabled(). OFF → кнопка «🛍 Лавка фракции» скрыта в хабе оплота, покупки запрещены (byte-identical мир).',
            'true (ON): в оплотах можно покупать курируемый ассортимент существующих предметов (оружие/броня) — выбор фракции получает ощутимую ценность.',
            'false (OFF): оплоты дают только продажу ресурсов (sell) и проект фракции — покупки уникального ассортимента нет.');

        $rows[] = $this->intRow('settlements.shop.member_discount_pct', 'world', 15, '15', '0', '40', '0', '90',
            'Скидка члену СВОЕЙ фракции при покупке в лавке её оплота (% от базовой цены). 15% — заметная привилегия «свой», не ломающая экономику.',
            'SettlementShopService::priceFor(): своя фракция → base × (100−pct)/100. Поощряет покупать у своих.',
            'Выше (40+): покупка у своих почти даром → обесценивает gold-sink и крафт-аналоги.',
            'Ниже (0): привилегия «свой» исчезает, выбор фракции теряет торговую ценность.');

        $rows[] = $this->intRow('settlements.shop.other_markup_pct', 'world', 25, '25', '0', '100', '0', '500',
            'Наценка не-члену (чужая фракция или без фракции) при покупке в чужом оплоте (% сверх базовой цены). 25% — «чужак платит дороже», но доступ не закрыт (gold-sink).',
            'SettlementShopService::priceFor(): не своя → base × (100+pct)/100. Свои товары дешевле для своих, дороже для чужих.',
            'Выше (100+): чужакам почти недоступно → лавки становятся строго внутрифракционными.',
            'Ниже (0): нет разницы свой/чужой → принадлежность к фракции не влияет на цену.');

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge([
                'value_int' => null, 'value_float' => null, 'value_bool' => null, 'value_string' => null,
                'recommended_min' => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $row));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function intRow(string $key, string $cat, int $int, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return [
            'setting_key' => $key, 'category' => $cat, 'value_type' => 'int', 'value_int' => $int,
            'default_value_text' => $defaultText, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $recMin, 'recommended_max' => $recMax, 'hard_min' => $hardMin, 'hard_max' => $hardMax,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function boolRow(string $key, string $cat, bool $bool, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return [
            'setting_key' => $key, 'category' => $cat, 'value_type' => 'bool', 'value_bool' => $bool ? 1 : 0,
            'default_value_text' => $defaultText, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $recMin, 'recommended_max' => $recMax, 'hard_min' => $hardMin, 'hard_max' => $hardMax,
        ];
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'settlements.shop.enabled',
                'settlements.shop.member_discount_pct',
                'settlements.shop.other_markup_pct',
            ])
            ->delete();
    }
}
