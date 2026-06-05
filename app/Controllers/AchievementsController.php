<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Player\AchievementService;
use CodeIgniter\Controller;

/**
 * Asana «Визуальная таблица достижений на сайте» (Steam/Монобанк-стиль) — публичная страница
 * достижений wildworld.fun (flat ADR-062). Сетка карточек с иконкой, описанием и ГЛОБАЛЬНЫМ
 * процентом игроков на каждом достижении (процент, не число — чтобы не демотивировать).
 *
 * Тиринг (как /battles, ADR-092): паблик видит все достижения + глобальный %; залогиненный через
 * Telegram (сессия ADR-061) — ДОПОЛНИТЕЛЬНО отметку ✅/🔒 своих + прогресс X/Y на незакрытых.
 * Личные данные — только для СВОЕГО персонажа (приватность own-only).
 *
 * Бэкенд достижений — W9/W10 (ADR-066): AchievementService + таблицы achievements/character_achievements.
 */
class AchievementsController extends Controller
{
    public function publicIndex(): string
    {
        $svc  = new AchievementService();
        $auth = $this->authContext();
        $data = $svc->statsWithPercent();

        $achievements = $data['achievements'];
        $total        = $data['total'];

        // Личный слой — только для своего персонажа.
        $myUnlocked = [];
        $myProgress = [];
        $myCount    = 0;
        if ($auth['characterId'] > 0) {
            foreach ($svc->unlockedAchievementIds($auth['characterId']) as $aid) {
                $myUnlocked[$aid] = true;
            }
            $myCount = count($myUnlocked);
            foreach ($achievements as $a) {
                $aid  = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
                $type = is_string($a['criteria_type'] ?? null) ? $a['criteria_type'] : '';
                if ($aid > 0 && ! isset($myUnlocked[$aid])) {
                    $myProgress[$aid] = $svc->currentValue($auth['characterId'], $type);
                }
            }
        }

        // Группировка по категориям (порядок — как пришли, sort_order).
        $byCategory = [];
        foreach ($achievements as $a) {
            $cat = is_string($a['category'] ?? null) && $a['category'] !== '' ? $a['category'] : 'general';
            $byCategory[$cat][] = $a;
        }

        $base = rtrim(base_url(), '/');

        return view('site/achievements/index', [
            'byCategory'   => $byCategory,
            'total'        => $total,
            'achCount'     => count($achievements),
            'myUnlocked'   => $myUnlocked,
            'myProgress'   => $myProgress,
            'myCount'      => $myCount,
            'authed'       => $auth['authed'],
            'tgName'       => $auth['name'],
            'botUsername'  => $auth['botUsername'],
            'hasChar'      => $auth['characterId'] > 0,
            'meta'         => [
                'title'       => 'Достижения выживших — Wild World',
                'description' => 'Все достижения Wild World: за крафт, исследование, выживание и не только. У каждого — процент игроков, что его открыли. Войди через Telegram и смотри свой прогресс.',
                'canonical'   => $base . '/achievements',
                'robots'      => 'index,follow',
                'breadcrumbs' => [
                    ['name' => 'Главная', 'url' => $base . '/'],
                    ['name' => 'Достижения', 'url' => $base . '/achievements'],
                ],
            ],
        ]);
    }

    /**
     * Контекст авторизации через Telegram (общая сессия ADR-061) — как BattlesController.
     *
     * @return array{authed:bool, name:string, botUsername:string, characterId:int}
     */
    private function authContext(): array
    {
        $session = session();
        $tgId    = $session->get('tg_user_id');
        $authed  = is_numeric($tgId) && (int) $tgId > 0;

        $charIdRaw   = $session->get('character_id');
        $characterId = ($authed && is_numeric($charIdRaw)) ? (int) $charIdRaw : 0;

        $name = '';
        if ($authed) {
            $uname = $session->get('tg_username');
            $fname = $session->get('tg_first_name');
            if (is_string($uname) && $uname !== '') {
                $name = '@' . ltrim($uname, '@');
            } elseif (is_string($fname) && $fname !== '') {
                $name = $fname;
            } else {
                $name = 'выживший';
            }
        }

        $rawBot = env('telegram.BOT_USERNAME');

        return [
            'authed'      => $authed,
            'name'        => $name,
            'botUsername' => is_string($rawBot) ? ltrim($rawBot, '@') : '',
            'characterId' => $characterId,
        ];
    }
}
