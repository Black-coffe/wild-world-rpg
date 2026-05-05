<?php namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\PollModel;
use App\Models\PollAnswerModel;
use App\Models\PollVoteModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;

class PollController extends BaseController
{
    protected $pollModel;
    protected $pollAnswerModel;
    protected $pollVoteModel;

    public function __construct()
    {
        $this->pollModel       = new PollModel();
        $this->pollAnswerModel = new PollAnswerModel();
        $this->pollVoteModel   = new PollVoteModel();
    }

    /**
     * Список опросов.
     */
    public function index()
    {
        helper('text'); // загружаем text helper для character_limiter
        $data = [
            'title' => 'Список опросов',
            'polls' => $this->pollModel->findAll()
        ];
        return view('admin/polls/index', $data);
    }

    /**
     * Форма создания нового опроса.
     */
    public function createPollForm()
    {
        $data = [
            'title' => 'Создать новый опрос'
        ];
        return view('admin/polls/create', $data);
    }

    /**
     * Сохранение нового опроса (минимум 2 варианта ответа).
     */
    public function storePoll()
    {
        $question   = $this->request->getPost('question');
        $answers    = $this->request->getPost('answers');
        $expires_at = $this->request->getPost('expires_at');

        if (empty($question) || empty($answers) || !is_array($answers)) {
            return redirect()->back()->with('error', 'Заполните вопрос и как минимум два варианта ответа.');
        }
        $filtered = array_filter($answers, fn($ans) => trim($ans) !== '');
        if (count($filtered) < 2) {
            return redirect()->back()->with('error', 'Минимум два варианта ответа должны быть заполнены.');
        }

        // F0.8 — санитизация HTML до сохранения. Опрос отправляется в Telegram
        // с parse_mode='HTML', и без фильтрации админ мог бы внедрить
        // <a href="evil.com"> для фишинговой рассылки 635 игрокам.
        // Пропускаем только теги, которые Telegram действительно поддерживает.
        $question = $this->sanitizeTelegramHtml($question);

        // Создаём опрос (active=0 => черновик)
        $pollData = [
            'question'   => $question,
            'active'     => 0,
            'expires_at' => $expires_at ?: null,
        ];
        $pollId = $this->pollModel->insert($pollData);
        if (!$pollId) {
            return redirect()->back()->with('error', 'Ошибка при создании опроса.');
        }

        // Сохраняем варианты
        foreach ($filtered as $answerText) {
            $this->pollAnswerModel->insert([
                'poll_id'     => $pollId,
                'answer_text' => trim($answerText),
            ]);
        }

        return redirect()->to('/admin/polls')->with('message', 'Опрос успешно создан.');
    }

    /**
     * Форма редактирования (доступно, если опрос не отправлен).
     */
    public function editPollForm($id)
    {
        $poll = $this->pollModel->find($id);
        if (!$poll) {
            return redirect()->back()->with('error', 'Опрос не найден.');
        }
        if ($poll['active'] == 1) {
            return redirect()->back()->with('error', 'Редактирование невозможно: опрос уже отправлен.');
        }

        $answers = $this->pollAnswerModel->where('poll_id', $id)->findAll();

        $data = [
            'title'   => 'Редактировать опрос',
            'poll'    => $poll,
            'answers' => $answers
        ];
        return view('admin/polls/edit', $data);
    }

    /**
     * Обновление опроса.
     */
    public function updatePoll($id)
    {
        $poll = $this->pollModel->find($id);
        if (!$poll) {
            return redirect()->back()->with('error', 'Опрос не найден.');
        }
        if ($poll['active'] == 1) {
            return redirect()->back()->with('error', 'Редактирование невозможно: опрос уже отправлен.');
        }

        $question   = $this->request->getPost('question');
        $expires_at = $this->request->getPost('expires_at');

        $existingAnswers = $this->request->getPost('existingAnswers');
        $newAnswers      = $this->request->getPost('newAnswers');

        // F0.8 — санитизация HTML (см. storePoll() выше).
        $question = $this->sanitizeTelegramHtml($question);

        // Обновляем поля опроса
        $this->pollModel->update($id, [
            'question'   => $question,
            'expires_at' => $expires_at ?: null,
        ]);

        // Обработка существующих вариантов
        if (is_array($existingAnswers)) {
            foreach ($existingAnswers as $answerId => $answerText) {
                $answerText = trim($answerText);
                if ($answerText === '') {
                    // Удаляем вариант
                    $this->pollAnswerModel->delete($answerId);
                    continue;
                }
                $this->pollAnswerModel->update($answerId, ['answer_text' => $answerText]);
            }
        }
        // Новые варианты
        if (is_array($newAnswers)) {
            foreach ($newAnswers as $text) {
                $text = trim($text);
                if ($text !== '') {
                    $this->pollAnswerModel->insert([
                        'poll_id'     => $id,
                        'answer_text' => $text,
                    ]);
                }
            }
        }

        return redirect()->to('/admin/polls')->with('message', 'Опрос успешно обновлён.');
    }

    /**
     * Удаление опроса (если не отправлен).
     */
    public function deletePoll($id)
    {
        $poll = $this->pollModel->find($id);
        if (!$poll) {
            return redirect()->back()->with('error', 'Опрос не найден.');
        }
        if ($poll['active'] == 1) {
            return redirect()->back()->with('error', 'Невозможно удалить опрос: он уже отправлен.');
        }

        $this->pollModel->delete($id);
        $this->auditAdminAction('POLL_DELETE', 'poll', (int) $id, ['question' => mb_substr((string) $poll['question'], 0, 200)]);
        return redirect()->to('/admin/polls')->with('message', 'Опрос успешно удалён.');
    }

    /**
     * Отображение статистики голосования.
     */
    public function statistics($id)
    {
        $poll = $this->pollModel->find($id);
        if (!$poll) {
            return redirect()->back()->with('error', 'Опрос не найден.');
        }
        $stats   = $this->pollVoteModel->getStatistics($id);
        $answers = $this->pollAnswerModel->where('poll_id', $id)->findAll();

        $totalVotes = 0;
        foreach ($stats as $s) {
            $totalVotes += $s['votes'];
        }

        $data = [
            'title'      => 'Статистика опроса',
            'poll'       => $poll,
            'answers'    => $answers,
            'stats'      => $stats,
            'totalVotes' => $totalVotes
        ];
        return view('admin/polls/statistics', $data);
    }

    /**
     * Остановка опроса (прекращение приёма голосов).
     */
    public function stopPoll($id)
    {
        $poll = $this->pollModel->find($id);
        if (!$poll) {
            return redirect()->back()->with('error', 'Опрос не найден.');
        }
        $this->pollModel->update($id, ['active' => 0]);
        $this->auditAdminAction('POLL_STOP', 'poll', (int) $id);
        return redirect()->to('/admin/polls')->with('message', 'Опрос остановлен.');
    }

    /**
     * Отправка (запуск) опроса игрокам.
     */
    public function sendPoll($id)
    {
        $poll = $this->pollModel->find($id);
        if (!$poll) {
            return redirect()->back()->with('error', 'Опрос не найден.');
        }
        if ($poll['active'] == 1) {
            return redirect()->back()->with('error', 'Этот опрос уже отправлен.');
        }

        // Получаем варианты ответа
        $answers = $this->pollAnswerModel->where('poll_id', $id)->findAll();
        if (count($answers) < 2) {
            return redirect()->back()->with('error', 'Нужно минимум 2 варианта ответа для отправки опроса.');
        }

        // Переводим опрос в статус "отправлен"
        $this->pollModel->update($id, ['active' => 1]);

        // Рассылка опроса всем пользователям
        $this->sendPollToAllUsers($poll, $answers);

        $this->auditAdminAction('POLL_SEND', 'poll', (int) $id, [
            'question_length' => mb_strlen((string) $poll['question']),
            'answers_count'   => count($answers),
        ]);
        return redirect()->to('/admin/polls')->with('message', 'Опрос запущен и отправлен всем игрокам.');
    }

    /**
     * F1.9 — единая запись destructive админских действий по опросам.
     */
    private function auditAdminAction(string $action, ?string $targetType, ?int $targetId, array $payload = []): void
    {
        $auth = service('auth');
        $adminUserId = is_object($auth) && method_exists($auth, 'user') ? (int) ($auth->user()->id ?? 0) : 0;
        (new AdminAuditLogModel())->record($adminUserId, $action, $targetType, $targetId, $payload);
    }

    /**
     * Логика массовой рассылки опроса всем игрокам через Telegram.
     */
    private function sendPollToAllUsers(array $poll, array $answers)
    {
        // Подключаемся к Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($telegram);

            // Получаем всех Telegram-пользователей
            $telegramUserModel = new TelegramUserModel();
            $users = $telegramUserModel->findAll();

            // Формируем inline-клавиатуру
            $keyboard = [];
            foreach ($answers as $answer) {
                $keyboard[] = [
                    [
                        'text'          => $answer['answer_text'],
                        'callback_data' => "pollVote_{$poll['id']}_{$answer['id']}"
                    ]
                ];
            }

            // Отправляем каждому пользователю
            foreach ($users as $user) {
                $chatId = $user['telegram_id'];
                if (empty($chatId)) {
                    continue;
                }

                // Подготовка данных запроса
                $data = [
                    'chat_id'      => $chatId,
                    'text'         => $poll['question'],
                    'parse_mode'   => 'HTML', // Если возникают проблемы, попробуйте 'Markdown'
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ];

                // Отправка сообщения
                $response = Request::sendMessage($data);

                // Логирование ошибки, если отправка не удалась
                if (!$response->isOk()) {
                    log_message('error', 'Ошибка отправки опроса для чата ' . $chatId . ': ' . $response->getDescription());
                }
            }
        } catch (TelegramException $e) {
            log_message('error', 'Ошибка при отправке опроса: ' . $e->getMessage());
        }
    }

    /**
     * F0.8 — оставляет только теги, которые Telegram parse_mode='HTML'
     * официально поддерживает: b, strong, i, em, u, s, strike, del, a, code, pre.
     * Защищает игроков от фишинговых ссылок, скриптов, iframe и т.п.,
     * если у админа взломан аккаунт или один из админов недобросовестный.
     */
    private function sanitizeTelegramHtml(?string $html): string
    {
        if ($html === null) {
            return '';
        }
        $allowed = '<b><strong><i><em><u><s><strike><del><a><code><pre>';
        return strip_tags($html, $allowed);
    }

}
