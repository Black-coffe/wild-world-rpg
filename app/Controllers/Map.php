<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\BiomeModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Services;

/**
 * Публичная карта мира 1000×1000 (MVP-v2, ADR-N/A — 🟢 wiki-style расширение).
 *
 * Видимость публичных слоёв: караваны (V25), активные мировые события, биом-tint.
 * Скрыто от паблика (admin-only — нужен ADR-🟠 если откроем): игроки, базы, схроны/world_objects.
 */
class Map extends BaseController
{
    public function index(): string
    {
        $biomes  = (new BiomeModel())->orderBy('id')->findAll();
        $session = Services::session();
        $tgUserId  = $session->get('tg_user_id');
        $firstName = $session->get('tg_first_name');
        $authState = [
            'logged_in'  => is_numeric($tgUserId) && (int) $tgUserId > 0,
            'first_name' => is_string($firstName) ? $firstName : '',
        ];
        $rawBotName  = env('telegram.BOT_USERNAME');
        $botUsername = is_string($rawBotName) ? ltrim($rawBotName, '@') : '';

        $canonical = rtrim(base_url('map'), '/');
        $title     = 'Карта мира — Wild World';
        $descr     = 'Интерактивная карта Wild World 1000×1000: переключение между попиксельной биом-картой и художественной подложкой, координатная сетка, караваны, активные события, легенда биомов.';

        $meta = [
            'title'       => $title,
            'description' => $descr,
            'canonical'   => $canonical,
            'ogImage'     => null,
            'robots'      => 'index,follow',
            'ogType'      => 'website',
            'keywords'    => 'карта Wild World, биомы, караваны, события, мир игры, текстовая MMORPG, Telegram',
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => rtrim(base_url(), '/') . '/'],
                ['name' => 'Карта мира', 'url' => $canonical],
            ],
        ];

        return view('site/map', [
            'biomes'      => $biomes,
            'meta'        => $meta,
            'auth'        => $authState,
            'botUsername' => $botUsername,
        ]);
    }

    /**
     * JSON-snapshot публичных слоёв карты.
     * Караваны → точки с координатой клетки + что продают + срок.
     * События → текстовая инфо-панель (тип + затронутые биомы + эффект + срок).
     *
     * Скрыто от паблика (ADR требуется чтобы открыть): игроки, базы, схроны.
     */
    public function data(): ResponseInterface
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $caravans = [];
        if ($db->tableExists('caravans')) {
            $caravanQuery = $db->table('caravans AS c')
                ->select('c.id, c.cell_number, c.quantity, c.price_per_unit, c.expires_at, m.coordinate_x, m.coordinate_y, r.name AS resource_name')
                ->join('map AS m', 'm.cell_number = c.cell_number', 'left')
                ->join('resources AS r', 'r.id = c.resource_id', 'left')
                ->where('c.status', 'active')
                ->where('c.expires_at >', $now)
                ->orderBy('c.expires_at', 'ASC')
                ->limit(100)
                ->get();
            $caravanRows = $caravanQuery !== false ? $caravanQuery->getResultArray() : [];
            foreach ($caravanRows as $row) {
                $caravans[] = [
                    'id'       => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                    'x'        => is_numeric($row['coordinate_x'] ?? null) ? (int) $row['coordinate_x'] : 0,
                    'y'        => is_numeric($row['coordinate_y'] ?? null) ? (int) $row['coordinate_y'] : 0,
                    'resource' => is_string($row['resource_name'] ?? null) ? $row['resource_name'] : '?',
                    'qty'      => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0,
                    'price'    => is_numeric($row['price_per_unit'] ?? null) ? (int) $row['price_per_unit'] : 0,
                    'expires'  => is_string($row['expires_at'] ?? null) ? $row['expires_at'] : '',
                ];
            }
        }

        $events     = [];
        $eventQuery = $db->table('active_events AS ae')
            ->select('e.event_id, e.name, e.event_type, e.biome_ids, e.effect_type, e.effect_value, ae.end_time')
            ->join('events AS e', 'e.event_id = ae.event_id', 'left')
            ->where('ae.status', 'active')
            ->where('ae.end_time >', $now)
            ->orderBy('ae.end_time', 'ASC')
            ->limit(50)
            ->get();
        $eventRows = $eventQuery !== false ? $eventQuery->getResultArray() : [];
        foreach ($eventRows as $row) {
            $rawBiomes  = is_string($row['biome_ids'] ?? null) ? $row['biome_ids'] : '';
            $biomeIds   = [];
            if ($rawBiomes !== '') {
                $decoded = json_decode($rawBiomes, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $b) {
                        if (is_numeric($b)) {
                            $biomeIds[] = (int) $b;
                        }
                    }
                } else {
                    foreach (explode(',', $rawBiomes) as $b) {
                        $b = trim($b);
                        if (is_numeric($b)) {
                            $biomeIds[] = (int) $b;
                        }
                    }
                }
            }
            $events[] = [
                'name'      => is_string($row['name'] ?? null) ? $row['name'] : '?',
                'type'      => is_string($row['event_type'] ?? null) ? $row['event_type'] : 'global',
                'biome_ids' => $biomeIds,
                'effect'    => is_string($row['effect_type'] ?? null) ? $row['effect_type'] : 'none',
                'ends_at'   => is_string($row['end_time'] ?? null) ? $row['end_time'] : '',
            ];
        }

        // ADR-061: own-position block если игрок auth'ован
        $me       = null;
        $session  = Services::session();
        $tgUserPk = $session->get('tg_user_id');
        if (is_numeric($tgUserPk) && (int) $tgUserPk > 0) {
            $tgUserPk = (int) $tgUserPk;
            $meRow = $db->table('characters AS c')
                ->select('c.id, c.name, c.level, c.cell_number, m.coordinate_x, m.coordinate_y')
                ->join('map AS m', 'm.cell_number = c.cell_number', 'left')
                ->where('c.telegram_user_id', $tgUserPk)
                ->limit(1)
                ->get();
            $meRows = $meRow !== false ? $meRow->getResultArray() : [];
            if (count($meRows) === 1) {
                $row = $meRows[0];
                if (is_numeric($row['coordinate_x'] ?? null) && is_numeric($row['coordinate_y'] ?? null)) {
                    $me = [
                        'x'     => (int) $row['coordinate_x'],
                        'y'     => (int) $row['coordinate_y'],
                        'name'  => is_string($row['name']  ?? null) ? $row['name']  : '?',
                        'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
                    ];
                }
            }
        }

        return $this->response
            ->setJSON([
                'caravans'   => $caravans,
                'events'     => $events,
                'me'         => $me,
                'fetched_at' => $now,
            ])
            ->setHeader('Cache-Control', 'private, max-age=15');
    }
}
