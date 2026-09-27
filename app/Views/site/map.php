<?php
/**
 * @var array<int,array<string,mixed>> $biomes
 * @var array<string,mixed>            $meta
 * @var array{logged_in: bool, first_name: string} $auth
 * @var string                         $botUsername
 * @var array<int,string>              $biomeColors цвета легенды из BiomePalette (контроллер)
 * @var string                         $pixelMap     URL PNG с версией по filemtime
 * @var string                         $beautifulMap URL PNG с версией по filemtime
 * @var string|null                    $playUrl      «Играть отсюда» — только вошедшему при включённой игре
 */
$this->extend('site/layout');
$csrfHash = csrf_hash();
$csrfName = csrf_token();

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

<?php /* Стили страницы — `.ww-map-*` в wildworld-ui.css (токены ADR-062). */ ?>

<?= $this->section('content') ?>
<section class="ww-map-wrap">
    <div class="container">
        <header class="ww-map-head">
            <h1>Карта мира</h1>
            <p>Остров Wild World 1000×1000 клеток. Переключай подложку, увеличивай масштаб и двигай мышкой/тачем. Контуры биомов поверх «художественной» подложки — для географической ясности.</p>
            <?php if (is_string($playUrl ?? null)): ?>
                <p class="ww-map-play"><a class="btn primary" href="<?= esc($playUrl, 'attr') ?>">▶ Играть отсюда</a><span>Откроет твою карту в игре на сайте — шаг и Поход прямо с клетки.</span></p>
            <?php endif; ?>
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
                        <div class="ww-auth-row">
                            <button type="button" class="ww-auth-locate" id="ww-locate-me" disabled title="Подсветить позицию персонажа на карте">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
                                Я на карте
                            </button>
                            <form method="post" action="<?= esc(base_url('logout/telegram'), 'attr') ?>">
                                <input type="hidden" name="<?= esc($csrfName, 'attr') ?>" value="<?= esc($csrfHash, 'attr') ?>">
                                <button type="submit" class="ww-auth-logout">Выйти</button>
                            </form>
                        </div>
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
                    <p class="ww-map-future">🏚 Поселения — постоянные обжитые места (аутпост и др.). Всегда отмечены на карте; наведи на значок, чтобы прочитать описание и лор.</p>
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
                            $col  = $biomeColors[$bid] ?? '';
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
    // Цвета маркеров — токены палитры wildworld-ui.css (ADR-062), не сырые значения.
    var TOKENS = (function(){
        var cs = getComputedStyle(document.documentElement);
        function t(name){ return cs.getPropertyValue(name).trim(); }
        return {
            accent: t('--accent'), ink: t('--accent-ink'), base: t('--bg-base'),
            text: t('--text'), me: t('--success'), meInk: t('--text-inverse')
        };
    })();
    function alpha(color, a){
        var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color || '');
        if (!m) return color;
        return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')';
    }
    var DATA_URL    = <?= json_encode($dataEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var BIOMES      = <?= json_encode($biomeMap, JSON_UNESCAPED_UNICODE) ?>;
    var CANVAS_SIZE = 2000;        // внутреннее разрешение canvas
    var WORLD_SIZE  = 1000;        // 1000×1000 логических клеток
    var PNG_NATIVE  = 2000;        // нативный размер исходных PNG = WORLD_SIZE * 2

    var meChk    = document.getElementById('ww-toggle-me');   // может быть null если не auth'ован
    var locateBtn = document.getElementById('ww-locate-me'); // null если не auth'ован
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
        settlements:   [], // ADR-101: статические поселения-лендмарки {x,y,name,type,zone,icon,lore}
        events:        [],
        me:            null, // ADR-061: {x, y, name, level} если auth'ован, иначе null
        drag:          null, // {startX, startY, startViewX, startViewY}
        ripple:        null, // {t0, duration} — анимация подсветки позиции (Я на карте)
        panAnim:       null  // {t0, duration, fromX, fromY, toX, toY, onDone}
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
            img.src = url; // версия — filemtime с сервера: тот же URL, пока PNG не перегенерирован
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
        ctx.strokeStyle = alpha(TOKENS.text, 0.10);
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
        ctx.strokeStyle = alpha(TOKENS.text, 0.24);
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
        ctx.fillStyle = alpha(TOKENS.text, 0.6);
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
            ctx.fillStyle = alpha(TOKENS.base, 0.55);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(px, py, 16, 0, Math.PI * 2);
            ctx.fillStyle = TOKENS.accent;
            ctx.fill();
            ctx.lineWidth = 3;
            ctx.strokeStyle = TOKENS.ink;
            ctx.stroke();
            ctx.fillStyle = TOKENS.ink;
            ctx.font = 'bold 24px "Oswald", sans-serif';
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'center';
            ctx.fillText('₸', px, py + 1);
        }
        ctx.restore();
    }

    // ADR-101 §Принцип 2 — статические поселения-лендмарки. Всегда видны (постоянные точки),
    // flat-маркер (квадрат + amber-рамка + emoji-иконка), hover → лор-tooltip.
    function drawSettlements(){
        if (!state.settlements.length) return;
        var vsCells = viewSizeCells();
        var pxPerCell = CANVAS_SIZE / vsCells;
        ctx.save();
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';
        for (var i = 0; i < state.settlements.length; i++){
            var s = state.settlements[i];
            if (!s.x || !s.y) continue;
            if (s.x < state.viewX || s.x > state.viewX + vsCells) continue;
            if (s.y < state.viewY || s.y > state.viewY + vsCells) continue;
            var px = (s.x - state.viewX) * pxPerCell;
            var py = (s.y - state.viewY) * pxPerCell;
            ctx.fillStyle = alpha(TOKENS.base, 0.88);
            ctx.fillRect(px - 19, py - 19, 38, 38);
            ctx.lineWidth = 3;
            ctx.strokeStyle = TOKENS.accent;
            ctx.strokeRect(px - 19, py - 19, 38, 38);
            ctx.font = '26px "Manrope", sans-serif';
            ctx.fillStyle = TOKENS.text;
            ctx.fillText(s.icon || '🏚', px, py + 2);
        }
        ctx.restore();
    }

    // 3 последовательные волны от точки персонажа — расходятся по всей карте,
    // каждая следующая слабее, затухание opacity. Web-3.0-style визуал, easeOutQuad
    // на radius чтобы волна быстрее расходилась в начале.
    function drawRipples(){
        if (!state.ripple || !state.me) return;
        var elapsed = performance.now() - state.ripple.t0;
        var vsCells = viewSizeCells();
        var pxPerCell = CANVAS_SIZE / vsCells;
        var cx = (state.me.x - state.viewX) * pxPerCell;
        var cy = (state.me.y - state.viewY) * pxPerCell;
        // Радиус волны рассчитан до угла canvas с любой точки (max distance) — всегда
        // покрывает всю видимую карту независимо от позиции игрока.
        var maxR = Math.sqrt(
            Math.max(cx, CANVAS_SIZE - cx) * Math.max(cx, CANVAS_SIZE - cx) +
            Math.max(cy, CANVAS_SIZE - cy) * Math.max(cy, CANVAS_SIZE - cy)
        );
        var WAVE_DURATION = 1500;          // ms — каждая волна
        var WAVE_STAGGER  = 500;           // ms — задержка между стартами волн
        var INTENSITY     = [0.85, 0.55, 0.32]; // 1-я ярче, 3-я слабее
        var WIDTH         = [7, 5, 3];     // line-width
        ctx.save();
        for (var i = 0; i < 3; i++){
            var waveStart = i * WAVE_STAGGER;
            var waveAge   = elapsed - waveStart;
            if (waveAge < 0 || waveAge > WAVE_DURATION) continue;
            var t = waveAge / WAVE_DURATION;           // 0..1
            var eased = 1 - (1 - t) * (1 - t);          // easeOutQuad для radius
            var radius = eased * maxR;
            var opacity = (1 - t) * INTENSITY[i];
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            ctx.lineWidth = WIDTH[i];
            ctx.strokeStyle = alpha(TOKENS.me, opacity);
            ctx.stroke();
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
        ctx.fillStyle = alpha(TOKENS.base, 0.55);
        ctx.fill();
        // основной круг — зелёный
        ctx.beginPath();
        ctx.arc(px, py, 17, 0, Math.PI * 2);
        ctx.fillStyle = TOKENS.me;
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = TOKENS.meInk;
        ctx.stroke();
        // точка в центре
        ctx.fillStyle = TOKENS.meInk;
        ctx.beginPath();
        ctx.arc(px, py, 5, 0, Math.PI * 2);
        ctx.fill();
        // пульсирующее кольцо (статичный декор без анимации — кольцо вокруг)
        ctx.lineWidth = 2;
        ctx.strokeStyle = alpha(TOKENS.me, 0.55);
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
        drawSettlements();   // ADR-101 — лендмарки всегда видны
        if (state.ripple)    drawRipples();
        if (state.showMe)    drawMe();
    }

    // --- animated pan + ripples ---

    function animatePan(toX, toY, duration, onDone){
        var fromX = state.viewX;
        var fromY = state.viewY;
        if (Math.abs(toX - fromX) < 0.01 && Math.abs(toY - fromY) < 0.01){
            if (onDone) onDone();
            return;
        }
        state.panAnim = {t0: performance.now(), duration: duration, fromX: fromX, fromY: fromY, toX: toX, toY: toY, onDone: onDone};
        requestAnimationFrame(panStep);
    }
    function panStep(){
        if (!state.panAnim) return;
        var t = (performance.now() - state.panAnim.t0) / state.panAnim.duration;
        if (t >= 1){
            state.viewX = state.panAnim.toX;
            state.viewY = state.panAnim.toY;
            var done = state.panAnim.onDone;
            state.panAnim = null;
            render();
            if (done) done();
            return;
        }
        var k = 1 - Math.pow(1 - t, 3); // easeOutCubic
        state.viewX = state.panAnim.fromX + (state.panAnim.toX - state.panAnim.fromX) * k;
        state.viewY = state.panAnim.fromY + (state.panAnim.toY - state.panAnim.fromY) * k;
        render();
        requestAnimationFrame(panStep);
    }

    function startRipple(){
        state.ripple = {t0: performance.now(), duration: 1800};
        function tick(){
            if (!state.ripple) return;
            var elapsed = performance.now() - state.ripple.t0;
            if (elapsed >= state.ripple.duration){
                state.ripple = null;
                render();
                return;
            }
            render();
            requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    function locateMe(){
        if (!state.me) return;
        // Гарантируем что слой Моя позиция включён
        if (meChk && !meChk.checked){
            meChk.checked = true;
            state.showMe = true;
        }
        // На 1× viewport покрывает весь мир — pan не нужен. На 2×+ — центрируем.
        var vsCells = viewSizeCells();
        var max = WORLD_SIZE - vsCells;
        var targetX = state.me.x - vsCells / 2;
        var targetY = state.me.y - vsCells / 2;
        if (targetX < 0) targetX = 0;
        if (targetY < 0) targetY = 0;
        if (targetX > max) targetX = max;
        if (targetY > max) targetY = max;
        animatePan(targetX, targetY, 600, startRipple);
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
                state.settlements = Array.isArray(json.settlements) ? json.settlements : [];
                state.events   = Array.isArray(json.events)   ? json.events   : [];
                state.me       = (json.me && typeof json.me === 'object') ? json.me : null;
                renderEvents();
                if (locateBtn) locateBtn.disabled = !state.me;
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
                    + ' · поселений: ' + state.settlements.length
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
    if (locateBtn){
        locateBtn.addEventListener('click', function(){
            if (this.disabled) return;
            locateMe();
        });
    }
    refresh.addEventListener('click',  function(){ reload(state.basemap); });

    // --- pointer math + tooltip + drag-pan ---
    function eventToCanvas(evt){
        var rect = canvas.getBoundingClientRect();
        var cx = (evt.clientX - rect.left) * (CANVAS_SIZE / rect.width);
        var cy = (evt.clientY - rect.top)  * (CANVAS_SIZE / rect.height);
        var pxPerCell = CANVAS_SIZE / viewSizeCells();
        // Клетка — те же координаты, что в игре: 0..999 (floor суммы в worldCoords).
        var wx = Math.min(WORLD_SIZE - 1, Math.max(0, Math.floor(cx / pxPerCell + state.viewX)));
        var wy = Math.min(WORLD_SIZE - 1, Math.max(0, Math.floor(cy / pxPerCell + state.viewY)));
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

    // ADR-101 §Принцип 2 — hit-test + лор-tooltip поселения.
    function nearestSettlement(cx, cy){
        if (!state.settlements.length) return null;
        var pxPerCell = CANVAS_SIZE / viewSizeCells();
        var HIT2 = 42 * 42;
        var best = null, bestD = HIT2;
        for (var i = 0; i < state.settlements.length; i++){
            var s = state.settlements[i];
            if (!s.x || !s.y) continue;
            var px = (s.x - state.viewX) * pxPerCell;
            var py = (s.y - state.viewY) * pxPerCell;
            var d = (cx - px) * (cx - px) + (cy - py) * (cy - py);
            if (d < bestD){ bestD = d; best = s; }
        }
        return best;
    }
    var SETTLE_TYPE = {outpost:'Аутпост (нейтральный)', bandit:'Логово', faction:'Оплот фракции', ruins:'Руины'};
    var SETTLE_ZONE = {safe:'🕊 Безопасная зона', hostile:'☠️ Опасная зона', neutral:''};
    function buildSettlementTip(s){
        while (tip.firstChild) tip.removeChild(tip.firstChild);
        var header = document.createElement('b');
        header.textContent = (s.icon || '🏚') + ' ' + (s.name || 'Поселение');
        tip.appendChild(header);
        var lines = [];
        if (SETTLE_TYPE[s.type]) lines.push(SETTLE_TYPE[s.type]);
        if (SETTLE_ZONE[s.zone]) lines.push(SETTLE_ZONE[s.zone]);
        if (s.lore) lines.push(s.lore);
        lines.push('X=' + s.x + ', Y=' + s.y);
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
        var st  = nearestSettlement(p.cx, p.cy);
        var car = st ? null : nearestCaravan(p.cx, p.cy);
        coords.textContent = 'Клетка (X=' + p.wx + ', Y=' + p.wy + ')';
        if (st){ buildSettlementTip(st); }
        else if (car){ buildCaravanTip(car); }
        else { tip.textContent = '(' + p.wx + ', ' + p.wy + ')'; }
        tip.classList.add('is-visible');
        var boxRect = box.getBoundingClientRect();
        var lx = p.clientX - boxRect.left;
        var ty = p.clientY - boxRect.top;
        // Флип «вниз», если над курсором мало места: иначе tooltip уходит за
        // верхнюю кромку и режется overflow:hidden у .ww-map-canvas-box. Бьёт по
        // маркерам у верха карты (ADR-101 руины на глубоком севере, Y<~160).
        // + лёгкий горизонтальный кламп (tooltip центрирован через -50%), чтобы
        // не срезало у левой/правой кромки.
        var tipH = tip.offsetHeight, tipW = tip.offsetWidth;
        tip.classList.toggle('is-below', ty < (tipH + 16));
        lx = Math.max(tipW / 2 + 4, Math.min(lx, boxRect.width - tipW / 2 - 4));
        tip.style.left = lx + 'px';
        tip.style.top  = ty + 'px';
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
