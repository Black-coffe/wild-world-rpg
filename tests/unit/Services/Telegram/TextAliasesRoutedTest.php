<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Text-алиасы навигации (2026-07-10) — анти-мёртвое-слово.
 *
 * Источник списка — не фантазия, а аудит прод-firehose (ADR-148) за 90 дней:
 * `SELECT raw_input FROM player_action_log WHERE status='unrouted' AND source='text'`.
 * Игроки НАБИРАЮТ то, что обучающие тексты (ADR-103) называют кнопкой — «двигаться»,
 * «как двигаться?», «вверх», «квест» — и получали «Не понял команду».
 *
 * Роутер текста — плоский `switch` в GenericmessageCommand, у reply-подписей нет таблицы
 * роутов (урок `FinalGridLabelsRoutedTest`). Значит забытый `case` = слово, которое снова
 * молча уходит в fallback. Тест сканирует исходник: каждый алиас обязан иметь `case`.
 *
 * Регистр: роутер лоуэркейсит вход (`mb_strtolower`), поэтому и `case` — в нижнем.
 *
 * @internal
 */
final class TextAliasesRoutedTest extends CIUnitTestCase
{
    /** Слова → куда ведут. Ключ = алиас, значение = смысл (для сообщения об ошибке). */
    private const ALIASES = [
        // Движение: прямое эхо обучающих текстов, ведёт на компас ходьбы.
        'двигаться'       => 'компас ходьбы',
        'двигатся'        => 'компас ходьбы (частая опечатка)',
        'идти'            => 'компас ходьбы',
        'иду'             => 'компас ходьбы',
        'ходить'          => 'компас ходьбы',
        'как двигаться'   => 'компас ходьбы',
        'как двигаться?'  => 'компас ходьбы (реальный вопрос игрока из firehose)',
        'вверх'           => 'компас ходьбы (направление)',
        'вниз'            => 'компас ходьбы (направление)',
        'влево'           => 'компас ходьбы (направление)',
        'вправо'          => 'компас ходьбы (направление)',
        'map'             => 'карта латиницей (зеркало settings)',
        // Цели: хаб «Дела» — дом квестов и заданий дня.
        'квест'           => 'хаб «Дела»',
        'квесты'          => 'хаб «Дела»',
        'задание'         => 'хаб «Дела»',
        'задания'         => 'хаб «Дела»',
        // Топ игроков: слова, ради которых экран и построен (двое спрашивали в firehose).
        'топ'                  => 'экран «Топ игроков»',
        'рейтинг'              => 'экран «Топ игроков»',
        'топ игроков'          => 'экран «Топ игроков»',
        'тут есть топ игроков?' => 'экран «Топ игроков» (дословный вопрос игрока)',
    ];

    private function routerSource(): string
    {
        return (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php'
        );
    }

    public function testEveryAliasHasCaseInRouter(): void
    {
        $src = $this->routerSource();

        foreach (self::ALIASES as $alias => $target) {
            $this->assertStringContainsString(
                "case '" . $alias . "':",
                $src,
                "Алиас «{$alias}» ({$target}) не имеет case в GenericmessageCommand — "
                . 'слово снова уйдёт в «Не понял команду».'
            );
        }
    }

    public function testAliasesAreLowercaseSoRouterMatchesThem(): void
    {
        foreach (array_keys(self::ALIASES) as $alias) {
            $this->assertSame(
                mb_strtolower($alias),
                $alias,
                "Алиас «{$alias}» не в нижнем регистре — роутер лоуэркейсит вход и никогда его не поймает."
            );
        }
    }

    /**
     * Анти-регрессия: алиасы движения обязаны уважать killswitch world_hub — то есть
     * роутиться той же веткой, что и «карта» (ON → компас, OFF → фото-карта), а не
     * звать handleWorld напрямую. Иначе при OFF слово вело бы в экран, которого нет в UI.
     */
    public function testMovementAliasesShareTheGuardedMapBranch(): void
    {
        $src = $this->routerSource();

        $start = mb_strpos($src, "case 'карта':");
        $this->assertIsInt($start, 'Ветка «карта» исчезла — алиасы движения осиротели.');

        $branchEnd = mb_strpos($src, 'return $mapResponse;', $start);
        $this->assertIsInt($branchEnd, 'Ветка «карта» не возвращает $mapResponse.');

        $branch = mb_substr($src, $start, $branchEnd - $start);

        foreach (['двигаться', 'вверх', 'map', 'идти'] as $alias) {
            $this->assertStringContainsString(
                "case '" . $alias . "':",
                $branch,
                "Алиас «{$alias}» обязан жить в guarded-ветке «карта» (worldHubEnabled), а не отдельно."
            );
        }

        $this->assertStringContainsString(
            'worldHubEnabled()',
            $branch,
            'Ветка алиасов движения потеряла killswitch-гвард world_hub.'
        );
    }

    /**
     * Квест-алиасы обязаны идти в ветку «Дела» — там `handleTasks` сам отдаёт fallback
     * при tasks_hub OFF, значит мёртвого слова не возникает ни при каком флаге.
     */
    public function testQuestAliasesShareTasksBranch(): void
    {
        $src = $this->routerSource();

        $start = mb_strpos($src, "case '📋 дела':");
        $this->assertIsInt($start, 'Ветка «Дела» исчезла — квест-алиасы осиротели.');

        $branchEnd = mb_strpos($src, 'return $tasksResponse;', $start);
        $this->assertIsInt($branchEnd, 'Ветка «Дела» не возвращает $tasksResponse.');

        $branch = mb_substr($src, $start, $branchEnd - $start);

        foreach (['квест', 'квесты', 'задание', 'задания'] as $alias) {
            $this->assertStringContainsString(
                "case '" . $alias . "':",
                $branch,
                "Алиас «{$alias}» обязан вести в хаб «Дела»."
            );
        }
    }
}
