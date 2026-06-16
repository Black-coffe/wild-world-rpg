<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\BattleLogModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\FactionModel;
use App\Services\Player\AchievementService;
use App\Services\Player\CollectionService;
use App\Services\Player\TitleService;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * E30 (ROADMAP-100 §E-D) — публичный веб-профиль персонажа (flat ADR-062, viral-петля).
 *
 * Игроку есть что показать наружу: уровень, активный титул, фракция, открытые достижения
 * (+ глобальный % как на /achievements), заработанные титулы, завершённые коллекции и PvP-сводка.
 * Из бота кнопка «🔗 Профиль» ведёт на `/profile/{id}` — бесплатный приток + хвастовство.
 *
 * 🔒 Приватность (память feedback_web_tier_privacy_own_only): страница ПУБЛИЧНАЯ (как /battles,
 * /achievements), но НИКОГДА не рендерит локацию/координаты/базу/золото/здоровье — только
 * brag-данные (нет наводки для PvP-таргетинга). Свой профиль (session.character_id == id) видит
 * баннер «поделись ссылкой»; чужой — чистый публичный вид. Все запросы read-only.
 *
 * Тиринг авторизации — общая Telegram-сессия ADR-061 (как AchievementsController/BattlesController).
 */
class ProfileController extends Controller
{
    public function view(int|string $id): string
    {
        $charId = is_numeric($id) ? (int) $id : 0;
        $char   = $charId > 0 ? (new CharacterModel())->find($charId) : null;
        if ($char === null) {
            throw PageNotFoundException::forPageNotFound("Профиль #{$id} не найден.");
        }
        $c = is_object($char) && method_exists($char, 'toArray') ? $char->toArray() : (array) $char;

        $name  = is_string($c['name'] ?? null) && trim($c['name']) !== '' ? trim($c['name']) : 'Выживший';
        $level = is_numeric($c['level'] ?? null) ? (int) $c['level'] : 1;
        $kills = is_numeric($c['npc_kills'] ?? null) ? (int) $c['npc_kills'] : 0;
        $str   = is_numeric($c['strength'] ?? null) ? (float) $c['strength'] : 0.0;
        $agi   = is_numeric($c['agility'] ?? null) ? (float) $c['agility'] : 0.0;
        $intl  = is_numeric($c['intellect'] ?? null) ? (float) $c['intellect'] : 0.0;

        // Дней в пустоши (created_at) — без утечки времени онлайна, просто стаж.
        $createdRaw = $c['created_at'] ?? null;
        $createdStr = is_scalar($createdRaw) ? (string) $createdRaw : '';
        $ts         = $createdStr !== '' ? strtotime($createdStr) : false;
        $daysAlive  = is_int($ts) && $ts > 0 ? max(0, (int) floor((time() - $ts) / 86400)) : 0;

        // Фракция (паттерн CharacterService): character_faction → factions.name; нет/5 → Нейтралы.
        $factionName = 'Нейтралы';
        $cf          = (new CharacterFactionModel())->where('character_id', $charId)->first();
        if (is_array($cf) && is_numeric($cf['faction_id'] ?? null)) {
            $faction = (new FactionModel())->find((int) $cf['faction_id']);
            if (is_array($faction) && is_string($faction['name'] ?? null) && $faction['name'] !== '') {
                $factionName = $faction['name'];
            }
        }

        // Активный титул.
        $titleSvc   = new TitleService();
        $activeT    = $titleSvc->activeTitle($charId);
        $activeIcon = is_array($activeT) && is_string($activeT['icon'] ?? null) && $activeT['icon'] !== '' ? $activeT['icon'] : '';
        $activeName = is_array($activeT) && is_string($activeT['name'] ?? null) ? $activeT['name'] : '';

        // Заработанные титулы + редкость (showcase).
        $earnedIds = $titleSvc->unlockedTitleIds($charId);
        $earnedSet = array_fill_keys($earnedIds, true);
        $titleRar  = $titleSvc->rarityPercentMap();
        $titleDefs = $titleSvc->definitions();
        $myTitles  = [];
        foreach ($titleDefs as $t) {
            $tid = is_numeric($t['id'] ?? null) ? (int) $t['id'] : 0;
            if ($tid > 0 && isset($earnedSet[$tid])) {
                $myTitles[] = [
                    'icon' => is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🎖',
                    'name' => is_string($t['name'] ?? null) ? $t['name'] : '',
                    'pct'  => $titleRar[$tid] ?? 0.0,
                ];
            }
        }
        $titlesTotal = count($titleDefs);

        // Достижения (глобальный % как на /achievements) + открытые этим персонажем.
        $achSvc      = new AchievementService();
        $achData     = $achSvc->statsWithPercent();
        $allAch      = $achData['achievements'];
        $achTotal    = count($allAch);
        $unlockedSet = array_fill_keys($achSvc->unlockedAchievementIds($charId), true);
        $myAch       = [];
        foreach ($allAch as $a) {
            $aid = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
            if ($aid > 0 && isset($unlockedSet[$aid])) {
                $myAch[] = [
                    'icon'  => is_string($a['icon'] ?? null) && $a['icon'] !== '' ? $a['icon'] : '🏅',
                    'title' => is_string($a['title'] ?? null) ? $a['title'] : '',
                    'pct'   => is_numeric($a['percent'] ?? null) ? (float) $a['percent'] : 0.0,
                ];
            }
        }
        $achCount = count($myAch);

        // Коллекции (музей): сколько завершено.
        $collSvc    = new CollectionService();
        $collDefs   = $collSvc->definitions();
        $collTotal  = count($collDefs);
        $collDone   = 0;
        foreach ($collDefs as $coll) {
            $cid = is_numeric($coll['id'] ?? null) ? (int) $coll['id'] : 0;
            if ($cid > 0 && $collSvc->isComplete($charId, $cid)) {
                $collDone++;
            }
        }

        // PvP-сводка (отдельные инстансы модели — избегаем builder-state-quirk).
        $pvpTotal = (new BattleLogModel())->where('battle_type', 'PVP')
            ->groupStart()->where('player1_id', $charId)->orWhere('player2_id', $charId)->groupEnd()
            ->countAllResults();
        $pvpWins = (new BattleLogModel())->where('battle_type', 'PVP')->where('winner_id', $charId)->countAllResults();

        // Изучено клеток (счётчик-хвастовство, не локация).
        $explored = (new ExploredCellsModel())->where('character_id', $charId)->countAllResults();

        $auth  = $this->authContext();
        $isOwn = $auth['characterId'] > 0 && $auth['characterId'] === $charId;
        $base  = rtrim(base_url(), '/');
        $url   = $base . '/profile/' . $charId;

        $titleTag   = trim($activeIcon . ' ' . $activeName);
        $ogTitle    = $name . ' · ' . $level . ' ур.' . ($titleTag !== '' ? ' · ' . $titleTag : '') . ' — Wild World';
        $ogDesc     = 'Достижений ' . $achCount . '/' . $achTotal . ' · титулов ' . count($myTitles)
            . ' · PvP-побед ' . $pvpWins . ' · ' . $daysAlive . ' дн. в пустоши. Профиль выжившего Wild World.';

        return view('site/profile/view', [
            'name'         => $name,
            'level'        => $level,
            'factionName'  => $factionName,
            'activeIcon'   => $activeIcon,
            'activeName'   => $activeName,
            'daysAlive'    => $daysAlive,
            'kills'        => $kills,
            'str'          => $str,
            'agi'          => $agi,
            'intl'         => $intl,
            'achCount'     => $achCount,
            'achTotal'     => $achTotal,
            'myAch'        => $myAch,
            'myTitles'     => $myTitles,
            'titlesTotal'  => $titlesTotal,
            'collDone'     => $collDone,
            'collTotal'    => $collTotal,
            'pvpTotal'     => $pvpTotal,
            'pvpWins'      => $pvpWins,
            'explored'     => $explored,
            'profileUrl'   => $url,
            'battlesUrl'   => $base . '/battles?q=' . rawurlencode($name),
            'isOwn'        => $isOwn,
            'authed'       => $auth['authed'],
            'botUsername'  => $auth['botUsername'],
            'meta'         => [
                'title'       => $ogTitle,
                'description' => $ogDesc,
                'canonical'   => $url,
                'robots'      => 'index,follow',
                'breadcrumbs' => [
                    ['name' => 'Главная', 'url' => $base . '/'],
                    ['name' => 'Профиль ' . $name, 'url' => $url],
                ],
                'jsonld'      => [[
                    '@type'       => 'Person',
                    'name'        => $name,
                    'url'         => $url,
                    'description' => 'Уровень ' . $level . ', фракция ' . $factionName . ', выживший Wild World.',
                ]],
            ],
        ]);
    }

    /**
     * Контекст авторизации через Telegram (общая сессия ADR-061) — как Achievements/BattlesController.
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
