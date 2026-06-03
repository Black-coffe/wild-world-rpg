<?php

namespace App\Controllers;

use App\Entities\BattleLogEntity;
use App\Models\BattleLogModel;
use App\Models\CharacterModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\I18n\Time;

class BattlesController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Admin (за login-фильтром, /admin/battles) — legacy review-вьюхи.
    // ─────────────────────────────────────────────────────────────────────────

    /** Показываем список последних 10 боёв (admin). */
    public function index(): string
    {
        $model   = new BattleLogModel();
        $battles = $model->orderBy('id', 'DESC')->limit(10)->findAll();

        return view('battles/index', ['battles' => $battles]);
    }

    /** Просмотр детальной информации о конкретном бое (admin). */
    public function view(int|string $id): string
    {
        $model  = new BattleLogModel();
        $battle = $model->find((int) $id);
        if (! $battle instanceof BattleLogEntity) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound("Бой #{$id} не найден.");
        }
        $logData = json_decode((string) $battle->log_data, true);

        return view('battles/view', ['battle' => $battle, 'logData' => $logData]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public (flat ADR-062, /battles) — Asana «Расширение логирования PVP» Фаза 2.
    // Публичный тир: общая статистика. Координаты НЕ рендерятся (gated за Фазой 3 TG-auth).
    // ─────────────────────────────────────────────────────────────────────────

    /** Публичный список боёв: поиск по имени игрока (?q=) / переход по ID боя (?id=) + пагинация. */
    public function publicIndex(): RedirectResponse|string
    {
        $qRaw = $this->request->getGet('q');
        $q    = is_string($qRaw) ? trim($qRaw) : '';

        $idRaw  = $this->request->getGet('id');
        $idJump = is_numeric($idRaw) ? (int) $idRaw : 0;
        if ($idJump > 0) {
            return redirect()->to(site_url('battles/view/' . $idJump));
        }

        $model   = new BattleLogModel();
        $builder = $model->where('battle_type', 'PVP');

        if ($q !== '') {
            $found   = (new CharacterModel())->like('name', $q)->findColumn('id') ?? [];
            $charIds = array_map(static fn ($v): int => is_numeric($v) ? (int) $v : 0, $found);
            if ($charIds === []) {
                $charIds = [-1]; // нет совпадений → пустой результат
            }
            $builder = $builder->groupStart()
                ->whereIn('player1_id', $charIds)
                ->orWhereIn('player2_id', $charIds)
                ->groupEnd();
        }

        $battles = $builder->orderBy('id', 'DESC')->paginate(20);
        $pager   = $model->pager;

        $rows = [];
        foreach ($battles as $b) {
            if ($b instanceof BattleLogEntity) {
                $rows[] = $this->summaryRow($b);
            }
        }

        $base = rtrim(base_url(), '/');

        return view('site/battles/index', [
            'rows'  => $rows,
            'q'     => $q,
            'pager' => $pager,
            'meta'  => [
                'title'       => 'Хроника PvP-боёв — Wild World',
                'description' => 'Публичная хроника дуэлей и схваток выживших: кто кого, на каком уровне, с каким оружием и чем кончилось.',
                'canonical'   => $base . '/battles',
                'robots'      => 'index,follow',
                'breadcrumbs' => [
                    ['name' => 'Главная', 'url' => $base . '/'],
                    ['name' => 'Хроника боёв', 'url' => $base . '/battles'],
                ],
            ],
        ]);
    }

    /** Публичная детальная страница боя (flat). */
    public function publicView(int|string $id): string
    {
        $model  = new BattleLogModel();
        $battle = $model->find((int) $id);
        if (! $battle instanceof BattleLogEntity) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound("Бой #{$id} не найден.");
        }

        $logRaw = json_decode((string) $battle->log_data, true);
        $log    = is_array($logRaw) ? $logRaw : [];

        $chars  = is_array($log['characters'] ?? null) ? $log['characters'] : [];
        $att    = is_array($chars['attacker'] ?? null) ? $chars['attacker'] : [];
        $def    = is_array($chars['defender'] ?? null) ? $chars['defender'] : [];
        $rounds = is_array($log['rounds'] ?? null) ? array_values($log['rounds']) : [];
        $oc     = is_array($log['outcome'] ?? null) ? $log['outcome'] : [];

        $winnerId = is_numeric($oc['winnerId'] ?? null)
            ? (int) $oc['winnerId']
            : ($battle->winner_id ?? null);

        $attName = is_scalar($att['name'] ?? null) ? (string) $att['name'] : '?';
        $defName = is_scalar($def['name'] ?? null) ? (string) $def['name'] : '?';
        $base    = rtrim(base_url(), '/');

        return view('site/battles/view', [
            'battle'   => $battle,
            'createdAt' => $this->dateStr($battle->created_at),
            'att'      => $att,
            'def'      => $def,
            'rounds'   => $rounds,
            'winnerId' => $winnerId,
            'outcome'  => $oc,
            'biome'    => is_string($log['biome'] ?? null) ? $log['biome'] : null,
            'version'  => is_numeric($log['version'] ?? null) ? (int) $log['version'] : 1,
            'meta'     => [
                'title'       => "Бой #{$battle->id} — {$attName} против {$defName} — Wild World",
                'description' => 'Детальный разбор PvP-боя: уровни, параметры, экипировка и ход схватки по раундам.',
                'canonical'   => $base . '/battles/view/' . $battle->id,
                'robots'      => 'index,follow',
                'breadcrumbs' => [
                    ['name' => 'Главная', 'url' => $base . '/'],
                    ['name' => 'Хроника боёв', 'url' => $base . '/battles'],
                    ['name' => "Бой #{$battle->id}", 'url' => $base . '/battles/view/' . $battle->id],
                ],
            ],
        ]);
    }

    /**
     * Строка списка (имена/исход из log_data — историческая точность).
     *
     * @return array<string,mixed>
     */
    private function summaryRow(BattleLogEntity $b): array
    {
        $ldRaw = json_decode((string) $b->log_data, true);
        $ld    = is_array($ldRaw) ? $ldRaw : [];
        $chars = is_array($ld['characters'] ?? null) ? $ld['characters'] : [];
        $att   = is_array($chars['attacker'] ?? null) ? $chars['attacker'] : [];
        $def   = is_array($chars['defender'] ?? null) ? $chars['defender'] : [];
        $oc    = is_array($ld['outcome'] ?? null) ? $ld['outcome'] : [];

        $winnerId = is_numeric($oc['winnerId'] ?? null) ? (int) $oc['winnerId'] : $b->winner_id;

        return [
            'id'            => $b->id,
            'date'          => $this->dateStr($b->created_at),
            'attacker'      => is_scalar($att['name'] ?? null) ? (string) $att['name'] : '—',
            'defender'      => is_scalar($def['name'] ?? null) ? (string) $def['name'] : '—',
            'attackerLevel' => is_numeric($att['level'] ?? null) ? (int) $att['level'] : null,
            'defenderLevel' => is_numeric($def['level'] ?? null) ? (int) $def['level'] : null,
            'attackerId'    => is_numeric($att['id'] ?? null) ? (int) $att['id'] : ($b->player1_id ?? 0),
            'defenderId'    => is_numeric($def['id'] ?? null) ? (int) $def['id'] : ($b->player2_id ?? 0),
            'winnerId'      => $winnerId,
            'type'          => is_string($oc['type'] ?? null) ? $oc['type'] : null,
        ];
    }

    /** Дата боя как строка 'Y-m-d H:i:s' (created_at кастится в Time). */
    private function dateStr(mixed $createdAt): string
    {
        if ($createdAt instanceof Time) {
            return (string) $createdAt->toDateTimeString();
        }

        return is_string($createdAt) ? $createdAt : '';
    }
}
