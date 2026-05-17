/*
 * Craft & Building Tree — admin/craft-tree
 * Vanilla JS. Hierarchical render, fuzzy-ish search, detail drawer,
 * auto-refresh every 30s with state preservation (expanded nodes,
 * selected node, search query).
 */
(function () {
    'use strict';

    const REFRESH_INTERVAL_MS = 30 * 1000;
    const STORAGE_KEY = 'ctTreeState/v1';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const state = {
        data: null,
        activeTab: 'crafts',
        search: '',
        expanded: new Set(),     // node-ids that are open
        selected: null,          // {type, id}
        lookups: { crafts: new Map(), buildings: new Map(), resources: new Map() },
    };

    // ── Storage ──────────────────────────────────────
    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const parsed = JSON.parse(raw);
            if (parsed.activeTab) state.activeTab = parsed.activeTab;
            if (parsed.search) state.search = parsed.search;
            if (Array.isArray(parsed.expanded)) state.expanded = new Set(parsed.expanded);
            if (parsed.selected) state.selected = parsed.selected;
        } catch (e) { /* ignore */ }
    }

    function persistState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                activeTab: state.activeTab,
                search: state.search,
                expanded: Array.from(state.expanded),
                selected: state.selected,
            }));
        } catch (e) { /* ignore */ }
    }

    // ── Fetch + auto-refresh ─────────────────────────
    async function fetchData(silent = false) {
        try {
            const res = await fetch(window.__ctDataUrl, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const payload = await res.json();
            state.data = payload;
            buildLookups();
            render();
            updateLiveBadge(true);
        } catch (err) {
            updateLiveBadge(false);
            if (!silent) {
                console.error('craft-tree fetch failed', err);
            }
        }
    }

    function buildLookups() {
        const cr = new Map();
        const bu = new Map();
        const rs = new Map();
        for (const wb of state.data.workbenches) {
            for (const dir of wb.directions) {
                for (const r of dir.recipes) cr.set(r.id, { node: r, workbench: wb.name, direction: dir.name });
            }
        }
        for (const b of state.data.buildings) bu.set(b.id, b);
        for (const r of state.data.resources) rs.set(r.id, r);
        state.lookups = { crafts: cr, buildings: bu, resources: rs };
    }

    function imageUrl(path) {
        if (!path) return null;
        const base = (window.__ctDataUrl || '').replace(/admin\/craft-tree\/data$/, '');
        return base + path.replace(/^\/+/, '');
    }

    function imageForCraftRef(refType, refId) {
        if (refType === 'crafted') {
            const entry = state.lookups.crafts.get(refId);
            return entry ? imageUrl(entry.node.image) : null;
        }
        return null;
    }

    function updateLiveBadge(ok) {
        const badge = $('#ct-live-badge');
        const upd = $('#ct-updated');
        if (!badge) return;
        if (ok) {
            badge.classList.remove('is-stale');
            badge.querySelector('i').className = 'ri-radio-button-line';
        } else {
            badge.classList.add('is-stale');
            badge.querySelector('i').className = 'ri-error-warning-line';
        }
        if (upd) {
            const now = new Date();
            upd.textContent = 'Обновлено ' + now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
    }

    // ── Format helpers ───────────────────────────────
    function fmtGold(v) {
        if (v === null || v === undefined) return '?';
        if (v >= 1000) return (v / 1000).toFixed(v >= 10000 ? 0 : 1) + 'k';
        if (Number.isInteger(v)) return String(v);
        return v.toFixed(2);
    }

    function escapeHTML(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    function highlightMatch(text, q) {
        const safe = escapeHTML(text);
        if (!q) return safe;
        const idx = safe.toLowerCase().indexOf(q.toLowerCase());
        if (idx < 0) return safe;
        return safe.slice(0, idx) + '<span class="ct-mark">' + safe.slice(idx, idx + q.length) + '</span>' + safe.slice(idx + q.length);
    }

    function matchesQuery(text, q) {
        if (!q) return true;
        return String(text || '').toLowerCase().includes(q.toLowerCase());
    }

    // ── Render: stats / counts ───────────────────────
    function renderStats() {
        const s = state.data.stats;
        $('#ct-stats').textContent =
            `${s.recipes} рецептов · ${s.buildings} построек · ${s.resources} ресурсов`;
        $('#ct-count-crafts').textContent = s.recipes;
        $('#ct-count-buildings').textContent = s.buildings;
        $('#ct-count-resources').textContent = s.resources;
    }

    // ── Render: crafts (workbenches → directions → recipes) ──
    function renderCrafts() {
        const q = state.search.trim().toLowerCase();
        const groups = state.data.workbenches.map(wb => renderWorkbench(wb, q)).filter(Boolean);
        if (groups.length === 0 && q) {
            return '<div class="ct-empty"><i class="ri-search-eye-line"></i><div>Ничего не найдено по запросу <b>' + escapeHTML(q) + '</b></div></div>';
        }
        return groups.join('');
    }

    function renderWorkbench(wb, q) {
        const directionsHTML = wb.directions.map(d => renderDirection(d, q, wb.key)).filter(Boolean).join('');
        const matchSelf = matchesQuery(wb.name, q);
        if (!matchSelf && !directionsHTML && q) return null;

        const nodeId = 'wb:' + wb.key;
        const isOpen = state.expanded.has(nodeId) || q.length > 0;

        const tierPill = wb.tier > 0
            ? `<span class="ct-pill tier-${wb.tier}">Tier ${wb.tier}</span>` : '';

        return `
        <div class="ct-group ${isOpen ? 'is-open' : ''}" data-node-id="${escapeHTML(nodeId)}">
            <div class="ct-group-head" data-toggle="${escapeHTML(nodeId)}">
                <span class="ct-chevron"><i class="ri-arrow-right-s-line"></i></span>
                <span class="ct-group-icon"><i class="${escapeHTML(wb.icon)}"></i></span>
                <span class="ct-group-title">${highlightMatch(wb.name, q)}</span>
                <span class="ct-group-meta">
                    ${tierPill}
                    <span class="ct-pill"><i class="ri-stack-line"></i>${wb.recipeCount}</span>
                </span>
            </div>
            <div class="ct-group-body">
                <div class="ct-group-inner">${directionsHTML}</div>
            </div>
        </div>`;
    }

    function renderDirection(dir, q, wbKey) {
        const recipesHTML = dir.recipes.map(r => renderRecipeRow(r, q)).filter(Boolean).join('');
        const matchSelf = matchesQuery(dir.name, q);
        if (!matchSelf && !recipesHTML && q) return null;

        const nodeId = 'dir:' + wbKey + ':' + dir.key;
        const isOpen = state.expanded.has(nodeId) || q.length > 0 || dir.recipes.length <= 3;

        return `
        <div class="ct-direction ${isOpen ? 'is-open' : ''}" data-node-id="${escapeHTML(nodeId)}">
            <div class="ct-direction-head" data-toggle="${escapeHTML(nodeId)}">
                <span class="ct-chevron"><i class="ri-arrow-right-s-line"></i></span>
                <span class="ct-direction-icon"><i class="${escapeHTML(dir.icon)}"></i></span>
                <span class="ct-direction-title">${highlightMatch(dir.name, q)}</span>
                <span class="ct-group-meta">
                    <span class="ct-pill">${dir.count}</span>
                </span>
            </div>
            <div class="ct-direction-body">${recipesHTML}</div>
        </div>`;
    }

    function renderRecipeRow(r, q) {
        const recipeNameMatch = matchesQuery(r.name, q) || matchesQuery(r.nameEn, q);
        const ingredientMatch = r.ingredients.some(i => matchesQuery(i.name, q));
        if (q && !recipeNameMatch && !ingredientMatch) return null;

        const isSelected = state.selected && state.selected.type === 'recipe' && state.selected.id === r.id;
        const enLabel = r.nameEn ? `<span class="ct-name-en">${escapeHTML(r.nameEn)}</span>` : '';

        const metaPills = [];
        if (r.craftingTime) metaPills.push(`<span class="ct-pill"><i class="ri-time-line"></i>${escapeHTML(r.craftingTime)}</span>`);
        if (r.totalCost !== null && r.totalCost !== undefined) {
            metaPills.push(`<span class="ct-pill gold"><i class="ri-coin-line"></i>${fmtGold(r.totalCost)}</span>`);
        }
        if (r.requiredLevel && r.requiredLevel > 1) {
            metaPills.push(`<span class="ct-pill"><i class="ri-shield-user-line"></i>L${r.requiredLevel}</span>`);
        }
        const hasMissing = r.ingredients.some(i => i.missing);
        if (hasMissing) metaPills.push(`<span class="ct-pill warn" title="Часть ингредиентов не найдена в БД"><i class="ri-alert-line"></i></span>`);

        const ingredientsHTML = r.ingredients.map(i => renderIngredient(i)).join('');

        const nodeId = 'recipe:' + r.id;

        const imgSrc = imageUrl(r.image);
        const iconOrThumb = imgSrc
            ? `<img class="ct-row-thumb" src="${escapeHTML(imgSrc)}" alt="" width="42" height="42" loading="lazy" decoding="async" data-action="zoom" data-src="${escapeHTML(imgSrc)}" data-caption="${escapeHTML(r.name)}" onerror="this.outerHTML='<span class=\\'ct-row-icon\\'><i class=\\'${escapeHTML(r.icon)}\\'></i></span>'">`
            : `<span class="ct-row-icon"><i class="${escapeHTML(r.icon)}"></i></span>`;

        return `
        <div class="ct-row-wrap" data-node-id="${escapeHTML(nodeId)}">
            <div class="ct-row ${isSelected ? 'is-selected' : ''} ${q && (recipeNameMatch || matchesQuery(r.nameEn, q)) ? 'is-match' : ''}"
                 data-action="select" data-type="recipe" data-id="${r.id}"
                 data-tip-text="${escapeHTML(buildRecipeTip(r))}">
                ${iconOrThumb}
                <span class="ct-row-title">${highlightMatch(r.name, q)} ${enLabel}</span>
                <span class="ct-row-meta">${metaPills.join('')}</span>
            </div>
            <div class="ct-row-children">${ingredientsHTML}</div>
        </div>`;
    }

    function renderIngredient(i, withCost = true) {
        const refCrafted = i.refType === 'crafted';
        const cls = ['ct-ingredient'];
        if (i.missing) cls.push('is-missing');
        if (refCrafted) cls.push('is-crafted');

        const qtyText = i.qty + (i.unit ? ' ' + i.unit : '');
        const costText = withCost && i.totalCost !== null && i.totalCost !== undefined
            ? `<span class="ct-ingredient-cost"><i class="ri-coin-line"></i>${fmtGold(i.totalCost)}</span>` : '';
        const refAttrs = refCrafted ? `data-action="select" data-type="recipe" data-id="${i.refId}"` :
            (i.refType === 'resource' ? `data-action="select" data-type="resource" data-id="${i.refId}"` : '');

        const subImg = imageForCraftRef(i.refType, i.refId);
        const iconNode = subImg
            ? `<img class="ct-ingredient-thumb" src="${escapeHTML(subImg)}" alt="" width="26" height="26" loading="lazy" decoding="async" data-action="zoom" data-src="${escapeHTML(subImg)}" data-caption="${escapeHTML(i.name)}" onerror="this.outerHTML='<span class=\\'ct-ingredient-icon\\'><i class=\\'${escapeHTML(i.icon)}\\'></i></span>'">`
            : `<span class="ct-ingredient-icon"><i class="${escapeHTML(i.icon)}"></i></span>`;

        return `
        <div class="${cls.join(' ')}" ${refAttrs}>
            ${iconNode}
            <span>${escapeHTML(i.name)}</span>
            <span class="ct-ingredient-qty">×${qtyText} ${costText}</span>
        </div>`;
    }

    function buildRecipeTip(r) {
        const lines = [r.name];
        if (r.totalCost !== null) lines.push('Сумма ингредиентов: ' + fmtGold(r.totalCost) + ' золота');
        if (r.price) lines.push('Продажа: ' + fmtGold(r.price));
        if (r.craftingTime) lines.push('Время: ' + r.craftingTime);
        if (r.description) lines.push(String(r.description).slice(0, 120));
        return lines.join('\n');
    }

    // ── Render: buildings ────────────────────────────
    function renderBuildings() {
        const q = state.search.trim().toLowerCase();
        const groups = {};
        for (const b of state.data.buildings) {
            const key = b.buildingType || 'other';
            (groups[key] ||= []).push(b);
        }
        const labels = {
            military: '🛡 Военные',
            residential: '🏠 Жилые',
            farming: '🌾 Фермерские',
            resource: '📦 Ресурсные',
            engineering: '⚙️ Инженерные',
            other: '🏗 Прочие',
        };
        const order = ['resource', 'engineering', 'farming', 'residential', 'military', 'other'];

        let html = '';
        for (const k of order) {
            if (!groups[k]) continue;
            const items = groups[k].map(b => renderBuildingRow(b, q)).filter(Boolean).join('');
            const matches = matchesQuery(labels[k], q);
            if (!items && !matches && q) continue;

            const nodeId = 'bld:' + k;
            const isOpen = state.expanded.has(nodeId) || q.length > 0 || true; // default open buildings (only 9 entries)
            html += `
            <div class="ct-group ${isOpen ? 'is-open' : ''}" data-node-id="${escapeHTML(nodeId)}">
                <div class="ct-group-head" data-toggle="${escapeHTML(nodeId)}">
                    <span class="ct-chevron"><i class="ri-arrow-right-s-line"></i></span>
                    <span class="ct-group-icon"><i class="ri-building-2-fill"></i></span>
                    <span class="ct-group-title">${highlightMatch(labels[k], q)}</span>
                    <span class="ct-group-meta">
                        <span class="ct-pill">${groups[k].length}</span>
                    </span>
                </div>
                <div class="ct-group-body">
                    <div class="ct-group-inner">${items}</div>
                </div>
            </div>`;
        }
        return html || '<div class="ct-empty"><i class="ri-search-eye-line"></i><div>Ничего не найдено</div></div>';
    }

    function renderBuildingRow(b, q) {
        const nameMatch = matchesQuery(b.name, q) || matchesQuery(b.nameEn, q);
        const ingredientMatch = b.ingredients.some(i => matchesQuery(i.name, q));
        if (q && !nameMatch && !ingredientMatch) return null;

        const isSelected = state.selected && state.selected.type === 'building' && state.selected.id === b.id;

        const metaPills = [];
        metaPills.push(`<span class="ct-pill tier-${Math.min(3, b.level || 1)}">Ур. ${b.level || 1}</span>`);
        if (b.constructionTime) metaPills.push(`<span class="ct-pill"><i class="ri-time-line"></i>${b.constructionTime} мин</span>`);
        metaPills.push(`<span class="ct-pill gold"><i class="ri-coin-line"></i>${fmtGold(b.totalCost)}</span>`);
        if (b.tax) metaPills.push(`<span class="ct-pill" title="Налог/сутки в золоте"><i class="ri-money-cny-circle-line"></i>${b.tax}</span>`);
        if (b.hasMissing) metaPills.push(`<span class="ct-pill warn"><i class="ri-alert-line"></i></span>`);

        const ingredientsHTML = b.ingredients.map(i => renderIngredient(i)).join('');
        const bImg = imageUrl(b.image);
        const bIconNode = bImg
            ? `<img class="ct-row-thumb" src="${escapeHTML(bImg)}" alt="" width="42" height="42" loading="lazy" decoding="async" data-action="zoom" data-src="${escapeHTML(bImg)}" data-caption="${escapeHTML(b.name)}" onerror="this.outerHTML='<span class=\\'ct-row-icon\\'><i class=\\'${escapeHTML(b.icon)}\\'></i></span>'">`
            : `<span class="ct-row-icon"><i class="${escapeHTML(b.icon)}"></i></span>`;

        return `
        <div class="ct-row-wrap">
            <div class="ct-row ${isSelected ? 'is-selected' : ''} ${q && nameMatch ? 'is-match' : ''}"
                 data-action="select" data-type="building" data-id="${b.id}">
                ${bIconNode}
                <span class="ct-row-title">${highlightMatch(b.name, q)} ${b.nameEn ? '<span class="ct-name-en">' + escapeHTML(b.nameEn) + '</span>' : ''}</span>
                <span class="ct-row-meta">${metaPills.join('')}</span>
            </div>
            <div class="ct-row-children">${ingredientsHTML}</div>
        </div>`;
    }

    // ── Render: resources ────────────────────────────
    function renderResources() {
        const q = state.search.trim().toLowerCase();
        const filtered = state.data.resources.filter(r =>
            !q || matchesQuery(r.name, q) || matchesQuery(r.nameEn, q) || matchesQuery(r.type, q)
        );
        if (filtered.length === 0) {
            return '<div class="ct-empty"><i class="ri-search-eye-line"></i><div>Ничего не найдено</div></div>';
        }
        return '<div class="ct-resource-grid">' + filtered.map(r => {
            const isSelected = state.selected && state.selected.type === 'resource' && state.selected.id === r.id;
            return `
            <div class="ct-resource-card ${isSelected ? 'is-selected' : ''}"
                 data-action="select" data-type="resource" data-id="${r.id}">
                <div class="ct-row-title">
                    <span class="ct-rarity ct-rarity-${r.rarity}" title="Редкость ${r.rarity}/10">${r.rarity}</span>
                    ${r.iconText ? escapeHTML(r.iconText) + ' ' : ''}${highlightMatch(r.name, q)}
                    ${r.nameEn ? '<span class="ct-name-en">' + escapeHTML(r.nameEn) + '</span>' : ''}
                </div>
                <div class="ct-row-meta">
                    <span class="ct-pill gold"><i class="ri-coin-line"></i>buy ${fmtGold(r.buyPrice)}</span>
                    <span class="ct-pill"><i class="ri-coin-line"></i>sell ${fmtGold(r.sellPrice)}</span>
                </div>
                <div class="text-muted small">${escapeHTML(r.type || '—')}</div>
            </div>`;
        }).join('') + '</div>';
    }

    // ── Render: detail drawer ────────────────────────
    function renderDetail() {
        const drawer = $('#ct-detail');
        if (!state.selected) {
            drawer.innerHTML = `
                <div class="ct-detail-placeholder">
                    <i class="ri-information-line"></i>
                    <h6 class="mt-2">Выбери элемент дерева</h6>
                    <p class="text-muted small mb-0">
                        Кликни на любой рецепт, здание или ингредиент &mdash; здесь появятся
                        детали: компоненты, стоимость в золоте, обратные ссылки, описание.
                    </p>
                </div>`;
            return;
        }
        const { type, id } = state.selected;
        if (type === 'recipe') {
            const entry = state.lookups.crafts.get(id);
            if (!entry) { state.selected = null; renderDetail(); return; }
            drawer.innerHTML = recipeDetailHTML(entry);
        } else if (type === 'building') {
            const b = state.lookups.buildings.get(id);
            if (!b) { state.selected = null; renderDetail(); return; }
            drawer.innerHTML = buildingDetailHTML(b);
        } else if (type === 'resource') {
            const r = state.lookups.resources.get(id);
            if (!r) { state.selected = null; renderDetail(); return; }
            drawer.innerHTML = resourceDetailHTML(r);
        }
    }

    function heroImageHTML(name, imagePath, fallbackIcon) {
        const src = imageUrl(imagePath);
        if (src) {
            // eager-load + sync decode для немедленного появления; onerror → fallback на icon-placeholder
            return `<img class="ct-hero-image" src="${escapeHTML(src)}" alt="" width="150" height="150" decoding="sync" data-action="zoom" data-src="${escapeHTML(src)}" data-caption="${escapeHTML(name)}" onerror="this.outerHTML='<div class=\\'ct-hero-image-placeholder\\'><i class=\\'${escapeHTML(fallbackIcon)}\\'></i></div>'">`;
        }
        return `<div class="ct-hero-image-placeholder"><i class="${escapeHTML(fallbackIcon)}"></i></div>`;
    }

    function recipeDetailHTML({ node: r, workbench, direction }) {
        const stats = [
            r.craftingTime ? { l: 'Время', v: r.craftingTime } : null,
            r.totalCost !== null ? { l: 'Стоимость', v: fmtGold(r.totalCost) + ' зол.' } : null,
            r.price ? { l: 'Цена в лавке', v: fmtGold(r.price) + ' зол.' } : null,
            r.requiredLevel ? { l: 'Уровень', v: r.requiredLevel } : null,
            r.weight ? { l: 'Вес', v: r.weight } : null,
            r.stackSize ? { l: 'Стак', v: r.stackSize } : null,
            r.durabilityCount ? { l: 'Прочн.', v: r.durabilityCount } : null,
            r.damage ? { l: 'Урон', v: r.damage } : null,
            r.armor ? { l: 'Броня', v: r.armor } : null,
            r.hp ? { l: 'HP', v: r.hp } : null,
        ].filter(Boolean);

        const usedBy = findRecipeUsages('crafted', r.id);

        return `
        <div class="ct-detail-head">
            ${heroImageHTML(r.name, r.image, r.icon)}
            <div>
                <div class="ct-detail-title">${escapeHTML(r.name)}</div>
                <div class="ct-detail-subtitle">
                    ${r.nameEn ? escapeHTML(r.nameEn) + ' · ' : ''}${escapeHTML(workbench)} · ${escapeHTML(direction)}
                </div>
            </div>
        </div>

        ${stats.length ? `
        <div class="ct-detail-section">
            <div class="ct-stat-grid">
                ${stats.map(s => `<div class="ct-stat-cell">
                    <div class="ct-stat-label">${escapeHTML(s.l)}</div>
                    <div class="ct-stat-value">${escapeHTML(String(s.v))}</div>
                </div>`).join('')}
            </div>
        </div>` : ''}

        ${r.effect || r.characterBoost ? `
        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Эффект</div>
            <div class="small text-muted">${escapeHTML(r.effect)} ${r.characterBoost ? '· ' + escapeHTML(r.characterBoost) : ''}</div>
        </div>` : ''}

        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Компоненты (${r.ingredients.length})</div>
            ${r.ingredients.map(i => renderIngredient(i)).join('') || '<div class="text-muted small">—</div>'}
        </div>

        ${usedBy.length ? `
        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Где используется (${usedBy.length})</div>
            ${usedBy.map(u => `<div class="ct-ingredient is-crafted" data-action="select" data-type="${u.type}" data-id="${u.id}">
                <span class="ct-ingredient-icon"><i class="${escapeHTML(u.icon)}"></i></span>
                <span>${escapeHTML(u.name)}</span>
                <span class="ct-ingredient-qty">×${u.qty}</span>
            </div>`).join('')}
        </div>` : ''}

        ${r.description ? `
        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Описание</div>
            <div class="small">${escapeHTML(r.description)}</div>
        </div>` : ''}`;
    }

    function buildingDetailHTML(b) {
        const stats = [
            { l: 'Уровень', v: b.level },
            b.constructionTime ? { l: 'Стройка', v: b.constructionTime + ' мин' } : null,
            { l: 'Стоимость', v: fmtGold(b.totalCost) + ' зол.' },
            b.tax ? { l: 'Налог/сут', v: b.tax } : null,
            b.hp ? { l: 'HP', v: b.hp } : null,
            b.minCharacterLevel ? { l: 'Игроку', v: 'Lvl ' + b.minCharacterLevel } : null,
            b.usage ? { l: 'Доступ', v: b.usage } : null,
        ].filter(Boolean);

        return `
        <div class="ct-detail-head">
            ${heroImageHTML(b.name, b.image, b.icon)}
            <div>
                <div class="ct-detail-title">${escapeHTML(b.name)}</div>
                <div class="ct-detail-subtitle">
                    ${b.nameEn ? escapeHTML(b.nameEn) + ' · ' : ''}${escapeHTML(b.buildingType)}
                </div>
            </div>
        </div>

        <div class="ct-detail-section">
            <div class="ct-stat-grid">
                ${stats.map(s => `<div class="ct-stat-cell">
                    <div class="ct-stat-label">${escapeHTML(s.l)}</div>
                    <div class="ct-stat-value">${escapeHTML(String(s.v))}</div>
                </div>`).join('')}
            </div>
        </div>

        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Материалы (${b.ingredients.length})</div>
            ${b.ingredients.map(i => renderIngredient(i)).join('') || '<div class="text-muted small">—</div>'}
        </div>

        ${b.description ? `
        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Описание</div>
            <div class="small">${escapeHTML(b.description)}</div>
        </div>` : ''}`;
    }

    function resourceDetailHTML(r) {
        const stats = [
            { l: 'Базовая цена', v: fmtGold(r.price) + ' з.' },
            r.buyPrice ? { l: 'Покупка', v: fmtGold(r.buyPrice) + ' з.' } : null,
            r.sellPrice ? { l: 'Продажа', v: fmtGold(r.sellPrice) + ' з.' } : null,
            { l: 'Редкость', v: r.rarity + '/10' },
            r.levelRequired ? { l: 'Уровень', v: r.levelRequired } : null,
            r.type ? { l: 'Тип', v: r.type } : null,
        ].filter(Boolean);

        const usedBy = findRecipeUsages('resource', r.id);

        return `
        <div class="ct-detail-head">
            <div class="ct-hero-image-placeholder" title="У ресурсов нет картинок, только emoji">
                <span style="font-size:3.4rem;line-height:1">${r.iconText ? escapeHTML(r.iconText) : '📦'}</span>
            </div>
            <div>
                <div class="ct-detail-title">${escapeHTML(r.name)}</div>
                <div class="ct-detail-subtitle">${r.nameEn ? escapeHTML(r.nameEn) + ' · ' : ''}Ресурс</div>
            </div>
        </div>

        <div class="ct-detail-section">
            <div class="ct-stat-grid">
                ${stats.map(s => `<div class="ct-stat-cell">
                    <div class="ct-stat-label">${escapeHTML(s.l)}</div>
                    <div class="ct-stat-value">${escapeHTML(String(s.v))}</div>
                </div>`).join('')}
            </div>
        </div>

        ${usedBy.length ? `
        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Где используется (${usedBy.length})</div>
            ${usedBy.map(u => `<div class="ct-ingredient is-crafted" data-action="select" data-type="${u.type}" data-id="${u.id}">
                <span class="ct-ingredient-icon"><i class="${escapeHTML(u.icon)}"></i></span>
                <span>${escapeHTML(u.name)}</span>
                <span class="ct-ingredient-qty">×${u.qty}</span>
            </div>`).join('')}
        </div>` : '<div class="ct-detail-section"><div class="text-muted small">Этот ресурс пока не используется ни в одном рецепте или постройке.</div></div>'}

        ${r.description ? `
        <div class="ct-detail-section">
            <div class="ct-detail-section-title">Описание</div>
            <div class="small">${escapeHTML(r.description)}</div>
        </div>` : ''}`;
    }

    function findRecipeUsages(refType, refId) {
        const out = [];
        state.lookups.crafts.forEach(({ node: r }) => {
            for (const i of r.ingredients) {
                if (i.refType === refType && i.refId === refId) {
                    out.push({ type: 'recipe', id: r.id, name: r.name, icon: r.icon, qty: i.qty });
                    break;
                }
            }
        });
        state.lookups.buildings.forEach(b => {
            for (const i of b.ingredients) {
                if (i.refType === refType && i.refId === refId) {
                    out.push({ type: 'building', id: b.id, name: b.name, icon: b.icon, qty: i.qty });
                    break;
                }
            }
        });
        return out;
    }

    // ── Master render dispatch ───────────────────────
    function render() {
        if (!state.data) return;
        renderStats();
        const tree = $('#ct-tree');
        tree.removeAttribute('aria-busy');
        let html = '';
        if (state.activeTab === 'crafts') html = renderCrafts();
        else if (state.activeTab === 'buildings') html = renderBuildings();
        else if (state.activeTab === 'resources') html = renderResources();
        tree.innerHTML = html;
        renderDetail();
        // tabs visual state
        $$('button[data-ct-tab]').forEach(el => {
            el.classList.toggle('active', el.dataset.ctTab === state.activeTab);
        });
        // search hint
        const q = state.search.trim();
        const hint = $('#ct-search-hint');
        $('#ct-search-clear').classList.toggle('d-none', q === '');
        if (q) {
            const matchCount = countMatches(q);
            hint.textContent = matchCount
                ? `Найдено: ${matchCount}`
                : 'Совпадений нет — попробуй другой запрос или переключи вкладку.';
        } else {
            hint.textContent = '';
        }
    }

    function countMatches(q) {
        const lq = q.toLowerCase();
        let n = 0;
        if (state.activeTab === 'crafts') {
            state.lookups.crafts.forEach(({ node: r }) => {
                if (matchesQuery(r.name, lq) || matchesQuery(r.nameEn, lq) || r.ingredients.some(i => matchesQuery(i.name, lq))) n++;
            });
        } else if (state.activeTab === 'buildings') {
            state.lookups.buildings.forEach(b => {
                if (matchesQuery(b.name, lq) || matchesQuery(b.nameEn, lq) || b.ingredients.some(i => matchesQuery(i.name, lq))) n++;
            });
        } else if (state.activeTab === 'resources') {
            state.lookups.resources.forEach(r => {
                if (matchesQuery(r.name, lq) || matchesQuery(r.nameEn, lq)) n++;
            });
        }
        return n;
    }

    // ── Interactions ────────────────────────────────
    function attachEvents() {
        const tree = $('#ct-tree');
        tree.addEventListener('click', e => {
            const toggleNode = e.target.closest('[data-toggle]');
            if (toggleNode) {
                const id = toggleNode.dataset.toggle;
                const card = toggleNode.closest('[data-node-id="' + CSS.escape(id) + '"]');
                if (card) {
                    const isOpen = card.classList.toggle('is-open');
                    if (isOpen) state.expanded.add(id);
                    else state.expanded.delete(id);
                    persistState();
                }
                return;
            }
            const selectNode = e.target.closest('[data-action="select"]');
            if (selectNode) {
                const t = selectNode.dataset.type;
                const i = parseInt(selectNode.dataset.id, 10);
                if (t && !Number.isNaN(i)) {
                    state.selected = { type: t === 'recipe' ? 'recipe' : t, id: i };
                    persistState();
                    render();
                    // also navigate if user clicks ingredient → switch tab if needed
                    if (t === 'recipe' && state.activeTab !== 'crafts') {
                        state.activeTab = 'crafts';
                        render();
                    } else if (t === 'resource' && state.activeTab !== 'resources') {
                        state.activeTab = 'resources';
                        render();
                    }
                }
            }
        });

        // Tooltip on hover
        const tip = document.createElement('div');
        tip.className = 'ct-tip';
        document.body.appendChild(tip);
        let tipHideTimer = null;
        tree.addEventListener('mouseover', e => {
            const t = e.target.closest('[data-tip-text]');
            if (!t) return;
            tip.textContent = t.dataset.tipText;
            const r = t.getBoundingClientRect();
            tip.style.left = (window.scrollX + r.left) + 'px';
            tip.style.top = (window.scrollY + r.top - tip.offsetHeight - 8) + 'px';
            tip.classList.add('is-visible');
            clearTimeout(tipHideTimer);
        });
        tree.addEventListener('mouseout', e => {
            const t = e.target.closest('[data-tip-text]');
            if (!t) return;
            tipHideTimer = setTimeout(() => tip.classList.remove('is-visible'), 100);
        });

        // Detail drawer clicks (forwarded into select)
        $('#ct-detail').addEventListener('click', e => {
            const selectNode = e.target.closest('[data-action="select"]');
            if (selectNode) {
                const t = selectNode.dataset.type;
                const i = parseInt(selectNode.dataset.id, 10);
                if (t && !Number.isNaN(i)) {
                    state.selected = { type: t, id: i };
                    if (t === 'recipe') state.activeTab = 'crafts';
                    else if (t === 'building') state.activeTab = 'buildings';
                    else if (t === 'resource') state.activeTab = 'resources';
                    persistState();
                    render();
                }
            }
        });

        // Tabs
        $$('button[data-ct-tab]').forEach(b => {
            b.addEventListener('click', () => {
                state.activeTab = b.dataset.ctTab;
                persistState();
                render();
            });
        });

        // Search (debounced)
        const search = $('#ct-search');
        search.value = state.search;
        let searchTimer = null;
        search.addEventListener('input', e => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.search = e.target.value;
                persistState();
                render();
            }, 120);
        });

        $('#ct-search-clear').addEventListener('click', () => {
            search.value = '';
            state.search = '';
            persistState();
            render();
            search.focus();
        });

        // Refresh button
        $('#ct-refresh').addEventListener('click', () => fetchData(false));

        // Expand / collapse all
        $('#ct-expand-all').addEventListener('click', () => {
            $$('.ct-tree [data-node-id]').forEach(el => {
                state.expanded.add(el.dataset.nodeId);
                el.classList.add('is-open');
            });
            persistState();
        });
        $('#ct-collapse-all').addEventListener('click', () => {
            $$('.ct-tree [data-node-id]').forEach(el => {
                state.expanded.delete(el.dataset.nodeId);
                el.classList.remove('is-open');
            });
            persistState();
        });

        // Image modal — delegated click
        const modal = createModal();
        const handleZoomClick = e => {
            const t = e.target.closest('[data-action="zoom"]');
            if (!t) return;
            e.preventDefault();
            e.stopPropagation();
            openModal(modal, t.dataset.src, t.dataset.caption || '');
        };
        tree.addEventListener('click', handleZoomClick, true);
        $('#ct-detail').addEventListener('click', handleZoomClick, true);

        modal.addEventListener('click', e => {
            if (e.target === modal || e.target.closest('.ct-modal-close')) {
                closeModal(modal);
            }
        });

        // Keyboard: Esc clears search OR closes modal
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                if (modal.classList.contains('is-visible')) {
                    closeModal(modal);
                    return;
                }
                if (state.search) {
                    state.search = '';
                    search.value = '';
                    persistState();
                    render();
                }
            }
            if (e.key === '/' && document.activeElement !== search) {
                e.preventDefault();
                search.focus();
            }
        });
    }

    function createModal() {
        let modal = document.querySelector('.ct-modal-backdrop');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.className = 'ct-modal-backdrop';
        modal.innerHTML = `
            <button class="ct-modal-close" type="button" aria-label="Закрыть"><i class="ri-close-line"></i></button>
            <img class="ct-modal-img" alt="">
            <div class="ct-modal-caption"></div>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    function openModal(modal, src, caption) {
        modal.querySelector('.ct-modal-img').src = src;
        modal.querySelector('.ct-modal-img').alt = caption || '';
        modal.querySelector('.ct-modal-caption').textContent = caption || '';
        modal.classList.add('is-visible');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('is-visible');
        document.body.style.overflow = '';
    }

    // ── Boot ─────────────────────────────────────────
    loadState();
    attachEvents();
    fetchData(false);
    setInterval(() => fetchData(true), REFRESH_INTERVAL_MS);
})();
