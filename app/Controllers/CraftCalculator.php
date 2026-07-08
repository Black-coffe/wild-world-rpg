<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CraftCalculator\CraftCalculatorService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ADR-149 (Поток E1, SEO-WEB-ROADMAP) — публичный калькулятор крафта wildworld.fun.
 *
 * Прогрессивное улучшение:
 *  - БЕЗ JS: `index()` читает `?recipe=&qty=` из GET-формы, считает на сервере
 *    ([[CraftCalculatorService::expand]]) и рендерит результат инлайн — полноценный
 *    калькулятор без единой строчки JS (ADR-062 graceful degradation).
 *  - С JS: `data()` отдаёт тот же expand() как JSON → клиент обновляет панель без
 *    перезагрузки (fetch на изменение выбора/количества).
 *
 * 🔴 Единый источник расчёта — PHP-сервис (нет дублирования логики в JS).
 * Read-only, никаких мутаций; данные — из `Config\CraftRecipes` + таблицы `resources`.
 */
class CraftCalculator extends BaseController
{
    /** Верхняя граница количества (защита от абсурдных значений в URL). */
    private const MAX_QTY = 1000;

    public function index(): string
    {
        $service = new CraftCalculatorService();

        $recipe = $this->request->getGet('recipe');
        $recipe = is_string($recipe) ? trim($recipe) : '';
        $qty    = $this->clampQty($this->request->getGet('qty'));

        $result = $recipe !== '' ? $service->expand($recipe, $qty) : null;

        $canonical = rtrim(base_url('kalkulyator-krafta'), '/');
        $meta = [
            'title'       => 'Калькулятор крафта Wild World: что нужно для крафта',
            'description' => 'Калькулятор крафта Wild World: выбери предмет и количество — покажем полный список сырья, суб-крафты и стоимость в золоте у торговца. Данные из живой игры.',
            'canonical'   => $canonical,
            'ogImage'     => null,
            'robots'      => 'index,follow',
            'ogType'      => 'website',
            'keywords'    => 'wild world калькулятор крафта, что нужно для крафта, рецепты, сырьё, ресурсы, стоимость крафта, текстовая MMORPG, Telegram',
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => rtrim(base_url(), '/') . '/'],
                ['name' => 'Калькулятор крафта', 'url' => $canonical],
            ],
        ];

        return view('site/craft-calculator', [
            'meta'     => $meta,
            'itemList' => $service->itemList(),
            'catalog'  => $service->catalog(),
            'result'   => $result,
            'selected' => $recipe,
            'qty'      => $qty,
            'tgLink'   => (new \Config\Social())->botStart('src_site_craft_calc'),
        ]);
    }

    /** JSON expand() для progressive enhancement (fetch на изменение). */
    public function data(): ResponseInterface
    {
        $service = new CraftCalculatorService();

        $recipe = $this->request->getGet('recipe');
        $recipe = is_string($recipe) ? trim($recipe) : '';
        $qty    = $this->clampQty($this->request->getGet('qty'));

        $payload = $recipe !== ''
            ? $service->expand($recipe, $qty)
            : ['found' => false, 'recipe' => '', 'qty' => $qty];

        return $this->response
            ->setJSON($payload)
            ->setHeader('Cache-Control', 'public, max-age=300');
    }

    private function clampQty(mixed $raw): int
    {
        $qty = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min(self::MAX_QTY, $qty));
    }
}
