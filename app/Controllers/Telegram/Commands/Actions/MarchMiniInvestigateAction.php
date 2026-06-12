<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Notifications\MediaSender;
use App\Services\World\MarchMiniEventService;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Config\GameBalance;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * E17 Ф2 (ADR-117) — «🔍 Осмотреть» мини-событие в Походе (callback `marchMini`).
 *
 * Находит paused-марш (reason=mini_event) персонажа, читает `mini_event_key`, начисляет находку
 * через [[MarchMiniEventService]], затем ВОЗОБНОВЛЯЕТ поход (status=in_work + end_time) — как
 * march_resume. Рендерит результат осмотра. «Пройти мимо» = существующий `march_resume` (без награды).
 */
final class MarchMiniInvestigateAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }
        $rawId  = $character['id'] ?? null;
        $charId = is_numeric($rawId) ? (int) $rawId : 0;

        $task = $this->pausedMarch($charId);
        if ($task === null) {
            return $this->alert('Это мини-событие уже неактуально.');
        }
        $rawTaskId = $task['id'] ?? null;
        $taskId    = is_numeric($rawTaskId) ? (int) $rawTaskId : 0;

        $decoded = json_decode(is_string($task['task_settings'] ?? null) ? $task['task_settings'] : '{}', true);
        $s       = is_array($decoded) ? $decoded : [];
        $key     = is_string($s['mini_event_key'] ?? null) ? $s['mini_event_key'] : '';

        $svc = new MarchMiniEventService();
        if ($key === '' || ! $svc->has($key)) {
            $this->resume($taskId, null);

            return $this->render('🔍 Здесь уже ничего нет. Поход продолжается.');
        }

        $result  = $svc->investigate($key, $charId);
        $awarded = $result['awarded'];

        // Возобновляем поход (как march_resume), очистив ключ мини-события.
        unset($s['mini_event_key']);
        $encoded = json_encode($s);
        $this->resume($taskId, $encoded !== false ? $encoded : null);

        $lines = $this->awardedLines($awarded);
        $head  = "{$result['icon']} *{$result['title']}*";
        $body  = $lines !== ''
            ? "{$head}\n\nТы осматриваешь находку и забираешь:\n{$lines}\n\n_Поход продолжается._"
            : "{$head}\n\nТы осматриваешь, но ценного не осталось.\n\n_Поход продолжается._";

        return $this->render($body);
    }

    /** @return array<int|string,mixed>|null последняя paused Marching-задача персонажа. */
    private function pausedMarch(int $charId): ?array
    {
        $marchTaskId = $this->marchingTaskId();
        if ($marchTaskId <= 0) {
            return null;
        }
        $res = Database::connect()->query(
            "SELECT id, task_settings FROM character_tasks WHERE character_id = ? AND task_id = ? AND status = 'paused' ORDER BY id DESC LIMIT 1",
            [$charId, $marchTaskId]
        );
        if (! $res instanceof BaseResult) {
            return null;
        }
        $row = $res->getResultArray()[0] ?? null;

        return is_array($row) ? $row : null;
    }

    private function marchingTaskId(): int
    {
        $res = Database::connect()->query("SELECT id FROM tasks WHERE name = 'Marching' LIMIT 1");
        if (! $res instanceof BaseResult) {
            return 0;
        }
        $row = $res->getResultArray()[0] ?? null;
        $id  = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

        return $id;
    }

    private function resume(int $taskId, ?string $settings): void
    {
        if ($taskId <= 0) {
            return;
        }
        $cfg   = new GameBalance();
        $start = new \DateTime();
        $end   = (clone $start)->add(new \DateInterval('PT' . max(1, $cfg->marchMinutesPerCell) . 'M'));
        $data  = [
            'status'     => 'in_work',
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time'   => $end->format('Y-m-d H:i:s'),
        ];
        if ($settings !== null) {
            $data['task_settings'] = $settings;
        }
        Database::connect()->table('character_tasks')->where('id', $taskId)->update($data);
    }

    /**
     * @param array<string,int> $awarded
     */
    private function awardedLines(array $awarded): string
    {
        if ($awarded === []) {
            return '';
        }
        $names = array_keys($awarded);
        $map   = [];
        $q     = Database::connect()->table('resources')->whereIn('name_en', $names)->get();
        $rows  = $q instanceof BaseResult ? $q->getResultArray() : [];
        foreach ($rows as $r) {
            $en = isset($r['name_en']) && is_string($r['name_en']) ? $r['name_en'] : null;
            $ru = isset($r['name']) && is_string($r['name']) ? $r['name'] : null;
            if ($en !== null) {
                $map[$en] = $ru !== null && $ru !== '' ? $ru : $en;
            }
        }
        $lines = [];
        foreach ($awarded as $en => $amount) {
            $lines[] = '• ' . ($map[$en] ?? $en) . " ×{$amount}";
        }

        return implode("\n", $lines);
    }

    private function render(string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']],
            ]]),
        ]);
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::emptyResponse();
    }
}
