<?php
/**
 * @var array<int,array<string,mixed>> $biomes
 * @var array<string,mixed>            $meta
 */
$this->extend('site/layout');

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
.ww-map-canvas-box canvas{display:block;width:100%;height:100%;cursor:crosshair;image-rendering:pixelated;image-rendering:crisp-edges}
.ww-map-canvas-box .ww-map-loader{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--ww-muted);font-size:.95rem;pointer-events:none;background:rgba(14,12,10,.85)}
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
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="ww-map-wrap">
    <div class="container">
        <header class="ww-map-head">
            <h1>Карта мира</h1>
            <p>Остров Wild World 1000×1000 клеток. Переключай подложку, включай слои с караванами и подсветкой биомов, смотри список активных мировых событий. Подсказка по клетке — при наведении.</p>
        </header>

        <div class="ww-map-layout">
            <div class="ww-map-stage">
                <div class="ww-map-canvas-box" id="ww-map-box">
                    <canvas id="ww-map-canvas" width="2000" height="2000" aria-label="Карта мира Wild World"></canvas>
                    <div class="ww-map-loader" id="ww-map-loader">Загружаем карту…</div>
                    <div class="ww-map-tooltip" id="ww-map-tooltip">(0, 0)</div>
                </div>
                <div class="ww-map-coords" id="ww-map-coords">Наведи курсор, чтобы увидеть координаты клетки</div>
            </div>

            <aside>
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
                    <label class="ww-map-opt">
                        <input type="checkbox" id="ww-toggle-biome-tint">
                        Биом-tint поверх
                        <small>Полупрозрачный biom-overlay поверх любой подложки (особенно полезно с «художественной»).</small>
                    </label>
                    <p class="ww-map-future">Скрыто от публичного доступа: игроки, базы фракций, схроны/объекты мира (для админа — отдельным release'ом).</p>
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
    var CANVAS_SIZE = 2000;
    var WORLD_SIZE  = 1000;
    var PX_PER_CELL = CANVAS_SIZE / WORLD_SIZE;
    var CARAVAN_HIT_RADIUS = 40; // в canvas-px = 20 логических клеток

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
    var eventsList = document.getElementById('ww-events-list');

    var state = {
        basemap:   'pixel',
        showGrid:  true,
        showCar:   false,
        showTint:  false,
        baseImg:   null,
        pixelImg:  null, // отдельно для biome-tint поверх beautiful
        caravans:  [],
        events:    []
    };

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

    function drawGrid(){
        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (var i = 50; i < WORLD_SIZE; i += 50){
            if (i % 100 === 0) continue;
            var p = i * PX_PER_CELL;
            ctx.beginPath(); ctx.moveTo(p, 0); ctx.lineTo(p, CANVAS_SIZE); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, p); ctx.lineTo(CANVAS_SIZE, p); ctx.stroke();
        }
        ctx.strokeStyle = 'rgba(255,255,255,0.22)';
        ctx.lineWidth = 2;
        for (var j = 100; j < WORLD_SIZE; j += 100){
            var q = j * PX_PER_CELL;
            ctx.beginPath(); ctx.moveTo(q, 0); ctx.lineTo(q, CANVAS_SIZE); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, q); ctx.lineTo(CANVAS_SIZE, q); ctx.stroke();
        }
        ctx.fillStyle = 'rgba(255,255,255,0.55)';
        ctx.font = 'bold 22px "Oswald", sans-serif';
        ctx.textBaseline = 'top';
        for (var k = 100; k < WORLD_SIZE; k += 100){
            var r = k * PX_PER_CELL;
            ctx.fillText(String(k), r + 4, 4);
            ctx.fillText(String(k), 4, r + 4);
        }
        ctx.restore();
    }

    function drawCaravans(){
        if (!state.caravans.length) return;
        ctx.save();
        for (var i = 0; i < state.caravans.length; i++){
            var c = state.caravans[i];
            if (!c.x || !c.y) continue;
            var px = (c.x - 1) * PX_PER_CELL;
            var py = (c.y - 1) * PX_PER_CELL;
            // тень
            ctx.beginPath();
            ctx.arc(px, py, 18, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(0,0,0,0.55)';
            ctx.fill();
            // обводка
            ctx.beginPath();
            ctx.arc(px, py, 14, 0, Math.PI * 2);
            ctx.fillStyle = '#f5a524';
            ctx.fill();
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#1a1408';
            ctx.stroke();
            // символ
            ctx.fillStyle = '#1a1408';
            ctx.font = 'bold 22px "Oswald", sans-serif';
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'center';
            ctx.fillText('₸', px, py + 1);
        }
        ctx.restore();
    }

    function render(){
        if (!state.baseImg) return;
        ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        ctx.drawImage(state.baseImg, 0, 0, CANVAS_SIZE, CANVAS_SIZE);
        if (state.showTint && state.pixelImg && state.basemap !== 'pixel'){
            ctx.save();
            ctx.globalAlpha = 0.45;
            ctx.drawImage(state.pixelImg, 0, 0, CANVAS_SIZE, CANVAS_SIZE);
            ctx.restore();
        }
        if (state.showGrid)   drawGrid();
        if (state.showCar)    drawCaravans();
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
                var biomeNames = e.biome_ids
                    .map(function(id){ return BIOMES[id] || ('Биом #' + id); })
                    .join(', ');
                parts.push('Биомы: ' + biomeNames);
            }
            if (e.effect && e.effect !== 'none') parts.push('Эффект: ' + e.effect);
            if (e.ends_at) parts.push('До: ' + e.ends_at.replace('T', ' '));
            meta.textContent = parts.join(' · ') || 'без подробностей';
            li.appendChild(meta);
            eventsList.appendChild(li);
        });
    }

    function fetchData(){
        return fetch(DATA_URL, {credentials: 'same-origin'})
            .then(function(r){
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(json){
                state.caravans = Array.isArray(json.caravans) ? json.caravans : [];
                state.events   = Array.isArray(json.events)   ? json.events   : [];
                renderEvents();
                render();
            })
            .catch(function(err){
                status.textContent = 'Ошибка слоёв: ' + (err.message || err);
            });
    }

    function reload(kind){
        setLoader(true, 'Загружаем карту…');
        var basePromise = loadImage(SOURCES[kind]);
        // Параллельно тянем pixel-карту для biome-tint, если ещё не загружена
        var tintPromise = (kind === 'pixel' || state.pixelImg)
            ? Promise.resolve(state.pixelImg)
            : loadImage(SOURCES.pixel).catch(function(){ return null; });

        return Promise.all([basePromise, tintPromise, fetchData()])
            .then(function(results){
                state.baseImg  = results[0];
                if (kind === 'pixel') state.pixelImg = results[0];
                else if (results[1])  state.pixelImg = results[1];
                render();
                setLoader(false);
                status.textContent = 'Snapshot: ' + new Date().toLocaleTimeString()
                    + ' · караванов: ' + state.caravans.length
                    + ' · событий: ' + state.events.length;
            })
            .catch(function(err){
                setLoader(true, 'Ошибка: ' + err.message);
                status.textContent = String(err.message || err);
            });
    }

    // --- UI ---

    document.querySelectorAll('input[name="ww-basemap"]').forEach(function(r){
        r.addEventListener('change', function(){
            state.basemap = this.value;
            reload(state.basemap);
        });
    });
    gridChk.addEventListener('change', function(){ state.showGrid = this.checked; render(); });
    carChk.addEventListener('change',  function(){ state.showCar  = this.checked; render(); });
    tintChk.addEventListener('change', function(){ state.showTint = this.checked; render(); });
    refresh.addEventListener('click',  function(){ reload(state.basemap); });

    // hover → координаты + tooltip + проверка попадания в караван
    function pointerToCanvas(evt){
        var rect = canvas.getBoundingClientRect();
        var cx = (evt.clientX - rect.left) * (CANVAS_SIZE / rect.width);
        var cy = (evt.clientY - rect.top)  * (CANVAS_SIZE / rect.height);
        var wx = Math.min(WORLD_SIZE, Math.max(1, Math.floor(cx / PX_PER_CELL) + 1));
        var wy = Math.min(WORLD_SIZE, Math.max(1, Math.floor(cy / PX_PER_CELL) + 1));
        return {cx: cx, cy: cy, wx: wx, wy: wy, clientX: evt.clientX, clientY: evt.clientY};
    }
    function nearestCaravan(cx, cy){
        if (!state.showCar || !state.caravans.length) return null;
        var best = null, bestD = CARAVAN_HIT_RADIUS * CARAVAN_HIT_RADIUS;
        for (var i = 0; i < state.caravans.length; i++){
            var c = state.caravans[i];
            if (!c.x || !c.y) continue;
            var px = (c.x - 1) * PX_PER_CELL;
            var py = (c.y - 1) * PX_PER_CELL;
            var d = (cx - px) * (cx - px) + (cy - py) * (cy - py);
            if (d < bestD){ bestD = d; best = c; }
        }
        return best;
    }
    function buildCaravanTip(car){
        // Безопасное построение через DOM API + textContent — не использовать innerHTML с данными из БД.
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

    canvas.addEventListener('mousemove', function(evt){
        var p = pointerToCanvas(evt);
        var car = nearestCaravan(p.cx, p.cy);
        coords.textContent = 'Клетка (X=' + p.wx + ', Y=' + p.wy + ')';
        if (car){
            buildCaravanTip(car);
        } else {
            tip.textContent = '(' + p.wx + ', ' + p.wy + ')';
        }
        tip.classList.add('is-visible');
        var boxRect = box.getBoundingClientRect();
        tip.style.left = (p.clientX - boxRect.left) + 'px';
        tip.style.top  = (p.clientY - boxRect.top) + 'px';
    });
    canvas.addEventListener('mouseleave', function(){
        tip.classList.remove('is-visible');
        coords.textContent = 'Наведи курсор, чтобы увидеть координаты клетки';
    });
    canvas.addEventListener('touchstart', function(evt){
        if (!evt.touches || !evt.touches[0]) return;
        var p = pointerToCanvas(evt.touches[0]);
        coords.textContent = 'Клетка (X=' + p.wx + ', Y=' + p.wy + ')';
    }, {passive: true});

    reload(state.basemap);
})();
</script>
<?= $this->endSection() ?>
