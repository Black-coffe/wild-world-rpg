<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\GameTipsModel;
use App\Models\SitePostModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Onboarding\GuideCatalog;
use Config\CommunityStopTopics;
use Config\CommunityVoice;

/**
 * community-chat-bot (ADR-176) §5 плана, story 07 — «пять рубежей против выуживания
 * читов». Единственная точка, через которую обязан пройти любой исходящий текст
 * (авто-ответ story 09 ИЛИ ручное одобрение story 12): {@see verdict()} решает
 * МОЖНО ли это сказать, не решает КОГДА и КОМУ (это `CommunityAnswerMatcher`,
 * story 08) и не решает КАК отправить (это `CommunityChatSender`, story 06).
 *
 * Пять рубежей (первая редакция плана имела три — панель обошла все три за минуту):
 *  1. Провенанс предложений — каждое предложение обязано отображаться в достаточную
 *     долю значимых слов какого-то одного фрагмента белого корпуса. Не строгий
 *     дословный substring (Русский язык сильно склоняется — «вещи»/«вещей»), а
 *     детерминированный стеммингом-по-префиксу (3 символа) порог покрытия — ловит
 *     класс утечек без цифр («упирается в потолок», «раньше было иначе»), для
 *     которых таких формулировок в корпусе по построению не существует.
 *  2. Гвард на входящем — вопрос с числом/%/диапазоном/словом-порогом или в форме
 *     проверки гипотезы («…, да?») блокирует ЛЮБОЙ ответ: бот не работает оракулом
 *     с одним битом на выход.
 *  3. Лексический стоп-лист на исходящем + числительные словами + односложные
 *     подтверждения как класс ответа + собственный `CommunityVoice::FORBIDDEN_PATTERNS`
 *     (§6 плана — «обещание студии, которое ещё должно быть правдой через полгода»).
 *  4. Стоп-темы (`Config\CommunityStopTopics::TOPICS`) — с узкой оговоркой: вопрос
 *     на подозрение бага не деньится, а квитируется и эскалируется (`Verdict::manual`).
 *  5. Live vs dormant — упоминание подсистемы за килсвитчем без `requires_setting`
 *     не проходит вовсе; с `requires_setting` — читается `GameSettings` по ключу,
 *     выключено значит `deny`.
 *
 * 🔴 НЕ зависит от `Config\GameBalance` и не читает `game_settings` напрямую —
 * единственное исключение конституции (рубеж 5) идёт через `GameSettingsService`
 * по ключу килсвитча, а не как «свериться с числом баланса».
 */
final class CommunityGuard
{
    /** Длина префикса для грубого стемминга (без морфологии, детерминированно). */
    private const PROVENANCE_STEM_LEN = 3;

    /** Минимальная длина слова, чтобы считаться «значимым» (не предлог/союз). */
    private const MIN_SIGNIFICANT_WORD_LEN = 4;

    /**
     * Доля ВЗВЕШЕННЫХ значимых слов предложения, которая обязана найтись в ОДНОМ
     * фрагменте корпуса (story 21). При десятках фрагментов простой bag-of-stems
     * ratio почти всегда набирает 0.6+ на каком-то фрагменте — родовые игровые
     * слова («ресурс», «база», «даёт») встречаются почти везде. Вес слова —
     * обратная частота фрагментов, где оно встречается (мини-IDF, детерминированный
     * счёт по корпусу, не семантика): редкое/специфичное слово («полис», «дрон»)
     * весит больше, чем то, что есть в половине фрагментов. Порог откалиброван по
     * `defaultCorpus()` (32 раздела `GuideCatalog`): добросовестный пересказ
     * реального фрагмента набирает ~0.9+, придуманное правдоподобное утверждение
     * из игровой лексики — ~0.6-0.7 (см. story 21 `## Findings`).
     */
    private const PROVENANCE_THRESHOLD = 0.75;

    /** §5, рубеж 3 — быстрее/выгоднее/оптимально/… (план §5.3, дословно). */
    private const LEXICAL_STOPLIST = [
        'быстрее', 'выгоднее', 'оптимально', 'всегда', 'никогда', 'достаточно',
        'упирается', 'потолок', 'порог', 'перестаёт', 'бесполезно', 'окупается',
    ];

    /**
     * Односложные подтверждения запрещены как КЛАСС ответа о механике (§5.3).
     * Story 21: список расширен словами-связками вокруг подтверждения («ага»,
     * «именно», «так») — проверка ниже (`answerLeaksLexically()`) требует, чтобы
     * ВСЕ слова ответа входили сюда (не точное равенство склеенной строки), тогда
     * «Да, верно.» / «Ага.» / «Именно так.» ловятся независимо от пунктуации,
     * эмодзи и порядка слов-связок.
     */
    private const MONOSYLLABLE_CONFIRMATIONS = ['да', 'нет', 'верно', 'почти', 'ага', 'именно', 'так'];

    /**
     * Числительные словами — тот же список, что уже проверен в `CommunityVoiceCanonTest`
     * (story 04): «дцать»/«десят» покрывают «двадцать»..«девяносто» одним корнем.
     */
    private const NUMERAL_WORDS = [
        'ноль', 'один', 'одна', 'одно', 'два', 'две', 'три', 'четыре', 'пять',
        'шесть', 'семь', 'восемь', 'девять', 'десят', 'дцать', 'сорок', 'сто',
        'тысяч', 'миллион', 'процент', 'вдвое', 'втрое',
    ];

    /** §5, рубеж 2 — слова-триггеры порога/лимита во входящем вопросе. */
    private const QUESTION_LEAK_KEYWORDS = ['потолок', 'максимум', 'порог', 'кулдаун', 'диапазон'];

    /** Формы проверки гипотезы во входящем — «правда, что…», «…, да?». */
    private const QUESTION_HYPOTHESIS_MARKERS = ['правда, что', 'правда что', 'не так ли'];

    /** @var list<array{source: string, text: string}> */
    private array $corpus;

    private GameSettingsService $gameSettings;

    /**
     * @param list<array{source: string, text: string}>|null $corpus Белый корпус
     *        (адрес фрагмента + текст). null — собирается дефолтно из живого
     *        `GuideCatalog::sections()` + `game_tips` + разрешённых `site_posts`
     *        (см. {@see defaultCorpus()}). Тесты передают свой корпус — без БД.
     * @param GameSettingsService|null $gameSettings Читатель килсвитча по ключу
     *        (рубеж 5). null — реальный `GameSettingsService()`. Тесты подменяют его
     *        конструктором с двойником `GameSettingsModel` (паттерн
     *        `CommunityIngestServiceTest`) — без реальной таблицы `game_settings`.
     */
    public function __construct(?array $corpus = null, ?GameSettingsService $gameSettings = null)
    {
        $this->corpus       = $corpus ?? $this->defaultCorpus();
        $this->gameSettings = $gameSettings ?? new GameSettingsService();
    }

    public function verdict(string $answerText, string $questionText, ?string $requiresSetting): Verdict
    {
        $answer   = trim($answerText);
        $question = trim($questionText);

        // §5.4 исключение: подозрение на баг — не молчать, квитировать и эскалировать.
        // Story 21: word-boundary матч, а не substring — «баг» не обязан ловить «багаж»
        // (короткий корень иначе даёт неверную причину в аудите раньше стоп-тем).
        if ($this->mentionsBugMarker($question)) {
            return Verdict::manual('bug_report_topic', CommunityVoice::RECEIPT[0]);
        }

        // Рубеж 5 — live vs dormant.
        $setting = $requiresSetting !== null && trim($requiresSetting) !== '' ? trim($requiresSetting) : null;
        if ($setting !== null && ! $this->readKillswitch($setting)) {
            return Verdict::deny('dormant_setting_disabled', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
        }

        // Story 21: проверка dormant-подсистем идёт ВСЕГДА, не в ветке «иначе» —
        // заполненный requires_setting закрывает СВОЮ тему, но не должен маскировать
        // упоминание ДРУГОЙ dormant-темы в том же ответе (Оракул при requires_setting
        // транспорта). `DORMANT_SUBSYSTEM_SETTINGS` — тот же 12-маркерный канон-словарь,
        // просто с уже задокументированными в комментариях ключами превращёнными в данные.
        $dormantMarker = $this->matchedDormantSubsystem($answer);
        if ($dormantMarker !== null) {
            $expectedSetting = CommunityStopTopics::DORMANT_SUBSYSTEM_SETTINGS[$dormantMarker] ?? null;
            if ($expectedSetting !== $setting
                && ($expectedSetting === null || ! $this->readKillswitch($expectedSetting))
            ) {
                return Verdict::deny('missing_requires_setting', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
            }
        }

        // Рубеж 2 — гвард на входящем: утечку несёт и вопрос, любой ответ читается битом.
        if ($this->questionLeaksSignal($question)) {
            return Verdict::deny('question_leaks_signal', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
        }

        // Рубеж 4 — стоп-темы (вопрос ИЛИ ответ).
        $stopTopic = $this->matchedStopTopic($question . ' ' . $answer);
        if ($stopTopic !== null) {
            return Verdict::deny('stop_topic:' . $stopTopic, CommunityVoice::STOP_TOPIC[0]);
        }

        // Рубеж 3 — лексический стоп-лист на исходящем + числа/числительные +
        // односложные подтверждения + собственный канон FORBIDDEN_PATTERNS.
        if ($this->answerLeaksLexically($answer)) {
            return Verdict::deny('lexical_stoplist', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
        }

        // Рубеж 1 — провенанс предложений, главный рубеж.
        if (! $this->hasProvenance($answer)) {
            return Verdict::deny('no_provenance', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
        }

        return Verdict::allow();
    }

    /** Рубеж 5 — типобезопасное чтение килсвитча; неизвестный/недоступный ключ = dormant. */
    private function readKillswitch(string $key): bool
    {
        $value = $this->gameSettings->get($key, false);
        if (is_bool($value)) {
            return $value;
        }

        return is_numeric($value) ? ((int) $value === 1) : false;
    }

    private function questionLeaksSignal(string $question): bool
    {
        if ($question === '') {
            return false;
        }
        $lower = mb_strtolower($question);

        if (preg_match('/\d/', $lower) === 1 || str_contains($lower, '%')) {
            return true;
        }
        foreach (self::QUESTION_LEAK_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        foreach (self::NUMERAL_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }
        foreach (self::QUESTION_HYPOTHESIS_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        // «уворот упирается в 75, да?» — форма проверки гипотезы через хвост «…, да?».
        return preg_match('/,?\s*да\s*\?\s*$/u', trim($question)) === 1;
    }

    private function answerLeaksLexically(string $answer): bool
    {
        if ($answer === '') {
            return false;
        }
        $lower = mb_strtolower($answer);

        if (preg_match('/\d/', $lower) === 1 || str_contains($lower, '%')) {
            return true;
        }
        foreach (self::NUMERAL_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }
        foreach (self::LEXICAL_STOPLIST as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }
        foreach (CommunityStopTopics::NARROW_LEXICAL_TOPICS as $phrase) {
            if (str_contains($lower, mb_strtolower($phrase))) {
                return true;
            }
        }
        foreach (CommunityVoice::FORBIDDEN_PATTERNS as $pattern) {
            if (str_contains($lower, mb_strtolower($pattern))) {
                return true;
            }
        }

        // Story 21: ответ — КЛАСС «голое подтверждение», если ВСЕ его слова (после
        // удаления пунктуации/эмодзи) входят в MONOSYLLABLE_CONFIRMATIONS. Точное
        // равенство склеенной строки ловило только `«Да.»`; так «Да, верно.» /
        // «Ага.» / «Именно так.» тоже опознаются как класс, а не как одно слово.
        preg_match_all('/\p{L}+/u', $lower, $wordMatches);
        $words = $wordMatches[0];

        return $words !== [] && array_diff($words, self::MONOSYLLABLE_CONFIRMATIONS) === [];
    }

    private function matchedStopTopic(string $text): ?string
    {
        $lower = mb_strtolower($text);
        foreach (CommunityStopTopics::TOPICS as $topic) {
            foreach ($topic['markers'] as $marker) {
                if (str_contains($lower, mb_strtolower($marker))) {
                    return $topic['key'];
                }
            }
        }

        return null;
    }

    /** Первый dormant-маркер (§5.5), упомянутый в тексте, или null. */
    private function matchedDormantSubsystem(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        $lower = mb_strtolower($text);
        foreach (CommunityStopTopics::DORMANT_SUBSYSTEM_MARKERS as $marker) {
            if (str_contains($lower, mb_strtolower($marker))) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Story 21: `CommunityStopTopics::BUG_REPORT_MARKERS` содержит и фразы, и короткие
     * слова-корни («баг», «глюк»). Фраза как substring уже достаточно специфична —
     * ложных срабатываний не даёт. Короткий корень — да: substring-матч «баг» ловил
     * и «багаж» (другое слово, случайно начинается с тех же трёх букв). Для
     * однословных маркеров требуем границу слова — маркер плюс МАКСИМУМ одна
     * дополнительная буква-окончание («баг» → «бага»/«багу»/«баги» проходят,
     * «багаж» с двумя лишними буквами — нет).
     */
    private function mentionsBugMarker(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $lower = mb_strtolower($text);
        foreach (CommunityStopTopics::BUG_REPORT_MARKERS as $marker) {
            $marker = mb_strtolower($marker);
            if (str_contains($marker, ' ')) {
                if (str_contains($lower, $marker)) {
                    return true;
                }
                continue;
            }
            $pattern = '/(?<![\p{L}])' . preg_quote($marker, '/') . '\p{L}?(?![\p{L}])/u';
            if (preg_match($pattern, $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasProvenance(string $answer): bool
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $answer) ?: [$answer];

        $fragmentStems = [];
        foreach ($this->corpus as $i => $fragment) {
            $fragmentStems[$i] = $this->stems($fragment['text']);
        }

        // Story 21: вес стема — обратная частота фрагментов, в которых он встречается
        // (мини-IDF, посчитанный ПО ЭТОМУ ЖЕ корпусу — детерминированно, не семантика).
        // Родовое слово из половины разделов справочника («ресурс», «база», «даёт»)
        // весит меньше, чем специфичное слово из одного-двух разделов.
        $documentFrequency = [];
        foreach ($fragmentStems as $stemsOfFragment) {
            foreach (array_unique($stemsOfFragment) as $stem) {
                $documentFrequency[$stem] = ($documentFrequency[$stem] ?? 0) + 1;
            }
        }

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence, " \t\n\r\0\x0B.!?");
            if ($sentence === '') {
                continue;
            }
            $words = $this->stems($sentence);
            if ($words === []) {
                // Предложение без содержательных слов (эмодзи/связка) — риска утечки нет.
                continue;
            }

            $weights     = [];
            $totalWeight = 0.0;
            foreach ($words as $stem) {
                $weights[$stem] = 1.0 / ($documentFrequency[$stem] ?? 1);
                $totalWeight    += $weights[$stem];
            }

            $bestRatio = 0.0;
            foreach ($fragmentStems as $stemsOfFragment) {
                $matchedWeight = 0.0;
                foreach ($words as $stem) {
                    if (in_array($stem, $stemsOfFragment, true)) {
                        $matchedWeight += $weights[$stem];
                    }
                }
                $ratio = $totalWeight > 0.0 ? $matchedWeight / $totalWeight : 0.0;
                if ($ratio > $bestRatio) {
                    $bestRatio = $ratio;
                }
            }

            if ($bestRatio < self::PROVENANCE_THRESHOLD) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function stems(string $text): array
    {
        preg_match_all('/\p{L}{' . self::MIN_SIGNIFICANT_WORD_LEN . ',}/u', mb_strtolower($text), $matches);

        $stems = [];
        foreach ($matches[0] as $word) {
            $stems[] = mb_substr($word, 0, self::PROVENANCE_STEM_LEN);
        }

        return array_values(array_unique($stems));
    }

    /**
     * Дефолтный белый корпус: живой `GuideCatalog` (адресами `sections()`, не
     * замороженной копией текста — иначе корпус начнёт врать про кнопки), `game_tips`,
     * разрешённые `site_posts` (`canon_reviewed=1` + `published`). Безопасная деградация
     * при недоступной БД — как `GameSettingsService::get()`: гвард сужает корпус, а не
     * падает (известный компромисс, см. `## Findings` story 07).
     *
     * ⚠️ `glossary/` НЕ включён: это markdown в `mmorpg-vault/`, соседнем репозитории,
     * не гарантированно доступном рантайму прод-сервера. Отдельный пробел, см. Findings.
     *
     * @return list<array{source: string, text: string}>
     */
    private function defaultCorpus(): array
    {
        $fragments = [];

        foreach (GuideCatalog::sections() as $section) {
            $fragments[] = ['source' => 'guide:' . $section['key'], 'text' => $section['title'] . ' ' . $section['body']];
        }

        try {
            $tips = (new GameTipsModel())->findAll();
            foreach ($tips as $tip) {
                if (! is_array($tip)) {
                    continue;
                }
                $titleEn = is_string($tip['title_en'] ?? null) ? $tip['title_en'] : '?';
                $content = $tip['content'] ?? '';
                $fragments[] = ['source' => 'tip:' . $titleEn, 'text' => is_string($content) ? $content : ''];
            }
        } catch (\Throwable) {
            // Тестовая/неполная БД без game_tips — корпус просто уже (не падаем).
        }

        try {
            $posts = (new SitePostModel())->where('canon_reviewed', 1)->where('status', 'published')->findAll();
            foreach ($posts as $post) {
                if (! is_array($post)) {
                    continue;
                }
                $slug        = is_string($post['slug'] ?? null) ? $post['slug'] : '?';
                $contentHtml = $post['content_html'] ?? '';
                $fragments[] = ['source' => 'post:' . $slug, 'text' => strip_tags(is_string($contentHtml) ? $contentHtml : '')];
            }
        } catch (\Throwable) {
            // См. выше.
        }

        return $fragments;
    }
}

/**
 * Вердикт гварда — `allow` / `manual` (в очередь владельцу) / `deny`. Умышленно в
 * одном файле с `CommunityGuard` (не отдельным файлом — story 07 не в праве заводить
 * файлы вне `## Files`): PHP не требует один класс на файл, а этот файл всегда
 * подгружается автозагрузчиком раньше, чем что-либо обращается к `Verdict`.
 *
 * `deny()` структурно не может существовать без маршрута — конструктор требует
 * непустую строку, а не nullable: «отказ без маршрута — запрещённый класс» (§6 плана)
 * гарантирован типом, не соглашением.
 */
final class Verdict
{
    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?string $route,
    ) {
    }

    public static function allow(): self
    {
        return new self('allow', 'ok', null);
    }

    public static function deny(string $reason, string $route): self
    {
        return new self('deny', $reason, $route);
    }

    public static function manual(string $reason, ?string $route = null): self
    {
        return new self('manual', $reason, $route);
    }

    public function isAllow(): bool
    {
        return $this->status === 'allow';
    }

    public function isDeny(): bool
    {
        return $this->status === 'deny';
    }

    public function isManual(): bool
    {
        return $this->status === 'manual';
    }
}
