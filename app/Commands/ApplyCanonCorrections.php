<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\SitePostModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Систематические правки импортированного контента под текущий ЛОР (ADR-052 + ADR-010).
 *
 * Версионируется в git и идемпотентна → прогоняется на каждом окружении ПОСЛЕ
 * `site:import-wp` (контент в БД, правки в коде → переносимы local→testbot→prod).
 * Каждая правка scoped по slug (не глобальный replace — «Разбойники»-фракция ≠
 * «шайка разбойников»-NPC). Повторный прогон безопасен (str_replace отсутствующего = no-op).
 *
 *   php spark site:apply-canon            # применить
 *   php spark site:apply-canon --dry-run  # показать что изменится
 */
class ApplyCanonCorrections extends BaseCommand
{
    protected $group       = 'Site';
    protected $name        = 'site:apply-canon';
    protected $description = 'Систематические правки контента сайта под канон (ADR-010): фракция Разбойники→Партизаны и др.';
    protected $usage       = 'site:apply-canon [--dry-run]';
    protected $options     = ['--dry-run' => 'Показать изменения без записи.'];

    /**
     * Падежи фракции: длинные формы ПЕРВЫМИ (str_replace по порядку), иначе
     * «Разбойники» превратится в «Партизани» при раннем «Разбойник»→«Партизан».
     */
    private const FACTION_RENAME = [
        'Разбойниками' => 'Партизанами',
        'Разбойниках'  => 'Партизанах',
        'Разбойникам'  => 'Партизанам',
        'Разбойников'  => 'Партизан',
        'Разбойники'   => 'Партизаны',
        'Разбойника'   => 'Партизана',
        'Разбойнике'   => 'Партизане',
        'Разбойнику'   => 'Партизану',
        'Разбойник'    => 'Партизан',
    ];

    /**
     * @var list<array{slug:string,replace:array<string,string>,mark_reviewed:bool,note:string}>
     */
    private const CORRECTIONS = [
        [
            'slug'          => 'predstavljaem-chetyre-frakcii-v-nashej-igre-wild-world',
            'replace'       => self::FACTION_RENAME,
            'mark_reviewed' => true,
            'note'          => 'Фракция «Разбойники» → канон «Партизаны» (ADR-010)',
        ],
        [
            'slug'          => 'wild-world-masshtabnaya-tekstovaya-mmorpg-s-elementami-pesochnitsy-i-vyzhivaniya',
            'replace'       => self::FACTION_RENAME,
            'mark_reviewed' => false, // большой обзорный пост — систем. фикс, полный ревью отдельно
            'note'          => 'Упоминание фракции «Разбойники» → «Партизаны» в списке фракций',
        ],
    ];

    /** @param array<int|string,string|null> $params */
    public function run(array $params): int
    {
        $dryRun = (bool) CLI::getOption('dry-run');
        $model  = new SitePostModel();
        $applied = 0;
        $marked  = 0;

        foreach (self::CORRECTIONS as $c) {
            $post = $model->where('slug', $c['slug'])->first();
            if (! is_array($post)) {
                CLI::write('  ⚠ пост не найден: ' . $c['slug'], 'red');
                continue;
            }
            $id      = is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;
            $content = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';
            $excerpt = is_string($post['excerpt'] ?? null) ? $post['excerpt'] : '';

            $from        = array_keys($c['replace']);
            $to          = array_values($c['replace']);
            $newContent  = str_replace($from, $to, $content);
            $newExcerpt  = str_replace($from, $to, $excerpt);
            $changed     = $newContent !== $content || $newExcerpt !== $excerpt;

            $alreadyReviewed = (int) (is_numeric($post['canon_reviewed'] ?? null) ? $post['canon_reviewed'] : 0) === 1;
            $willMark        = $c['mark_reviewed'] && ! $alreadyReviewed;

            if (! $changed && ! $willMark) {
                CLI::write('  = без изменений: ' . $c['slug'], 'dark_gray');
                continue;
            }

            CLI::write('  • ' . $c['slug'] . ' — ' . $c['note']
                . ($changed ? ' [текст]' : '') . ($willMark ? ' [canon✓]' : ''), 'green');

            if ($dryRun) {
                continue;
            }

            $data = ['id' => $id, 'content_html' => $newContent, 'excerpt' => $newExcerpt !== '' ? $newExcerpt : null];
            if ($c['mark_reviewed']) {
                $data['canon_reviewed'] = 1;
            }
            if ($model->update($id, $data)) {
                if ($changed) {
                    $applied++;
                }
                if ($willMark) {
                    $marked++;
                }
            } else {
                CLI::error('  ✗ update failed: ' . implode('; ', $model->errors()));
            }
        }

        CLI::write('');
        CLI::write(sprintf('Готово%s. Изменено текстов: %d, отмечено сверёнными: %d.', $dryRun ? ' (dry-run)' : '', $applied, $marked), 'yellow');

        return EXIT_SUCCESS;
    }
}
