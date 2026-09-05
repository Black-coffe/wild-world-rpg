<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Services\Player\CraftService;
use App\Services\Telegram\KeyboardNormalizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Клавиатура хаба крафта: ни одной кнопки-одиночки в ряду — на ВЫХОДЕ нормализатора.
 *
 * Мерить надо именно выход: сам `hubRows()` сознательно отдаёт «как задумано по
 * смыслу», а хвост из одной кнопки (ремонт без модернизации или наоборот) правит
 * централизованный `KeyboardNormalizer` в точке отправки. Замер исходного массива
 * показал бы нарушение там, где игрок его не увидит — на этом уже один раз
 * споткнулось ревью.
 */
final class CraftHubKeyboardTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function combos(): array
    {
        $out = [];
        foreach ([false, true] as $workbench) {
            foreach ([false, true] as $repair) {
                foreach ([false, true] as $modernization) {
                    $key = sprintf(
                        'цех=%s ремонт=%s модернизация=%s',
                        $workbench ? 'да' : 'нет',
                        $repair ? 'вкл' : 'выкл',
                        $modernization ? 'вкл' : 'выкл',
                    );
                    $out[$key] = [$workbench, $repair, $modernization];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<list<array<string, string>>>
     */
    private function normalizedRows(bool $workbench, bool $repair, bool $modernization): array
    {
        $data = KeyboardNormalizer::normalize([
            'reply_markup' => json_encode([
                'inline_keyboard' => CraftService::hubRows($workbench, $repair, $modernization),
            ]),
        ]);

        $decoded = json_decode((string) $data['reply_markup'], true);

        return $decoded['inline_keyboard'];
    }

    /**
     * @dataProvider combos
     */
    public function testNoLoneButtonReachesThePlayer(bool $workbench, bool $repair, bool $modernization): void
    {
        foreach ($this->normalizedRows($workbench, $repair, $modernization) as $index => $row) {
            $this->assertGreaterThan(
                1,
                count($row),
                sprintf('Ряд #%d уходит игроку с одной кнопкой: %s', $index, implode(', ', array_column($row, 'text'))),
            );
        }
    }

    /**
     * @dataProvider combos
     */
    public function testEveryGatedButtonSurvivesNormalization(bool $workbench, bool $repair, bool $modernization): void
    {
        $labels = [];
        foreach ($this->normalizedRows($workbench, $repair, $modernization) as $row) {
            foreach ($row as $button) {
                $labels[] = $button['text'];
            }
        }

        // Перекладка рядов не имеет права терять кнопки: пропавшая дверь — это
        // невидимая фича, а не косметика раскладки.
        $this->assertContains('🔨 Общий крафт', $labels);
        // Полка уже скрафченного: до 2026-09-05 входа из Крафта не было вовсе,
        // и хинт про транспорт звал в несуществующую дверь.
        $this->assertContains('🔨 Крафтовые ресурсы', $labels);
        $this->assertContains('🔬 Верстаки', $labels);
        $this->assertContains(
            $workbench ? '🛠️ Профессиональный крафт' : '🔒 Проф. крафт',
            $labels,
        );

        $this->assertSame($repair, in_array('🪛 Ремонт инструментов', $labels, true));
        $this->assertSame($modernization, in_array('🔧 Модернизация', $labels, true));
    }
}
