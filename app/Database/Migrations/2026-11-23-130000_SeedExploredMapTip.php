<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE — появилась личная карта исследованного.
 *
 * Повод: вопрос игрока (Анжела, 18.08.2026) «нет ли ресурса, показывающего открытую
 * карту?». Туман войны копился с первого шага (`explored_cells`), но игроку не
 * показывался нигде: «🗺 Обзор» рисует весь мир и не знает, где ты был.
 *
 * Инварианты (ADR-134): про навигацию и понятия, без чисел баланса; markdown-safe
 * (парные *); категория «общие» из 14 ENUM; идемпотентность по `title_en`; media-off
 * (ADR-020) — текст самодостаточен, картинка на экране только показывает форму пятна.
 */
class SeedExploredMapTip extends Migration
{
    private const TITLE_EN = 'ExploredMapScreen';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🔍 *Теперь видно, сколько мира ты открыл.* На экране *«🌍 Мир»* → *«🗺 Обзор»* '
            . 'появилась кнопка *«🔍 Что я открыл»* — это твоя личная карта, а не общая.'
            . "\n\n"
            . 'Там написано, сколько клеток снято с тумана, в каких ты границах и в каких биомах '
            . 'успел побывать. На картинке открытые клетки закрашены цветом своего биома, '
            . 'непройденное вокруг — тёмное, а твоя нынешняя клетка отмечена красным.'
            . "\n\n"
            . 'Карта открывается сама, пока ходишь: каждый шаг снимает туман с соседних клеток. '
            . 'Разом большой кусок открывает дрон-разведчик.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🔍 Личная карта: что ты уже открыл',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', self::TITLE_EN)->delete();
    }
}
