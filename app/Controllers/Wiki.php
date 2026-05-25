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
                'title'       => 'Вики мира — Wild World',
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
                'title'       => $title . ' — Вики Wild World',
                'description' => 'Раздел «' . $title . '» — справочник Wild World: актуальные данные прямо из игры (' . count($listItems) . ' записей).',
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
}
