<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Игра на сайте — код из бота» (TIPS-COVERAGE, CLAUDE.md; web-accounts-p0-06, ADR-188).
 *
 * `/web` или кнопка «🌐 Играть на сайте» в «⚙️ Настройках» выдают одноразовый код; игрок вводит
 * его на wildworld.fun/account/link и входит в аккаунт персонажа (или привязывает к нему вход
 * почтой / Google / Яндексом). Без чисел: срок жизни кода называет само сообщение с кодом.
 *
 * Idempotent по title_en='WebLinkCode'. media-off самодостаточен, markdown-safe (парные *),
 * категория «настройки» из 14 ENUM (вход — экран «⚙️ Настройки»). game_tips = KEEP (WipeManifest).
 */
class SeedWebLinkTip extends Migration
{
    private const TITLE_EN = 'WebLinkCode';

    public function up(): void
    {
        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $now     = date('Y-m-d H:i:s');
        $content = '🌐 *Играть можно и на сайте.* Набери /web или нажми *«🌐 Играть на сайте»* в '
            . '*⚙️ Настройках* — я пришлю одноразовый код. Введи его на странице wildworld.fun/account/link: '
            . 'код впустит тебя в аккаунт твоего персонажа, а если ты уже вошёл почтой, Google или Яндексом — '
            . 'привяжет этот вход к персонажу. Код живёт недолго и срабатывает один раз, так что '
            . 'запрашивай его прямо перед входом. Бот при этом никуда не денется.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🌐 Игра на сайте — код из бота',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'настройки',
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
