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
     * фрагменте корпуса — recall-пол, не главный фильтр (story 30 понизила порог
     * с 0.75: на 32 фрагментах разной длины он сам по себе не разделял классы,
     * см. `RECOMBINATION_THRESHOLD` ниже и `## Findings` story 30). Вес слова —
     * обратная частота фрагментов, где оно встречается (мини-IDF, детерминированный
     * счёт по корпусу, не семантика): редкое/специфичное слово («полис», «дрон»)
     * весит больше, чем то, что есть в половине фрагментов.
     */
    private const PROVENANCE_THRESHOLD = 0.50;

    /**
     * Story 30 — рубеж анти-рекомбинации, второй обязательный признак рядом с
     * `PROVENANCE_THRESHOLD`. Ревью замерило: длинный фрагмент (например «Торговец»,
     * 190+ уникальных стемов) случайно набирает высокий ratio по ВСЕМУ своему тексту
     * даже когда слова предложения в нём нигде не стоят рядом — «Если ходить в поход
     * голодным, добыча падает.» получала 0.886 против фрагмента про торговлю, где
     * ни разу не встречается ни «поход», ни «голод» рядом с «добычей». Признак —
     * то же взвешенное покрытие, но не по всему документу, а по ЛУЧШЕМУ скользящему
     * окну фиксированного размера (`RECOMBINATION_WINDOW_WORDS`) внутри ЛУЧШЕГО по
     * `PROVENANCE_THRESHOLD` фрагмента: у добросовестного пересказа слова claim'а
     * стоят рядом в источнике (какое-то окно их удерживает почти целиком), у
     * рекомбинации — разбросаны по всему документу, и ни одно окно фиксированного
     * размера высокого покрытия не даёт. Калибровка на 24 добросовестных + 24
     * фабрикатах — `## Findings` story 30.
     */
    private const RECOMBINATION_THRESHOLD = 0.55;

    /**
     * Story 30 — размер скользящего окна (в значимых словах фрагмента). Маленькое
     * окно нарочно: калибровка показала, что более широкое окно (16+) уравнивает
     * «Редкие ресурсы падают чаще, если идти в поход без брони.» (фабрикат) с
     * добросовестными ответами того же порядка длины — окно шире разбросанной
     * фабрикации начинает ту же ошибку длинного документа, только в миниатюре.
     */
    private const RECOMBINATION_WINDOW_WORDS = 8;

    /** Story 30 — шаг скольжения окна; меньше — точнее, дороже. */
    private const RECOMBINATION_WINDOW_STRIDE = 2;

    /**
     * Story 30 — обход анти-рекомбинации для ПОЧТИ ДОСЛОВНОГО совпадения с одним
     * фрагментом целиком. При `RECOMBINATION_WINDOW_WORDS = 8` часть добросовестных
     * пересказов, которые связно объединяют мысль из НЕСКОЛЬКИХ соседних предложений
     * одного фрагмента (напр. «Смерть забирает часть ресурсов… а под своей базой
     * потери меньше, чем у бездомного.» — doc-ratio 0.933, ужимает две фразы раздела
     * «Что забирает смерть» в одну), не укладываются ни в одно 8-словное окно — но
     * их ОБЩИЙ ratio настолько высок, что рекомбинация уже маловероятна: собрать
     * фальшивку, покрывающую 90%+ ВЗВЕШЕННОЙ лексики единственного документа, кейсы
     * калибровки не смогли (максимум фабриката — 0.886, см. `## Findings`).
     */
    private const RECOMBINATION_BYPASS_THRESHOLD = 0.90;

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
        //
        // Story 30: проверяются ВСЕ упомянутые маркеры, а не только первый по порядку
        // константы — `matchedDormantSubsystem()` (единственное число) возвращал первый
        // найденный, и «Оракул работает, а караваны ходят» проходило, если «оракул»
        // просто стоял в списке раньше «карава» (перевёрнутая пара: первый маркер жив,
        // второй выключен — второй маркер до проверки не доходил вовсе).
        foreach ($this->matchedDormantSubsystems($answer) as $dormantMarker) {
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
        // Story 30: обязана быть граница слова ПЕРЕД «да» — без неё хвост ловил и
        // «Где вода?» / «Роби, где взять еда?» (буквы «да» на конце обычного слова).
        return preg_match('/(?<![\p{L}])да\s*\?\s*$/u', trim($question)) === 1;
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

    /**
     * Story 30: ВСЕ dormant-маркеры (§5.5), упомянутые в тексте, не только первый —
     * см. комментарий на месте вызова.
     *
     * @return list<string>
     */
    private function matchedDormantSubsystems(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lower   = mb_strtolower($text);
        $matches = [];
        foreach (CommunityStopTopics::DORMANT_SUBSYSTEM_MARKERS as $marker) {
            if (str_contains($lower, mb_strtolower($marker))) {
                $matches[] = $marker;
            }
        }

        return $matches;
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

        // Story 30: и bag-of-stems (порядок неважен, для ratio), и ordered (порядок
        // сохранён, для скользящего окна анти-рекомбинации) — считаются один раз на
        // весь корпус, не на каждое предложение.
        $fragmentStems        = [];
        $fragmentOrderedStems = [];
        foreach ($this->corpus as $i => $fragment) {
            $fragmentOrderedStems[$i] = $this->stemsOrdered($fragment['text']);
            $fragmentStems[$i]        = array_values(array_unique($fragmentOrderedStems[$i]));
        }

        // Story 21: вес стема — обратная частота фрагментов, в которых он встречается
        // (мини-IDF, посчитанный ПО ЭТОМУ ЖЕ корпусу — детерминированно, не семантика).
        // Родовое слово из половины разделов справочника («ресурс», «база», «даёт»)
        // весит меньше, чем специфичное слово из одного-двух разделов.
        $documentFrequency = [];
        foreach ($fragmentStems as $stemsOfFragment) {
            foreach ($stemsOfFragment as $stem) {
                $documentFrequency[$stem] = ($documentFrequency[$stem] ?? 0) + 1;
            }
        }

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence, " \t\n\r\0\x0B.!?");
            if ($sentence === '') {
                continue;
            }
            $words = array_values(array_unique($this->stemsOrdered($sentence)));
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

            $bestRatio       = 0.0;
            $bestFragmentIdx = null;
            foreach ($fragmentStems as $i => $stemsOfFragment) {
                $matchedWeight = 0.0;
                foreach ($words as $stem) {
                    if (in_array($stem, $stemsOfFragment, true)) {
                        $matchedWeight += $weights[$stem];
                    }
                }
                $ratio = $totalWeight > 0.0 ? $matchedWeight / $totalWeight : 0.0;
                if ($ratio > $bestRatio) {
                    $bestRatio       = $ratio;
                    $bestFragmentIdx = $i;
                }
            }

            if ($bestRatio < self::PROVENANCE_THRESHOLD || $bestFragmentIdx === null) {
                return false;
            }

            // Story 30 — обход: почти весь взвешенный вес фрагмента покрыт, дальше
            // проверять локальную плотность окном не нужно (см. докблок константы).
            if ($bestRatio >= self::RECOMBINATION_BYPASS_THRESHOLD) {
                continue;
            }

            // Story 30 — анти-рекомбинация: тот же вес, но по лучшему скользящему
            // окну ВНУТРИ уже выбранного фрагмента, а не по всему его тексту.
            $windowRatio = $this->bestWindowRatio($fragmentOrderedStems[$bestFragmentIdx], $words, $weights, $totalWeight);
            if ($windowRatio < self::RECOMBINATION_THRESHOLD) {
                return false;
            }
        }

        return true;
    }

    /**
     * Story 30 — лучшее покрытие предложения ($words/$weights) любым скользящим
     * окном фиксированного размера внутри ОДНОГО фрагмента (`$fragmentOrdered`,
     * порядок стемов как в исходном тексте). Окно фиксированной длины не зависит
     * от длины фрагмента — длинный документ больше не получает случайное
     * преимущество только за счёт объёма уникальной лексики.
     *
     * @param list<string>        $fragmentOrdered
     * @param list<string>        $words
     * @param array<string,float> $weights
     */
    private function bestWindowRatio(array $fragmentOrdered, array $words, array $weights, float $totalWeight): float
    {
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $length = count($fragmentOrdered);
        if ($length === 0) {
            return 0.0;
        }

        $best = 0.0;
        for ($start = 0; $start < $length; $start += self::RECOMBINATION_WINDOW_STRIDE) {
            $window = array_slice($fragmentOrdered, $start, self::RECOMBINATION_WINDOW_WORDS);
            if ($window === []) {
                break;
            }
            $windowSet     = array_unique($window);
            $matchedWeight = 0.0;
            foreach ($words as $stem) {
                if (in_array($stem, $windowSet, true)) {
                    $matchedWeight += $weights[$stem];
                }
            }
            $ratio = $matchedWeight / $totalWeight;
            if ($ratio > $best) {
                $best = $ratio;
            }
            if ($start + self::RECOMBINATION_WINDOW_WORDS >= $length) {
                break;
            }
        }

        return $best;
    }

    /**
     * Порядок и повторы стемов сохранены (не `array_unique`) — нужны скользящему
     * окну анти-рекомбинации (story 30); вызовы, которым важен только набор без
     * порядка (bag-of-stems для ratio), сами оборачивают результат в
     * `array_unique()` на месте.
     *
     * @return list<string>
     */
    private function stemsOrdered(string $text): array
    {
        preg_match_all('/\p{L}{' . self::MIN_SIGNIFICANT_WORD_LEN . ',}/u', mb_strtolower($text), $matches);

        $stems = [];
        foreach ($matches[0] as $word) {
            $stems[] = mb_substr($word, 0, self::PROVENANCE_STEM_LEN);
        }

        return $stems;
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

        // Story 47: story 38 утверждала «падать здесь действительно нечему» — ревью поймало
        // исполнением обратное. GuideCatalog::sections() читает настройки через
        // BotMenuService::menuLabel()/gatherOnCompassEnabled() и т.п., а те на КАЖДЫЙ вызов
        // конструируют `new GameSettingsService()`, которая в конструкторе создаёт
        // `new GameSettingsModel()` — ЭТА строка лежит вне try/catch метода `get()`
        // (GameSettingsService.php: конструктор строкой раньше самого метода, глушащий
        // Throwable try/catch есть только внутри `get()`). CI4 `Database::connect()`
        // способен бросить `CriticalError` уже на этом шаге (напр. отсутствующее
        // php-расширение драйвера БД, см. `Database::checkDbExtension()`), и `get()`'овский
        // try/catch этого не увидит — исключение улетает из конструктора раньше, чем
        // метод вообще начал выполняться. Между этой точкой и `defaultCorpus()` больше
        // нет ни одного try/catch (ни в `BotMenuService`, ни в `GuideCatalog::sections()`),
        // поэтому try/catch НИЖЕ — единственная защита, а не симметричное украшение рядом
        // с game_tips/site_posts.
        try {
            foreach (GuideCatalog::sections() as $section) {
                $fragments[] = ['source' => 'guide:' . $section['key'], 'text' => $section['title'] . ' ' . $section['body']];
            }
        } catch (\Throwable) {
            // См. game_tips/site_posts ниже — корпус просто уже, не падаем.
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
