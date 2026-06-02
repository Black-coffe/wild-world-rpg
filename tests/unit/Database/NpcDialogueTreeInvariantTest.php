<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-089 Phase 6 — анти-дрифт гейт инварианта диалоговых деревьев.
 *
 * Требование владельца (2026-06-02): «ни одного узла с единственным вариантом —
 * минимум 2 реплики в каждом, до конца дерева». Тест статически парсит JSON
 * миграции глубоких деревьев и проверяет ВСЕ узлы: >=2 опции, валидный next,
 * ключ только a-z (без '_'), наличие root. Падение = блок deploy.
 */
final class NpcDialogueTreeInvariantTest extends CIUnitTestCase
{
    private const SENTINELS = ['end', 'root', 'act-quest', 'act-rob', 'act-fight', 'act-trade', 'act-leave', 'act-provoke'];

    public function testDeepTreesSatisfyTwoOptionInvariant(): void
    {
        $file = APPPATH . 'Database/Migrations/2026-06-21-130000_DeepDialogueTreesAllNeutrals.php';
        $this->assertFileExists($file);
        $src = file_get_contents($file);
        $this->assertIsString($src);

        $this->assertSame(1, preg_match("/<<<'JSON'\n(.*)\nJSON;/s", $src, $m), 'не найден встроенный JSON деревьев');
        $trees = json_decode($m[1] ?? '', true);
        $this->assertIsArray($trees, 'JSON деревьев должен декодироваться');
        $this->assertNotEmpty($trees);

        $total = 0;
        foreach ($trees as $nameEn => $nodes) {
            $name = is_string($nameEn) ? $nameEn : (string) $nameEn;
            $this->assertIsArray($nodes, "{$name}: nodes — массив");

            // собрать множество ключей (для проверки резолва next)
            $keys = [];
            foreach ($nodes as $n) {
                if (is_array($n) && isset($n['key']) && is_string($n['key'])) {
                    $keys[] = $n['key'];
                }
            }
            $this->assertContains('root', $keys, "{$name}: обязателен узел 'root'");

            foreach ($nodes as $n) {
                $this->assertIsArray($n, "{$name}: узел — массив");
                $total++;
                $key = (isset($n['key']) && is_string($n['key'])) ? $n['key'] : '';
                $this->assertMatchesRegularExpression('/^[a-z]+$/', $key, "{$name}: node_key '{$key}' только строчные a-z (без _ и -)");

                $opts = (isset($n['options']) && is_array($n['options'])) ? $n['options'] : [];
                $this->assertGreaterThanOrEqual(
                    2,
                    count($opts),
                    "{$name} узел '{$key}': требуется >=2 реплики игрока (инвариант владельца)"
                );

                foreach ($opts as $o) {
                    $this->assertIsArray($o, "{$name} узел '{$key}': опция — массив");
                    $nx = (isset($o['next']) && is_string($o['next'])) ? $o['next'] : '';
                    $this->assertTrue(
                        in_array($nx, $keys, true) || in_array($nx, self::SENTINELS, true),
                        "{$name} узел '{$key}': next '{$nx}' не резолвится (ни узел, ни служебный)"
                    );
                    $gate = (isset($o['gate']) && is_string($o['gate'])) ? $o['gate'] : '';
                    if ($gate !== '') {
                        $this->assertContains($gate, ['wary', 'neutral', 'acquaintance', 'ally'], "{$name} узел '{$key}': плохой gate '{$gate}'");
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(200, $total, 'ожидаются глубокие деревья (>=200 узлов суммарно)');
    }
}
