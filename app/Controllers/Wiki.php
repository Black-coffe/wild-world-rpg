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

        return view('site/wiki_index', [
            'sections' => $wiki->sections(),
            'meta'     => [
                'title'       => 'Вики мира — Wild World',
                'description' => 'Биомы, ресурсы, фракции, здания, оружие и броня Wild World. Справочник всегда актуален — данные берутся прямо из игры.',
                'canonical'   => rtrim(base_url('wiki'), '/'),
                'ogImage'     => null,
                'robots'      => 'index,follow',
                'ogType'      => 'website',
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

        return view('site/wiki_section', [
            'sectionKey'   => $section,
            'heading'      => $title,
            'items'        => $items,
            'meta'         => [
                'title'       => $title . ' — Вики Wild World',
                'description' => 'Раздел «' . $title . '» — справочник Wild World, актуальные данные прямо из игры.',
                'canonical'   => rtrim(base_url('wiki/' . $section), '/'),
                'ogImage'     => null,
                'robots'      => 'index,follow',
                'ogType'      => 'website',
            ],
        ]);
    }
}
