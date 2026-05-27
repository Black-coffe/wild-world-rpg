<?php
/**
 * @var array<int,array<string,mixed>> $biomes
 * @var array<string,mixed>            $meta
 * @var array{logged_in: bool, first_name: string} $auth
 * @var string                         $botUsername
 */
$this->extend('site/layout');
$csrfHash = csrf_hash();
$csrfName = csrf_token();

// Цвета биомов — синхронизированы с App\Services\World\MiniMapService::$biomeColors
$biomeColors = [
    1 => '#008874', // Леса
    2 => '#003239', // Горы
    3 => '#ffffff', // Тундра
    4 => '#39db97', // Реки
    5 => '#d9d229', // Тропики
    6 => '#dac99d', // Поля
    7 => '#4e4211', // Пещеры
    8 => '#cc0000', // Вулканы
    9 => '#82642b', // Пустыни
];

$pixelMap     = base_url('uploads/telegram/character/world_map_1000x1000.png');
$beautifulMap = base_url('uploads/telegram/character/beautiful_map.png');
$dataEndpoint = base_url('map/data');

$biomeMap = [];
foreach ($biomes as $b) {
    $bid  = (int) ($b['id'] ?? 0);
    $name = is_string($b['name'] ?? null) ? $b['name'] : ('Биом #' . $bid);
    if ($bid > 0) {
        $biomeMap[$bid] = $name;
    }
}
?>

<?= $this->section('head') ?>
<style>
.ww-map-wrap{padding:2.2rem 0 3rem}
.ww-map-head{margin-bottom:1.3rem}
.ww-map-head h1{font-family:"Oswald",sans-serif;font-size:1.9rem;text-transform:uppercase;letter-spacing:.06em;margin:0 0 .4rem}
.ww-map-head p{color:var(--ww-muted);margin:0;font-size:.95rem;max-width:780px}
.ww-map-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:1.5rem;align-items:start}
@media (max-width: 980px){.ww-map-layout{grid-template-columns:1fr}}
.ww-map-stage{background:var(--ww-panel);border:1px solid var(--ww-line);border-radius:8px;padding:.6rem;position:relative;overflow:hidden}
.ww-map-canvas-box{position:relative;width:100%;aspect-ratio:1/1;background:#0e0c0a;border-radius:4px;overflow:hidden}
.ww-map-canvas-box canvas{display:block;width:100%;height:100%;cursor:grab;image-rendering:pixelated;image-rendering:crisp-edges;user-select:none;-webkit-user-select:none}
.ww-map-canvas-box canvas.is-dragging{cursor:grabbing}
.ww-map-canvas-box.zoom-1 canvas{cursor:crosshair}
.ww-map-canvas-box .ww-map-loader{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--ww-muted);font-size:.95rem;pointer-events:none;background:rgba(14,12,10,.85);text-align:center;padding:1rem}
.ww-map-canvas-box .ww-map-loader.is-hidden{display:none}
.ww-map-tooltip{position:absolute;pointer-events:none;background:rgba(13,17,24,.95);border:1px solid var(--ww-line);color:var(--ww-text);padding:.4rem .6rem;border-radius:4px;font-size:.82rem;font-family:"PT Sans",sans-serif;line-height:1.35;white-space:nowrap;transform:translate(-50%,-130%);display:none;z-index:5;box-shadow:0 4px 14px rgba(0,0,0,.5)}
.ww-map-tooltip.is-visible{display:block}
.ww-map-tooltip b{color:var(--ww-accent)}
.ww-map-panel{background:var(--ww-panel);border:1px solid var(--ww-line);border-radius:8px;padding:1.1rem 1.2rem}
.ww-map-panel + .ww-map-panel{margin-top:1rem}
.ww-map-panel h3{font-family:"Oswald",sans-serif;font-size:.95rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ww-muted);margin:0 0 .8rem}
.ww-map-opt{display:block;padding:.45rem .1rem;cursor:pointer;font-size:.92rem;color:var(--ww-text)}
.ww-map-opt input{margin-right:.55rem;vertical-align:middle}
.ww-map-opt small{display:block;color:var(--ww-muted);font-size:.78rem;margin-left:1.4rem;margin-top:.1rem}
.ww-map-opt.is-locked{cursor:not-allowed;opacity:.5}
.ww-map-opt.is-locked input{cursor:not-allowed}
.ww-map-scale-row{display:flex;gap:.35rem;flex-wrap:wrap}
.ww-map-scale-row label{flex:1 1 calc(25% - .35rem);min-width:60px;text-align:center;padding:.4rem .25rem;border:1px solid var(--ww-line);border-radius:4px;cursor:pointer;font-family:"Oswald",sans-serif;font-size:.85rem;letter-spacing:.04em;background:rgba(0,0,0,.18);user-select:none}
.ww-map-scale-row label.is-active{border-color:var(--ww-accent);background:rgba(245,165,36,.12);color:var(--ww-accent)}
.ww-map-scale-row input{position:absolute;opacity:0;pointer-events:none}
.ww-map-refresh{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;background:transparent;border:1px solid var(--ww-line);color:var(--ww-text);border-radius:4px;cursor:pointer;font-family:"Oswald",sans-serif;text-transform:uppercase;font-size:.85rem;letter-spacing:.05em}
.ww-map-refresh:hover{border-color:var(--ww-accent);color:var(--ww-accent)}
.ww-map-status{margin-top:.6rem;font-size:.8rem;color:var(--ww-muted)}
.ww-map-legend ul{list-style:none;padding:0;margin:0}
.ww-map-legend li{display:flex;align-items:center;gap:.55rem;padding:.3rem 0;font-size:.88rem}
.ww-map-legend .ww-map-swatch{display:inline-block;width:18px;height:18px;border:1px solid rgba(255,255,255,.15);border-radius:2px;flex-shrink:0}
.ww-map-coords{font-family:"Oswald",sans-serif;font-size:.95rem;letter-spacing:.04em;color:var(--ww-accent);margin-top:.4rem;min-height:1.2em}
.ww-map-events ul{list-style:none;padding:0;margin:0;max-height:280px;overflow-y:auto}
.ww-map-events li{padding:.55rem .6rem;border:1px solid var(--ww-line);border-radius:4px;margin-bottom:.45rem;font-size:.85rem;line-height:1.4;background:rgba(0,0,0,.18)}
.ww-map-events li.is-empty{color:var(--ww-muted);text-align:center;border-style:dashed;background:transparent}
.ww-map-events .ww-ev-name{display:block;font-weight:600;color:var(--ww-text);margin-bottom:.15rem}
.ww-map-events .ww-ev-meta{display:block;color:var(--ww-muted);font-size:.77rem}
.ww-map-events .ww-ev-tag{display:inline-block;padding:.05rem .4rem;border-radius:3px;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;margin-right:.3rem}
.ww-map-events .ww-ev-tag-local{background:rgba(217,210,41,.15);color:#d9d229;border:1px solid rgba(217,210,41,.4)}
.ww-map-events .ww-ev-tag-global{background:rgba(204,0,0,.18);color:#e85555;border:1px solid rgba(204,0,0,.5)}
.ww-map-future{font-size:.82rem;color:var(--ww-muted);line-height:1.5;margin:.6rem 0 0}
.ww-map-auth{display:flex;flex-direction:column;gap:.5rem}
.ww-map-auth .ww-auth-hello{font-size:.92rem}
.ww-map-auth .ww-auth-hello b{color:var(--ww-accent)}
.ww-map-auth .ww-auth-hint{font-size:.78rem;color:var(--ww-muted);line-height:1.4;margin:.3rem 0 0}
.ww-map-auth .ww-auth-logout{display:inline-block;padding:.4rem .8rem;background:transparent;border:1px solid var(--ww-line);color:var(--ww-muted);border-radius:4px;font-family:"Oswald",sans-serif;text-transform:uppercase;font-size:.78rem;letter-spacing:.04em;cursor:pointer}
.ww-map-auth .ww-auth-logout:hover{border-color:#e85555;color:#e85555}
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="ww-map-wrap">
    <div class="container">
        <header class="ww-map-head">
            <h1>Карта мира</h1>
            <p>Остров Wild World 1000×1000 клеток. Переключай подложку, увеличивай масштаб и двигай мышкой/тачем. Контуры биомов поверх «художественной» подложки — для географической ясности.</p>
        </header>

        <div class="ww-map-layout">
            <div class="ww-map-stage">
                <div class="ww-map-canvas-box zoom-1" id="ww-map-box">
                    <canvas id="ww-map-canvas" width="2000" height="2000" aria-label="Карта мира Wild World"></canvas>
                    <div class="ww-map-loader" id="ww-map-loader">Загружаем карту…</div>
                    <div class="ww-map-tooltip" id="ww-map-tooltip">(0, 0)</div>
                </div>
                <div class="ww-map-coords" id="ww-map-coords">Наведи курсор, чтобы увидеть координаты клетки</div>
            </div>

            <aside>
                <div class="ww-map-panel ww-map-auth">
                    <h3>Доступ</h3>
                    <?php if ($auth['logged_in']): ?>
                        <span class="ww-auth-hello">Привет, <b><?= esc($auth['first_name'] !== '' ? $auth['first_name'] : 'игрок') ?></b>!</span>
                        <form method="post" action="<?= esc(base_url('logout/telegram'), 'attr') ?>">
                            <input type="hidden" name="<?= esc($csrfName, 'attr') ?>" value="<?= esc($csrfHash, 'attr') ?>">
                            <button type="submit" class="ww-auth-logout">Выйти</button>
                        </form>
                        <p class="ww-auth-hint">Видишь свою позицию на карте (зелёный маркер ●). Других игроков карта не показывает.</p>
                    <?php elseif ($botUsername !== ''): ?>
                        <span class="ww-auth-hello">Войди через Telegram, чтобы видеть свою позицию на карте.</span>
                        <?php /* SRI integrity= намеренно опущен: Telegram обновляет widget script
                                 (security fixes), хеш слетел бы. Trust-anchor = telegram.org TLS.
                                 crossorigin+referrerpolicy — anti-credential leak (ADR-061).
                                 data-onauth (а не data-auth-url) — JS-callback надёжнее срабатывает
                                 в iframe-mode виджета v22 (data-auth-url оставался в iframe). */ ?>
                        <script>
                        (function(){
                            window.onTelegramAuth = function(user){
                                if (!user || typeof user !== 'object') return;
                                var qs = Object.keys(user)
                                    .filter(function(k){ return user[k] !== null && user[k] !== undefined; })
                                    .map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(user[k]); })
                                    .join('&');
                                window.location.href = <?= json_encode(base_url('login/telegram/callback'), JSON_UNESCAPED_SLASHES) ?> + '?' + qs;
                            };
                        })();
                        </script>
                        <script async src="https://telegram.org/js/telegram-widget.js?22"
                                data-telegram-login="<?= esc($botUsername, 'attr') ?>"
                                data-size="medium"
                                data-onauth="onTelegramAuth(user)"
                                data-request-access="write"
                                crossorigin="anonymous"
                                referrerpolicy="no-referrer"></script>
                        <noscript><span class="ww-auth-hint">Для входа нужен JavaScript.</span></noscript>
                    <?php else: ?>
                        <span class="ww-auth-hint">Авторизация временно недоступна.</span>
                    <?php endif; ?>
                </div>

                <div class="ww-map-panel">
                    <h3>Подложка</h3>
                    <label class="ww-map-opt">
                        <input type="radio" name="ww-basemap" value="pixel" checked>
                        Попиксельная биом-карта
                        <small>1 клетка = 2px, чистые биом-цвета.</small>
                    </label>
                    <label class="ww-map-opt">
                        <input type="radio" name="ww-basemap" value="beautiful">
                        Художественная
                        <small>Стилизованный географический рендер.</small>
                    </label>
                </div>

                <div class="ww-map-panel">
                    <h3>Масштаб</h3>
                    <div class="ww-map-scale-row" id="ww-scale-row">
                        <label class="is-active" data-scale="1"><input type="radio" name="ww-scale" value="1" checked>1×</label>
                        <label data-scale="2"><input type="radio" name="ww-scale" value="2">2×</label>
                        <label data-scale="5"><input type="radio" name="ww-scale" value="5">5×</label>
                        <label data-scale="10"><input type="radio" name="ww-scale" value="10">10×</label>
                    </div>
                    <small class="ww-map-future">При 2× и выше — тащи мышкой / пальцем, чтобы двигать карту.</small>
                </div>

                <div class="ww-map-panel">
                    <h3>Слои</h3>
                    <label class="ww-map-opt">
                        <input type="checkbox" id="ww-toggle-grid" checked>
                        Координатная сетка
                        <small>Тонкая через 50, жирная через 100 клеток.</small>
                    </label>
                    <label class="ww-map-opt">
                        <input type="checkbox" id="ww-toggle-caravans">
                        Караваны 🚚
                        <small>Активные NPC-торговцы (V25): точки на карте, hover — что продают и почём.</small>
                    </label>
                    <label class="ww-map-opt is-locked" id="ww-tint-label">
                        <input type="checkbox" id="ww-toggle-biome-tint">
                        Контуры биомов
                        <small>Тонкие границы между биомами. Доступно только с «художественной» подложкой.</small>
                    </label>
                    <?php if ($auth['logged_in']): ?>
                    <label class="ww-map-opt" id="ww-me-label">
                        <input type="checkbox" id="ww-toggle-me" checked>
                        Моя позиция ●
                        <small>Зелёный маркер на координатах твоего персонажа.</small>
                    </label>
                    <?php endif; ?>
                </div>

                <div class="ww-map-panel">
                    <h3>Обновление</h3>
                    <button type="button" class="ww-map-refresh" id="ww-map-refresh">🔄 Обновить</button>
                    <div class="ww-map-status" id="ww-map-status">Загружаем snapshot…</div>
                </div>

                <div class="ww-map-panel ww-map-events">
                    <h3>Активные события</h3>
                    <ul id="ww-events-list">
                        <li class="is-empty">Загрузка…</li>
                    </ul>
                </div>

                <div class="ww-map-panel ww-map-legend">
                    <h3>Легенда биомов</h3>
                    <ul>
                        <?php foreach ($biomes as $b): ?>
                            <?php
                            $bid  = (int) ($b['id'] ?? 0);
                            $name = is_string($b['name'] ?? null) ? $b['name'] : ('Биом #' . $bid);
                            $col  = $biomeColors[$bid] ?? '#808080';
                            ?>
                            <li>
                                <span class="ww-map-swatch" style="background:<?= esc($col, 'attr') ?>"></span>
                                <span><?= esc($name) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function(){
    'use strict';

    var SOURCES = {
        pixel:     <?= json_encode($pixelMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        beautiful: <?= json_encode($beautifulMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    };
    var DATA_URL    = <?= json_encode($dataEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var BIOMES      = <?= json_encode($biomeMap, JSON_UNESCAPED_UNICODE) ?>;
    var CANVAS_SIZE = 2000;        // внутреннее разрешение canvas
    var WORLD_SIZE  = 1000;        // 1000×1000 логических клеток
    var PNG_NATIVE  = 2000;        // нативный размер исходных PNG = WORLD_SIZE * 2

    var meChk   = document.getElementById('ww-toggle-me');  // может быть null если не auth'ован
    var canvas  = document.getElementById('ww-map-canvas');
    var ctx     = canvas.getContext('2d');
    var loader  = document.getElementById('ww-map-loader');
    var box     = document.getElementById('ww-map-box');
    var tip     = document.getElementById('ww-map-tooltip');
    var coords  = document.getElementById('ww-map-coords');
    var status  = document.getElementById('ww-map-status');
    var refresh = document.getElementById('ww-map-refresh');
    var gridChk = document.getElementById('ww-toggle-grid');
    var carChk  = document.getElementById('ww-toggle-caravans');
    var tintChk = document.getElementById('ww-toggle-biome-tint');
    var tintLab = document.getElementById('ww-tint-label');
    var eventsList = document.getElementById('ww-events-list');
    var scaleRow = document.getElementById('ww-scale-row');

    var state = {
        basemap:       'pixel',
        showGrid:      true,
        showCar:       false,
        showTint:      false,
        showMe:        meChk ? meChk.checked : false,
        scale:         1,
        viewX:         0,    // worldX левого края viewport (0..1000-viewSize)
        viewY:         0,
        baseImg:       null, // текущая подложка
        pixelImg:      null, // pixel-биом-карта (для contour precompute)
        contourCanvas: null, // pre-computed offscreen canvas с контурами биомов
        caravans:      [],
        events:        [],
        me:            null, // ADR-061: {x, y, name, level} если auth'ован, иначе null
        drag:          null  // {startX, startY, startViewX, startViewY}
    };

    // --- утилиты ---

    function viewSizeCells(){ return WORLD_SIZE / state.scale; }
    function clampPan(){
        var max = WORLD_SIZE - viewSizeCells();
        if (state.viewX < 0) state.viewX = 0;
        if (state.viewY < 0) state.viewY = 0;
        if (state.viewX > max) state.viewX = max;
        if (state.viewY > max) state.viewY = max;
    }
    function centerOn(cx, cy){
        var half = viewSizeCells() / 2;
        state.viewX = cx - half;
        state.viewY = cy - half;
        clampPan();
    }
    function setLoader(visible, text){
        if (text) loader.textContent = text;
        loader.classList.toggle('is-hidden', !visible);
    }
    function loadImage(url){
        return new Promise(function(resolve, reject){
            var img = new Image();
            img.onload  = function(){ resolve(img); };
            img.onerror = function(){ reject(new Error('Не удалось загрузить ' + url)); };
            img.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
        });
    }

    // --- рендер ---

    function drawBasemap(){
        if (!state.baseImg) return;
        var vsCells = viewSizeCells();
        // источник в PNG-px: 1 cell = 2 PNG-px
        var sx = state.viewX * 2;
        var sy = state.viewY * 2;
        var sw = vsCells * 2;
        var sh = vsCells * 2;
        ctx.drawImage(state.baseImg, sx, sy, sw, sh, 0, 0, CANVAS_SIZE, CANVAS_SIZE);
    }

    function drawContours(){
        if (!state.contourCanvas) return;
        var vsCells = viewSizeCells();
        var sx = state.viewX * 2;
        var sy = state.viewY * 2;
        var sw = vsCells * 2;
        var sh = vsCells * 2;
        ctx.save();
        ctx.globalAlpha = 0.85;
        ctx.drawImage(state.contourCanvas, sx, sy, sw, sh, 0, 0, CANVAS_SIZE, CANVAS_SIZE);
        ctx.restore();
    }

    function drawGrid(){
        var vsCells = viewSizeCells();
        var pxPerCell = CANVAS_SIZE / vsCells; // 2 (scale=1), 4 (2×), 10 (5×), 20 (10×)
        var startX = Math.ceil(state.viewX / 50) * 50;
        var endX   = state.viewX + vsCells;
        var startY = Math.ceil(state.viewY / 50) * 50;
        var endY   = state.viewY + vsCells;

        ctx.save();
        // тонкие линии каждые 50 cells
        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (var x = startX; x <= endX; x += 50){
            if (x % 100 === 0) continue;
            var px = (x - state.viewX) * pxPerCell;
            ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, CANVAS_SIZE); ctx.stroke();
        }
        for (var y = startY; y <= endY; y += 50){
            if (y % 100 === 0) continue;
            var py = (y - state.viewY) * pxPerCell;
            ctx.beginPath(); ctx.moveTo(0, py); ctx.lineTo(CANVAS_SIZE, py); ctx.stroke();
        }
        // жирные линии каждые 100 cells
        ctx.strokeStyle = 'rgba(255,255,255,0.24)';
        ctx.lineWidth = 2;
        var startX100 = Math.ceil(state.viewX / 100) * 100;
        var startY100 = Math.ceil(state.viewY / 100) * 100;
        for (var xx = startX100; xx <= endX; xx += 100){
            var px2 = (xx - state.viewX) * pxPerCell;
            ctx.beginPath(); ctx.moveTo(px2, 0); ctx.lineTo(px2, CANVAS_SIZE); ctx.stroke();
        }
        for (var yy = startY100; yy <= endY; yy += 100){
            var py2 = (yy - state.viewY) * pxPerCell;
            ctx.beginPath(); ctx.moveTo(0, py2); ctx.lineTo(CANVAS_SIZE, py2); ctx.stroke();
        }
        // подписи
        ctx.fillStyle = 'rgba(255,255,255,0.6)';
        ctx.font = 'bold 22px "Oswald", sans-serif';
        ctx.textBaseline = 'top';
        for (var xl = startX100; xl <= endX; xl += 100){
            var pxL = (xl - state.viewX) * pxPerCell;
            ctx.fillText(String(xl), pxL + 4, 4);
        }
        for (var yl = startY100; yl <= endY; yl += 100){
            var pyL = (yl - state.viewY) * pxPerCell;
            ctx.fillText(String(yl), 4, pyL + 4);
        }
        ctx.restore();
    }

    function drawCaravans(){
        if (!state.caravans.length) return;
        var vsCells = viewSizeCells();
        var pxPerCell = CANVAS_SIZE / vsCells;
        ctx.save();
        for (var i = 0; i < state.caravans.length; i++){
            var c = state.caravans[i];
            if (!c.x || !c.y) continue;
            if (c.x < state.viewX || c.x > state.viewX + vsCells) continue;
            if (c.y < state.viewY || c.y > state.viewY + vsCells) continue;
            var px = (c.x - state.viewX) * pxPerCell;
            var py = (c.y - state.viewY) * pxPerCell;
            // маркер всегда фиксированный размер вне зависимости от scale
            ctx.beginPath();
            ctx.arc(px, py, 20, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(0,0,0,0.55)';
            ctx.fill();
            ctx.beginPath();
            ctx.arc(px, py, 16, 0, Math.PI * 2);
            ctx.fillStyle = '#f5a524';
            ctx.fill();
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#1a1408';
            ctx.stroke();
            ctx.fillStyle = '#1a1408';
            ctx.font = 'bold 24px "Oswald", sans-serif';
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'center';
            ctx.fillText('₸', px, py + 1);
        }
        ctx.restore();
    }

    function drawMe(){
        if (!state.me) return;
        var vsCells = viewSizeCells();
        var pxPerCell = CANVAS_SIZE / vsCells;
        if (state.me.x < state.viewX || state.me.x > state.viewX + vsCells) return;
        if (state.me.y < state.viewY || state.me.y > state.viewY + vsCells) return;
        var px = (state.me.x - state.viewX) * pxPerCell;
        var py = (state.me.y - state.viewY) * pxPerCell;
        ctx.save();
        // тень
        ctx.beginPath();
        ctx.arc(px, py, 22, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,0,0,0.55)';
        ctx.fill();
        // основной круг — зелёный
        ctx.beginPath();
        ctx.arc(px, py, 17, 0, Math.PI * 2);
        ctx.fillStyle = '#39db97';
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#0a1f15';
        ctx.stroke();
        // точка в центре
        ctx.fillStyle = '#0a1f15';
        ctx.beginPath();
        ctx.arc(px, py, 5, 0, Math.PI * 2);
        ctx.fill();
        // пульсирующее кольцо (статичный декор без анимации — кольцо вокруг)
        ctx.lineWidth = 2;
        ctx.strokeStyle = 'rgba(57,219,151,0.55)';
        ctx.beginPath();
        ctx.arc(px, py, 28, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
    }

    function render(){
        if (!state.baseImg) return;
        clampPan();
        ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        drawBasemap();
        if (state.showTint && state.basemap === 'beautiful') drawContours();
        if (state.showGrid)  drawGrid();
        if (state.showCar)   drawCaravans();
        if (state.showMe)    drawMe();
    }

    function renderEvents(){
        eventsList.innerHTML = '';
        if (!state.events.length){
            var empty = document.createElement('li');
            empty.className = 'is-empty';
            empty.textContent = 'Активных мировых событий нет.';
            eventsList.appendChild(empty);
            return;
        }
        state.events.forEach(function(e){
            var li = document.createElement('li');
            var name = document.createElement('span');
            name.className = 'ww-ev-name';
            var tag  = document.createElement('span');
            tag.className = 'ww-ev-tag ' + (e.type === 'global' ? 'ww-ev-tag-global' : 'ww-ev-tag-local');
            tag.textContent = e.type === 'global' ? 'Глобальное' : 'Локальное';
            name.appendChild(tag);
            name.appendChild(document.createTextNode(e.name || '?'));
            li.appendChild(name);
            var meta = document.createElement('span');
            meta.className = 'ww-ev-meta';
            var parts = [];
            if (e.biome_ids && e.biome_ids.length){
                var biomeNames = e.biome_ids.map(function(id){ return BIOMES[id] || ('Биом #' + id); }).join(', ');
                parts.push('Биомы: ' + biomeNames);
            }
            if (e.effect && e.effect !== 'none') parts.push('Эффект: ' + e.effect);
            if (e.ends_at) parts.push('До: ' + e.ends_at.replace('T', ' '));
            meta.textContent = parts.join(' · ') || 'без подробностей';
            li.appendChild(meta);
            eventsList.appendChild(li);
        });
    }

    // --- contour edge detection (pre-compute один раз после загрузки pixel-карты) ---
    function precomputeContours(pixelImage){
        try {
            var n = PNG_NATIVE;
            var off = document.createElement('canvas');
            off.width = n; off.height = n;
            var octx = off.getContext('2d');
            octx.drawImage(pixelImage, 0, 0, n, n);
            var src = octx.getImageData(0, 0, n, n).data;

            // сэмплируем биом-цвет в центре каждой cell (2x2 px). cells WORLD_SIZE×WORLD_SIZE.
            var biomeKey = new Uint32Array(WORLD_SIZE * WORLD_SIZE);
            for (var cy = 0; cy < WORLD_SIZE; cy++){
                for (var cx = 0; cx < WORLD_SIZE; cx++){
                    var px = cx * 2;
                    var py = cy * 2;
                    var idx = (py * n + px) * 4;
                    // packed (r<<16)|(g<<8)|b
                    biomeKey[cy * WORLD_SIZE + cx] = (src[idx] << 16) | (src[idx + 1] << 8) | src[idx + 2];
                }
            }

            var out = document.createElement('canvas');
            out.width = n; out.height = n;
            var outCtx = out.getContext('2d');
            var outImage = outCtx.createImageData(n, n);
            var dst = outImage.data;

            // контур: проверяем правый и нижний соседи cell'а.
            // если разные биомы — заполняем граничные пиксели тёмным.
            function setPixel(px, py){
                if (px < 0 || px >= n || py < 0 || py >= n) return;
                var di = (py * n + px) * 4;
                dst[di]     = 28;
                dst[di + 1] = 22;
                dst[di + 2] = 16;
                dst[di + 3] = 230;
            }

            for (var iy = 0; iy < WORLD_SIZE; iy++){
                for (var ix = 0; ix < WORLD_SIZE; ix++){
                    var base = biomeKey[iy * WORLD_SIZE + ix];
                    var pxN = ix * 2;
                    var pyN = iy * 2;
                    // правый сосед
                    if (ix < WORLD_SIZE - 1){
                        if (biomeKey[iy * WORLD_SIZE + (ix + 1)] !== base){
                            setPixel(pxN + 1, pyN);
                            setPixel(pxN + 1, pyN + 1);
                        }
                    }
                    // нижний сосед
                    if (iy < WORLD_SIZE - 1){
                        if (biomeKey[(iy + 1) * WORLD_SIZE + ix] !== base){
                            setPixel(pxN,     pyN + 1);
                            setPixel(pxN + 1, pyN + 1);
                        }
                    }
                }
            }
            outCtx.putImageData(outImage, 0, 0);
            return out;
        } catch (e){
            // CORS / OOM / etc — контуры просто не появятся
            return null;
        }
    }

    // --- fetch data ---
    function fetchData(){
        return fetch(DATA_URL, {credentials: 'same-origin'})
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(json){
                state.caravans = Array.isArray(json.caravans) ? json.caravans : [];
                state.events   = Array.isArray(json.events)   ? json.events   : [];
                state.me       = (json.me && typeof json.me === 'object') ? json.me : null;
                renderEvents();
            })
            .catch(function(err){
                status.textContent = 'Ошибка слоёв: ' + (err.message || err);
            });
    }

    function reload(kind){
        setLoader(true, kind === 'pixel' ? 'Загружаем карту + считаем контуры…' : 'Загружаем карту…');
        var basePromise = loadImage(SOURCES[kind]);
        var tintNeeded  = !state.pixelImg;
        var pixelPromise = (kind === 'pixel')
            ? basePromise
            : (tintNeeded ? loadImage(SOURCES.pixel).catch(function(){ return null; })
                          : Promise.resolve(state.pixelImg));

        return Promise.all([basePromise, pixelPromise, fetchData()])
            .then(function(results){
                state.baseImg = results[0];
                if (results[1] && !state.pixelImg){
                    state.pixelImg = results[1];
                    // pre-compute контуры (один раз)
                    setLoader(true, 'Считаем контуры биомов…');
                    // даём loader отрисоваться
                    return new Promise(function(res){ setTimeout(function(){
                        state.contourCanvas = precomputeContours(state.pixelImg);
                        res();
                    }, 30); });
                }
                return null;
            })
            .then(function(){
                render();
                updateTintLockState();
                setLoader(false);
                status.textContent = 'Snapshot: ' + new Date().toLocaleTimeString()
                    + ' · караванов: ' + state.caravans.length
                    + ' · событий: ' + state.events.length;
            })
            .catch(function(err){
                setLoader(true, 'Ошибка: ' + (err.message || err));
                status.textContent = String(err.message || err);
            });
    }

    function updateTintLockState(){
        var locked = state.basemap === 'pixel';
        tintLab.classList.toggle('is-locked', locked);
        tintChk.disabled = locked;
        if (locked && tintChk.checked){
            tintChk.checked = false;
            state.showTint = false;
        }
    }

    function applyScale(newScale){
        var oldVsCells = viewSizeCells();
        var centerX = state.viewX + oldVsCells / 2;
        var centerY = state.viewY + oldVsCells / 2;
        state.scale = newScale;
        centerOn(centerX, centerY);
        // CSS class для cursor (zoom-1 = crosshair, 2/5/10 = grab)
        box.classList.remove('zoom-1', 'zoom-2', 'zoom-5', 'zoom-10');
        box.classList.add('zoom-' + newScale);
        // активный label
        document.querySelectorAll('#ww-scale-row label').forEach(function(l){
            l.classList.toggle('is-active', parseInt(l.getAttribute('data-scale'), 10) === newScale);
        });
        render();
    }

    // --- UI ---
    document.querySelectorAll('input[name="ww-basemap"]').forEach(function(r){
        r.addEventListener('change', function(){
            state.basemap = this.value;
            updateTintLockState();
            reload(state.basemap);
        });
    });
    document.querySelectorAll('input[name="ww-scale"]').forEach(function(r){
        r.addEventListener('change', function(){
            var s = parseInt(this.value, 10);
            if (s === 1 || s === 2 || s === 5 || s === 10) applyScale(s);
        });
    });
    gridChk.addEventListener('change', function(){ state.showGrid = this.checked; render(); });
    carChk.addEventListener('change',  function(){ state.showCar  = this.checked; render(); });
    tintChk.addEventListener('change', function(){
        if (this.disabled) return;
        state.showTint = this.checked;
        render();
    });
    if (meChk){
        meChk.addEventListener('change', function(){
            state.showMe = this.checked;
            render();
        });
    }
    refresh.addEventListener('click',  function(){ reload(state.basemap); });

    // --- pointer math + tooltip + drag-pan ---
    function eventToCanvas(evt){
        var rect = canvas.getBoundingClientRect();
        var cx = (evt.clientX - rect.left) * (CANVAS_SIZE / rect.width);
        var cy = (evt.clientY - rect.top)  * (CANVAS_SIZE / rect.height);
        var pxPerCell = CANVAS_SIZE / viewSizeCells();
        // floor сначала суммы (cell в worldCoords) → потом +1 даёт 1..1000
        var wx = Math.min(WORLD_SIZE, Math.max(1, Math.floor(cx / pxPerCell + state.viewX) + 1));
        var wy = Math.min(WORLD_SIZE, Math.max(1, Math.floor(cy / pxPerCell + state.viewY) + 1));
        return {cx: cx, cy: cy, wx: wx, wy: wy, clientX: evt.clientX, clientY: evt.clientY};
    }
    function nearestCaravan(cx, cy){
        if (!state.showCar || !state.caravans.length) return null;
        var pxPerCell = CANVAS_SIZE / viewSizeCells();
        var HIT2 = 36 * 36;
        var best = null, bestD = HIT2;
        for (var i = 0; i < state.caravans.length; i++){
            var c = state.caravans[i];
            if (!c.x || !c.y) continue;
            var px = (c.x - state.viewX) * pxPerCell;
            var py = (c.y - state.viewY) * pxPerCell;
            var d = (cx - px) * (cx - px) + (cy - py) * (cy - py);
            if (d < bestD){ bestD = d; best = c; }
        }
        return best;
    }
    function buildCaravanTip(car){
        while (tip.firstChild) tip.removeChild(tip.firstChild);
        var header = document.createElement('b');
        header.textContent = '🚚 Караван';
        tip.appendChild(header);
        var lines = [
            (car.resource || '?') + ' ×' + (car.qty || 0),
            'Цена: ' + (car.price || 0) + ' 🪙/шт',
            'X=' + car.x + ', Y=' + car.y
        ];
        lines.forEach(function(text){
            tip.appendChild(document.createElement('br'));
            tip.appendChild(document.createTextNode(text));
        });
    }

    canvas.addEventListener('mousedown', function(evt){
        if (state.scale === 1) return; // на 1× pan не нужен
        state.drag = {
            startClientX: evt.clientX,
            startClientY: evt.clientY,
            startViewX:   state.viewX,
            startViewY:   state.viewY
        };
        canvas.classList.add('is-dragging');
        evt.preventDefault();
    });
    window.addEventListener('mousemove', function(evt){
        if (state.drag){
            var rect = canvas.getBoundingClientRect();
            var pxPerCellScreen = rect.width / viewSizeCells();
            var dxCells = (evt.clientX - state.drag.startClientX) / pxPerCellScreen;
            var dyCells = (evt.clientY - state.drag.startClientY) / pxPerCellScreen;
            state.viewX = state.drag.startViewX - dxCells;
            state.viewY = state.drag.startViewY - dyCells;
            render();
            return;
        }
        // обычный hover — только если курсор над canvas
        if (evt.target !== canvas) return;
        var p = eventToCanvas(evt);
        var car = nearestCaravan(p.cx, p.cy);
        coords.textContent = 'Клетка (X=' + p.wx + ', Y=' + p.wy + ')';
        if (car){ buildCaravanTip(car); }
        else { tip.textContent = '(' + p.wx + ', ' + p.wy + ')'; }
        tip.classList.add('is-visible');
        var boxRect = box.getBoundingClientRect();
        tip.style.left = (p.clientX - boxRect.left) + 'px';
        tip.style.top  = (p.clientY - boxRect.top) + 'px';
    });
    window.addEventListener('mouseup', function(){
        if (state.drag){
            state.drag = null;
            canvas.classList.remove('is-dragging');
        }
    });
    canvas.addEventListener('mouseleave', function(){
        if (!state.drag){
            tip.classList.remove('is-visible');
            coords.textContent = 'Наведи курсор, чтобы увидеть координаты клетки';
        }
    });

    // touch — drag-pan + tap координаты
    canvas.addEventListener('touchstart', function(evt){
        if (state.scale === 1 || !evt.touches || !evt.touches[0]) return;
        var t = evt.touches[0];
        state.drag = {
            startClientX: t.clientX,
            startClientY: t.clientY,
            startViewX:   state.viewX,
            startViewY:   state.viewY
        };
    }, {passive: true});
    canvas.addEventListener('touchmove', function(evt){
        if (!state.drag || !evt.touches || !evt.touches[0]) return;
        var t = evt.touches[0];
        var rect = canvas.getBoundingClientRect();
        var pxPerCellScreen = rect.width / viewSizeCells();
        state.viewX = state.drag.startViewX - (t.clientX - state.drag.startClientX) / pxPerCellScreen;
        state.viewY = state.drag.startViewY - (t.clientY - state.drag.startClientY) / pxPerCellScreen;
        render();
        evt.preventDefault();
    }, {passive: false});
    canvas.addEventListener('touchend', function(){ state.drag = null; }, {passive: true});

    // первый запуск
    updateTintLockState();
    reload(state.basemap);
})();
</script>
<?= $this->endSection() ?>
