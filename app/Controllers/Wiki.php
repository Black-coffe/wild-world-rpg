<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WikiContentService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Публичная вики (ADR-052) — разделы генерятся из живых игровых таблиц
 * ({@see WikiContentService}), поэтому всегда актуальны.
 */
class Wiki extends BaseController
{
    public function index(): string
    {
        $wiki = new WikiContentService();

        $site      = rtrim(base_url(), '/');
        $canonical = rtrim(base_url('wiki'), '/');
        $crumbs    = [
            ['name' => 'Главная', 'url' => $site . '/'],
            ['name' => 'Вики', 'url' => $canonical],
        ];

        return view('site/wiki_index', [
            'sections'    => $wiki->sections(),
            'breadcrumbs' => $crumbs,
            'meta'        => [
                'title'       => 'Вики Wild World: биомы, ресурсы, оружие и постройки',
                'description' => 'Биомы, ресурсы, фракции, здания, оружие и броня Wild World. Справочник всегда актуален — данные берутся прямо из игры.',
                'canonical'   => $canonical,
                'ogImage'     => null,
                'robots'      => 'index,follow',
                'ogType'      => 'website',
                'keywords'    => 'Wild World вики, биомы, ресурсы, фракции, здания, оружие, броня, гайд, справочник',
                'breadcrumbs' => $crumbs,
                'jsonld'      => [[
                    '@type'       => 'CollectionPage',
                    'name'        => 'Вики мира Wild World',
                    'url'         => $canonical,
                    'description' => 'Справочник по миру игры Wild World: биомы, ресурсы, фракции, здания, оружие и броня.',
                    'inLanguage'  => 'ru',
                    'isPartOf'    => ['@id' => $site . '/#website'],
                ]],
            ],
        ]);
    }

    public function entry(string $section): string
    {
        $section = mb_strtolower(trim($section));
        $wiki    = new WikiContentService();
        $items   = $wiki->section($section);
        if ($items === null) {
            throw PageNotFoundException::forPageNotFound('Нет такого раздела вики: ' . $section);
        }

        $title = $section;
        foreach ($wiki->sections() as $s) {
            if ($s['key'] === $section) {
                $title = $s['title'];
                break;
            }
        }

        $site      = rtrim(base_url(), '/');
        $canonical = rtrim(base_url('wiki/' . $section), '/');
        $crumbs    = [
            ['name' => 'Главная', 'url' => $site . '/'],
            ['name' => 'Вики', 'url' => rtrim(base_url('wiki'), '/')],
            ['name' => $title, 'url' => $canonical],
        ];

        // ItemList — записи раздела (rich-список).
        $listItems = [];
        $pos       = 1;
        foreach ($items as $it) {
            $nm = is_string($it['name'] ?? null) ? $it['name'] : '';
            if ($nm === '') {
                continue;
            }
            $listItems[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $nm];
        }

        return view('site/wiki_section', [
            'sectionKey'  => $section,
            'heading'     => $title,
            'items'       => $items,
            'breadcrumbs' => $crumbs,
            'meta'        => [
                'title'       => $this->sectionTitle($section, $title),
                'description' => 'Раздел «' . $title . '» — справочник Wild World: ' . $this->plural(count($listItems), 'запись', 'записи', 'записей')
                    . ' с актуальными данными прямо из игры. Списки обновляются автоматически вместе с обновлениями игры.',
                'canonical'   => $canonical,
                'ogImage'     => null,
                'robots'      => 'index,follow',
                'ogType'      => 'website',
                'keywords'    => $title . ', Wild World, вики, справочник, ' . $section,
                'breadcrumbs' => $crumbs,
                'jsonld'      => [[
                    '@type'           => 'ItemList',
                    'name'            => $title . ' — Wild World',
                    'numberOfItems'   => count($listItems),
                    'itemListElement' => $listItems,
                ]],
            ],
        ]);
    }

    /**
     * Русское склонение числительных: 1 запись / 2 записи / 5 записей.
     *
     * SEO-аудит 2026-07-24: описание раздела печатало «(24 записей)» — форма родительного
     * падежа при любом числе. Это видно прямо в сниппете выдачи и читается как небрежность.
     */
    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $n . ' ' . $many;
        }
        if ($mod10 === 1) {
            return $n . ' ' . $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $n . ' ' . $few;
        }

        return $n . ' ' . $many;
    }

    /**
     * Описательный <title> раздела вики.
     *
     * SEO-аудит 2026-07-24: хабовые страницы вики отдавали заголовки вида «Биомы — Вики
     * Wild World» (23 символа). Обрезки нет, но и половина отведённого места в выдаче
     * пустует, а по этим страницам показы есть (`/wiki/biomes` — 26 показов за 3 месяца
     * при позиции 37). Описательный заголовок даёт поисковику слова, по которым страницу
     * вообще можно найти, — сам по себе термин «Биомы» их не несёт.
     *
     * Неизвестный раздел → прежняя схема (новый раздел не останется без заголовка).
     */
    private function sectionTitle(string $section, string $fallback): string
    {
        $map = [
            'biomes'        => 'Биомы Wild World: какие бывают и что в них добывать',
            'resources'     => 'Ресурсы Wild World: где добывать и на что идут',
            'weapons'       => 'Оружие Wild World: список, урон и требования',
            'outfits'       => 'Броня Wild World: список, защита и требования',
            'buildings'     => 'Постройки базы Wild World: что дают и как строить',
            'factions'      => 'Фракции Wild World: чем отличаются и что дают',
            'world-objects' => 'Объекты мира Wild World: что можно найти на карте',
        ];

        return $map[$section] ?? ($fallback . ' — Вики Wild World');
    }
}
