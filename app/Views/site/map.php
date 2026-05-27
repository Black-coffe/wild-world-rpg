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
?>

<?= $this->section('head') ?>
<style>
.ww-map-wrap{padding:2.2rem 0 3rem}
.ww-map-head{margin-bottom:1.3rem}
.ww-map-head h1{font-family:"Oswald",sans-serif;font-size:1.9rem;text-transform:uppercase;letter-spacing:.06em;margin:0 0 .4rem}
.ww-map-head p{color:var(--ww-muted);margin:0;font-size:.95rem;max-width:780px}
.ww-map-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:1.5rem;align-items:start}
@media (max-width: 900px){.ww-map-layout{grid-template-columns:1fr}}
.ww-map-stage{background:var(--ww-panel);border:1px solid var(--ww-line);border-radius:8px;padding:.6rem;position:relative;overflow:hidden}
.ww-map-canvas-box{position:relative;width:100%;aspect-ratio:1/1;background:#0e0c0a;border-radius:4px;overflow:hidden}
.ww-map-canvas-box canvas{display:block;width:100%;height:100%;cursor:crosshair;image-rendering:pixelated;image-rendering:crisp-edges}
.ww-map-canvas-box .ww-map-loader{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--ww-muted);font-size:.95rem;pointer-events:none;background:rgba(14,12,10,.85)}
.ww-map-canvas-box .ww-map-loader.is-hidden{display:none}
.ww-map-tooltip{position:absolute;pointer-events:none;background:rgba(13,17,24,.92);border:1px solid var(--ww-line);color:var(--ww-text);padding:.3rem .55rem;border-radius:4px;font-size:.82rem;font-family:"PT Sans",sans-serif;white-space:nowrap;transform:translate(-50%,-130%);display:none;z-index:5}
.ww-map-tooltip.is-visible{display:block}
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
.ww-map-future{font-size:.82rem;color:var(--ww-muted);line-height:1.5}
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="ww-map-wrap">
    <div class="container">
        <header class="ww-map-head">
            <h1>Карта мира</h1>
            <p>Остров Wild World 1000×1000 клеток. Переключай подложку, чтобы увидеть либо точную попиксельную биом-карту, либо художественный вид. Координатная сетка и подсказка по клетке — при наведении.</p>
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
                        <small>1 клетка = 2px, чистые биом-цвета. Структурный вид.</small>
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
                        <small>Тонкая сетка через 50 клеток, жирная через 100.</small>
                    </label>
                    <p class="ww-map-future">Следующая итерация: караваны, активные события, базы фракций, объекты мира — отдельными переключателями.</p>
                </div>

                <div class="ww-map-panel">
                    <h3>Обновление</h3>
                    <button type="button" class="ww-map-refresh" id="ww-map-refresh">🔄 Обновить</button>
                    <div class="ww-map-status" id="ww-map-status">Snapshot загружен.</div>
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
    var CANVAS_SIZE = 2000;        // внутреннее разрешение canvas
    var WORLD_SIZE  = 1000;        // 1000×1000 логических клеток
    var PX_PER_CELL = CANVAS_SIZE / WORLD_SIZE; // 2

    var canvas  = document.getElementById('ww-map-canvas');
    var ctx     = canvas.getContext('2d');
    var loader  = document.getElementById('ww-map-loader');
    var box     = document.getElementById('ww-map-box');
    var tip     = document.getElementById('ww-map-tooltip');
    var coords  = document.getElementById('ww-map-coords');
    var status  = document.getElementById('ww-map-status');
    var refresh = document.getElementById('ww-map-refresh');
    var gridChk = document.getElementById('ww-toggle-grid');

    var state = {
        basemap:    'pixel',
        showGrid:   true,
        baseImg:    null
    };

    function setLoader(visible, text){
        if (text) loader.textContent = text;
        loader.classList.toggle('is-hidden', !visible);
    }

    function loadBaseImage(kind){
        return new Promise(function(resolve, reject){
            var url = SOURCES[kind];
            if (!url) { reject(new Error('unknown basemap')); return; }
            var img = new Image();
            img.onload  = function(){ resolve(img); };
            img.onerror = function(){ reject(new Error('Не удалось загрузить ' + url)); };
            // на проде картинки лежат под тем же origin → CORS не требуется
            img.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
        });
    }

    function drawGrid(){
        ctx.save();
        // тонкие линии каждые 50 клеток
        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (var i = 50; i < WORLD_SIZE; i += 50){
            if (i % 100 === 0) continue;
            var p = i * PX_PER_CELL;
            ctx.beginPath(); ctx.moveTo(p, 0); ctx.lineTo(p, CANVAS_SIZE); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, p); ctx.lineTo(CANVAS_SIZE, p); ctx.stroke();
        }
        // жирные линии каждые 100 клеток
        ctx.strokeStyle = 'rgba(255,255,255,0.22)';
        ctx.lineWidth = 2;
        for (var j = 100; j < WORLD_SIZE; j += 100){
            var q = j * PX_PER_CELL;
            ctx.beginPath(); ctx.moveTo(q, 0); ctx.lineTo(q, CANVAS_SIZE); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, q); ctx.lineTo(CANVAS_SIZE, q); ctx.stroke();
        }
        // подписи координат на жирных линиях
        ctx.fillStyle = 'rgba(255,255,255,0.55)';
        ctx.font = 'bold 22px "Oswald", sans-serif';
        ctx.textBaseline = 'top';
        for (var k = 100; k < WORLD_SIZE; k += 100){
            var r = k * PX_PER_CELL;
            ctx.fillText(String(k), r + 4, 4);   // подпись X сверху
            ctx.fillText(String(k), 4, r + 4);   // подпись Y слева
        }
        ctx.restore();
    }

    function render(){
        if (!state.baseImg) return;
        ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        ctx.drawImage(state.baseImg, 0, 0, CANVAS_SIZE, CANVAS_SIZE);
        if (state.showGrid) drawGrid();
    }

    function reload(kind){
        setLoader(true, 'Загружаем карту…');
        loadBaseImage(kind).then(function(img){
            state.baseImg = img;
            render();
            setLoader(false);
            status.textContent = 'Snapshot обновлён в ' + new Date().toLocaleTimeString();
        }).catch(function(err){
            setLoader(true, 'Ошибка: ' + err.message);
            status.textContent = String(err.message || err);
        });
    }

    // --- события UI ---

    document.querySelectorAll('input[name="ww-basemap"]').forEach(function(r){
        r.addEventListener('change', function(){
            state.basemap = this.value;
            reload(state.basemap);
        });
    });

    gridChk.addEventListener('change', function(){
        state.showGrid = this.checked;
        render();
    });

    refresh.addEventListener('click', function(){
        reload(state.basemap);
    });

    // hover → координаты + tooltip
    function pointerToWorld(evt){
        var rect = canvas.getBoundingClientRect();
        var cx = (evt.clientX - rect.left) * (CANVAS_SIZE / rect.width);
        var cy = (evt.clientY - rect.top)  * (CANVAS_SIZE / rect.height);
        var wx = Math.min(WORLD_SIZE, Math.max(1, Math.floor(cx / PX_PER_CELL) + 1));
        var wy = Math.min(WORLD_SIZE, Math.max(1, Math.floor(cy / PX_PER_CELL) + 1));
        return {wx: wx, wy: wy, rect: rect, clientX: evt.clientX, clientY: evt.clientY};
    }

    canvas.addEventListener('mousemove', function(evt){
        var p = pointerToWorld(evt);
        coords.textContent = 'Клетка (X=' + p.wx + ', Y=' + p.wy + ')';
        tip.textContent = '(' + p.wx + ', ' + p.wy + ')';
        tip.classList.add('is-visible');
        // позиционируем tooltip относительно .ww-map-canvas-box
        var boxRect = box.getBoundingClientRect();
        tip.style.left = (p.clientX - boxRect.left) + 'px';
        tip.style.top  = (p.clientY - boxRect.top) + 'px';
    });
    canvas.addEventListener('mouseleave', function(){
        tip.classList.remove('is-visible');
        coords.textContent = 'Наведи курсор, чтобы увидеть координаты клетки';
    });

    // touch — короткий tap показывает координаты
    canvas.addEventListener('touchstart', function(evt){
        if (!evt.touches || !evt.touches[0]) return;
        var p = pointerToWorld(evt.touches[0]);
        coords.textContent = 'Клетка (X=' + p.wx + ', Y=' + p.wy + ')';
    }, {passive: true});

    // первый запуск
    reload(state.basemap);
})();
</script>
<?= $this->endSection() ?>
