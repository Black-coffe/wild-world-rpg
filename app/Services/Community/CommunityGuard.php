<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\GameTipsModel;
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
 *  1. Провенанс предложений (ADR-177 §1, поправлено ADR-178) — единица подтверждения
 *     предложение-юнит белого корпуса, mini-IDF по юнитам, ОДИН юнит целиком, никогда
 *     объединением нескольких. 🔴 Провенанс — БЕЗ права вето (ADR-178): признак не
 *     разделяет классы (законный пересказ своими словами измерен на 0.265, лучший
 *     несравнительный фабрикат — на 0.805; распределение двумодально, порога между
 *     ними не существует — лексическое покрытие детектирует дословность, а не
 *     правдивость). Рубеж 1 собирает `advisories` — пометки для владельца на
 *     одобрении (непокрытое предложение + адрес лучшего источника + ratio),
 *     `verdict()` возвращает `allow($advisories)`, если ни один вето-рубеж не
 *     сработал. 🔴 Story 72 — режим `deny` больше не денит на любом вызывающем:
 *     `verdict()` принимает явный параметр `$isApprovalContext` (default `false`,
 *     дефолт — БЕЗОПАСНАЯ сторона отправки, забытый/новый вызывающий не рискует
 *     заглушить уже одобренный ответ), и вето `deny`-режима применяется, ТОЛЬКО
 *     если он `true`. Только `CommunityController::approveAnswer()` передаёт
 *     `true` явно; `CommunityAutoReplyHandler` передаёт `false` явно (совпадает
 *     с default, но пишет решение текстом, не полагается на умолчание молча).
 *     Внутри рубежа 1 — отдельная,
 *     лексика-независимая проверка сравнительно-оценочной формы (§2 ADR-177,
 *     СОХРАНЯЕТ право вето): ключуется на союз сопоставления/корень оценки/
 *     рекомендательный оборот/сравнительную степень+условие-действие (R4,
 *     ADR-178 поправка №2), а не на список прилагательных (список в русском не
 *     закрывается, союз закрывается). 🔴 R5 (клауза-императив+маркер оптимизации)
 *     удалён ЦЕЛИКОМ поправкой №5 — денил ядро описательных ответов о механике
 *     (детектор императива по суффиксам путал существительные в позиции
 *     подлежащего), класс «императивный лайфхак» признан детерминированно
 *     непокрываемым, замена не вводится.
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
 * исключения конституции (рубеж 5, четыре ключа рубежа 1 из ADR-177/ADR-178) идут
 * через `GameSettingsService` по ключу, а не как «свериться с числом баланса».
 */
final class CommunityGuard
{
    /** Длина префикса для грубого стемминга (без морфологии, детерминированно). */
    private const PROVENANCE_STEM_LEN = 3;

    /** Минимальная длина слова, чтобы считаться «значимым» (не предлог/союз). */
    private const MIN_SIGNIFICANT_WORD_LEN = 4;

    /**
     * ADR-177 §1, поправлено ADR-178 — default порога рубежа 1.
     *
     * 🔴 Story 63 сначала расширила фабрикатную выборку до 22 полных предложений
     * и честно нашла: высший несравнительный фабрикат — **0.805**, а законный
     * пересказ своими словами (не цитата) — **0.265** на отдельном замере ревью
     * (12 ответов в тоне владельца, не цитаты `/guide`). Классы не просто
     * перекрыты — перевёрнуты: значения порога, при котором правда проходит, а
     * выдумка нет, **не существует**. Дальше: 0.50 и 0.80 дают ОДИНАКОВЫЙ
     * результат до строки на живой выборке — признак двумодален (у предложения
     * либо есть почти дословная опора ≈0.9+, либо её нет ≈0.3), а не непрерывен,
     * и число между модами не разделяет ничего. ADR-178: лексическое покрытие
     * детектирует ДОСЛОВНОСТЬ, не ПРАВДИВОСТЬ, и не имеет права вето именно
     * поэтому (см. `Verdict::$advisories`, `readProvenanceMode()`).
     *
     * Порог остаётся **0.65** (исходное число ADR-177) не потому что он лучше
     * 0.80 — они неотличимы — а потому что story 63 сняла обязательство его
     * перемерять: он больше не решает «можно/нельзя», а только регулирует
     * шумность пометки для ревьюера (выше — меньше пометок, ниже — больше).
     * Настраивать здесь больше нечего.
     */
    private const DEFAULT_PROVENANCE_THRESHOLD = 0.65;

    /**
     * ADR-177 §1 — юнит короче этого числа значимых слов подтверждением быть не
     * может (обрывок фразы совпадёт со слишком многим случайно). `GameSettings`
     * ключ `community.guard.min_source_sentence_words`, default 3.
     */
    private const DEFAULT_MIN_SOURCE_SENTENCE_WORDS = 3;

    /**
     * ADR-178 — режим рубежа 1: `advisory` (default, пометка без вето) ·
     * `deny` (старое поведение ADR-177, вето на одобрении — не на отправке,
     * см. `readProvenanceMode()` и место вызова в `verdict()`) · `off` (рубеж 1
     * не считается вовсе, ни пометок, ни вето). `GameSettings` ключ
     * `community.guard.provenance_mode`.
     */
    private const DEFAULT_PROVENANCE_MODE = 'advisory';

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
        'тысяч', 'миллион', 'процент', 'вдвое', 'втрое', 'сотн',
    ];

    /**
     * Story 69: «дцать»/«десят» — слова в {@see NUMERAL_WORDS}, которые НИКОГДА
     * не встречаются как отдельное слово (они инфикс-фрагменты составных
     * числительных: «два·дцать», «пять·десят»). Граница слова (см.
     * {@see matchesStopWord()}) их убила бы целиком — им оставлен старый
     * substring-матч. Story 73: «сотн» добавлен тем же приёмом — «сотня»/
     * «сотни»/«сотен»/«соток» не являются падежной формой слова «сто» (другой
     * порядок букв — «сот», не «сто», substring-матч по «сто» их не ловит ни
     * при какой границе), это отдельная лексема с тем же числовым смыслом.
     */
    private const SUBSTRING_FRAGMENT_WORDS = ['дцать', 'десят', 'сотн'];

    /** §5, рубеж 2 — слова-триггеры порога/лимита во входящем вопросе. */
    private const QUESTION_LEAK_KEYWORDS = ['потолок', 'максимум', 'порог', 'кулдаун', 'диапазон'];

    /** Формы проверки гипотезы во входящем — «правда, что…», «…, да?». */
    private const QUESTION_HYPOTHESIS_MARKERS = ['правда, что', 'правда что', 'не так ли'];

    /**
     * ADR-177 §2, R2 — корень оценочного сравнения БЕЗ союза сопоставления
     * («Лаборатория полезнее»). Список — дословно из ADR (`быстрее`/`выгоднее`
     * уже в {@see LEXICAL_STOPLIST}, здесь не дублируются).
     *
     * 🔴 Story 69, второй круг: исходные корни оканчивались на «е» (`выгодне`,
     * от «выгодн**ее**») и потому НЕ матчили базовую (не сравнительную) форму
     * оценки — «невыгодно» этот корень не содержит (в-ы-г-о-д-н-**о**, не
     * «-не»). Утечку «Идти в поход голодным невыгодно…» раньше денил не R2, а
     * случайное совпадение `NUMERAL_WORDS` («одно» внутри «невыг**одно**») —
     * тот самый класс дефекта, что чинила первая половина story 69; фикс
     * границы слова его закрыл, и утечка обнажилась. Последняя буква убрана у
     * каждого корня («выгодне» → «выгодн»): substring теперь совпадает и с
     * «-ее» (сравнительная степень), и с «-о» (базовое наречие/предикатив), И
     * с отрицанием «не-» ПЕРЕД корнем (substring не смотрит, что стоит до
     * начала совпадения — «не»+«выгодно» всё ещё содержит «выгодн»), без
     * добавления отдельного слова в список (списки в русском не закрываются —
     * урок уже дважды записан). Перемер живого `/guide` (32 раздела) после
     * этого сдвига: ложный отказ не изменился, 6.2% (2/32), обе прежние
     * причины — не новые (`## Findings`).
     */
    private const COMPARATIVE_EVALUATION_ROOTS = [
        'полезн', 'выгодн', 'эффективн', 'оптимальн', 'предпочтительн',
        'целесообразн', 'разумн', 'лучше', 'хуже',
    ];

    /** @var list<array{source: string, text: string}> */
    private array $corpus;

    private GameSettingsService $gameSettings;

    /**
     * @param list<array{source: string, text: string}>|null $corpus Белый корпус
     *        (адрес фрагмента + текст). null — собирается дефолтно из живого
     *        `GuideCatalog::sections()` + `game_tips` (ADR-177 §3: `site_posts`
     *        исключены — жанр без обязательства актуальности; `community_answers`
     *        не входят никогда — инвариант анти-храповика, корпус не питается
     *        собственным выходом бота) (см. {@see defaultCorpus()}). Тесты
     *        передают свой корпус — без БД.
     * @param GameSettingsService|null $gameSettings Читатель килсвитча по ключу
     *        (рубеж 5) и трёх ключей рубежа 1 (ADR-177). null — реальный
     *        `GameSettingsService()`. Тесты подменяют его
     *        конструктором с двойником `GameSettingsModel` (паттерн
     *        `CommunityIngestServiceTest`) — без реальной таблицы `game_settings`.
     */
    public function __construct(?array $corpus = null, ?GameSettingsService $gameSettings = null)
    {
        $this->corpus       = $corpus ?? $this->defaultCorpus();
        $this->gameSettings = $gameSettings ?? new GameSettingsService();
    }

    /**
     * @param bool $isApprovalContext Story 72 — единственный параметр, которым
     *        вызывающая сторона различает себя перед гвардом: `true` только у
     *        `CommunityController::approveAnswer()` (одобрение в админке),
     *        `false` (default) — у отправки, в т.ч. `CommunityAutoReplyHandler`.
     *        Управляет ТОЛЬКО тем, применяет ли `provenance_mode=deny` вето (см.
     *        место чтения ниже); на `advisory`/`off` не влияет никак — их
     *        поведение одинаково независимо от контекста. Default `false` —
     *        безопасная сторона: забытый или новый вызывающий не рискует молча
     *        заглушить уже одобренный ответ в живом чате, максимум пропустит
     *        то, что `deny` в admin-контексте отклонил бы.
     */
    public function verdict(string $answerText, string $questionText, ?string $requiresSetting, bool $isApprovalContext = false): Verdict
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

        // Рубеж 1, ADR-177 §2 — сравнительно-оценочная форма деньится НЕЗАВИСИМО от
        // лексического совпадения с корпусом, до самого провенанса: список
        // сравнительных прилагательных в русском не закрывается, а союз/корень/
        // рекомендательный оборот — закрывается. `comparative_form=off` — аварийный
        // выключатель этой части без деплоя (см. миграцию), сам рубеж 1 при этом
        // продолжает работать.
        if ($this->readComparativeFormMode() !== 'off' && $this->isComparativeClaim($answer)) {
            return Verdict::deny('comparative_claim', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
        }

        // Рубеж 1, ADR-178 — провенанс БЕЗ права вето: собирает пометки для
        // ревьюера, а не решает allow/deny. `off` — рубеж 1 не считается вовсе.
        $provenanceMode = $this->readProvenanceMode();
        if ($provenanceMode === 'off') {
            return Verdict::allow();
        }

        $advisories = $this->provenanceAdvisories($answer);

        // Story 72 — `deny` (опциональный откат к старому вето ADR-177) применяется
        // ТОЛЬКО в контексте одобрения (`$isApprovalContext === true`), НИКОГДА на
        // отправке: до фикса деньило на любом вызывающем без разбора — старый
        // докблок «на авто-отправке провенанс не считается никогда» был ложью,
        // ADR-178 явно требует «вето только на одобрении», а корпус между
        // одобрением и отправкой дрейфует (правка `/guide`), так что пересчёт
        // провенанса на отправке означал бы тихую смерть уже одобренного ответа.
        if ($provenanceMode === 'deny' && $isApprovalContext && $advisories !== []) {
            return Verdict::deny('no_provenance', CommunityVoice::REFUSAL_WITH_ROUTE[0]);
        }

        return Verdict::allow($advisories);
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

    /**
     * `GameSettings` читатели рубежа 1 (ADR-177) — сужение `mixed` через переменную,
     * не прямой cast на offset (phpstan L9 `cast.int`/`cast.float` на `mixed`,
     * memory `feedback_phpstan_no_mixed_to_int_cast`), тот же паттерн, что
     * `CommunityAnswerMatcher::readFloat()`.
     */
    private function readFloat(string $key, float $default): float
    {
        $raw = $this->gameSettings->get($key, $default);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    private function readInt(string $key, int $default): int
    {
        $raw = $this->gameSettings->get($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    /** `community.guard.comparative_form` — `deny` (default) / `off`. Неизвестное значение = `deny`. */
    private function readComparativeFormMode(): string
    {
        $raw = $this->gameSettings->get('community.guard.comparative_form', 'deny');

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : 'deny';
    }

    /**
     * ADR-178 — `community.guard.provenance_mode`: `advisory` (default) / `deny` /
     * `off`. Значение вне трёх — трактуется как `advisory` (тот же safe-default
     * принцип, что у остальных читателей `GameSettings` в этом классе).
     */
    private function readProvenanceMode(): string
    {
        $raw  = $this->gameSettings->get('community.guard.provenance_mode', self::DEFAULT_PROVENANCE_MODE);
        $mode = is_string($raw) && trim($raw) !== '' ? trim($raw) : self::DEFAULT_PROVENANCE_MODE;

        return in_array($mode, ['advisory', 'deny', 'off'], true) ? $mode : self::DEFAULT_PROVENANCE_MODE;
    }

    /**
     * ADR-177 §2, R4 добавлена поправкой ADR-178 №2 — сравнительно-оценочная
     * форма, ключуется на союз/корень/оборот, НЕ на список прилагательных.
     * Четыре детерминированных правила, срабатывает любое (регулярки —
     * дословно из ADR):
     *  - R1 союз сопоставления по границе слова, ПО СОЮЗАМ, не единообразно
     *    (ADR-178, поправка №3 — читай докблок `hasComparativeDegree()` и
     *    место вызова ниже, там же почему единообразная правка сломалась).
     *    «а не» — БЕЗУСЛОВНО (поправка №4 отменила вырез под «а не только/
     *    просто/лишь» поправки №3 — различитель стоял на неверной оси: у
     *    уточнения и замены идентичная поверхность, семантику список слов не
     *    берёт, «Ставь X, а не просто Y» проходило рядом с законным «говорить,
     *    а не только драться»);
     *  - R2 корень оценочного сравнения без союза («Лаборатория полезнее»);
     *  - R3 рекомендательный оборот («стоит»/«лучше»/«советую»/«рекомендую»/
     *    «имеет смысл») + инфинитив в том же предложении;
     *  - R4 сравнительная степень + условие-действие («попадаются чаще, если
     *    идти без брони») — зеркало R3: R3 ловит совет-в-главной-части, R4 —
     *    тот же совет в условной форме, которая по-русски естественнее. Обе
     *    части ОБЯЗАНЫ совпасть в одном предложении. Условие требует ИМЕННО
     *    инфинитив («если идти») — обобщённый совет по-русски берёт инфинитив,
     *    личная форма («если ты идёшь») адресна и на лайфхак похожа меньше;
     *    этот пробел признан и не закрывается (`## Findings` story 63).
     *    Принятое ложное срабатывание: прилагательное среднего рода на `-ее`
     *    («среднее», «лишнее») рядом с условием-инфинитивом — отказ несёт
     *    маршрут, владелец переформулирует.
     *
     * 🔴 R5 (клауза-императив + маркер оптимизации по времени/моменту) БЫЛ, но
     * УДАЛЁН ЦЕЛИКОМ поправкой №5 (story 78) — денил описательные фразы про
     * механику («Уровень здоровья восстанавливается, пока ты на базе»),
     * потому что детектор «императива» по суффиксам `и|й|ь` в начале клаузы
     * на деле ловил существительные-подлежащие. Класс «императивный лайфхак»
     * признан детерминированно непокрываемым (см. место удаления ниже) —
     * стоп-правило ADR-178 действует буквально: замена НЕ вводится.
     */
    private function isComparativeClaim(string $answer): bool
    {
        if ($answer === '') {
            return false;
        }
        $lower = mb_strtolower($answer);

        // R1, ADR-178 поправка №3 — ПО СОЮЗАМ, не единообразно. Ранняя ревизия
        // (story 65) нашла, что «чем» без требования степени резал риторические
        // обороты без сопоставления («не знаешь, чем заняться»); архитектор
        // отверг «требовать степень от всех союзов» — на «нежели»/«вместо» это
        // открыло бы класс советов-замен без степени по построению («Ставь
        // Лабораторию вместо Мастерской»), которые ни R3, ни R4 не ловят.
        //  - «нежели»/«вместо» — БЕЗУСЛОВНО, требовать степень запрещено.
        if (preg_match('/(?<![\p{L}])(нежели|вместо)(?![\p{L}])/u', $lower) === 1) {
            return true;
        }
        //  - «чем» — ТОЛЬКО вместе со сравнительной степенью в том же ответе
        //    (детектор общий с R4, см. `hasComparativeDegree()`).
        if (preg_match('/(?<![\p{L}])чем(?![\p{L}])/u', $lower) === 1 && $this->hasComparativeDegree($lower)) {
            return true;
        }
        //  - «а не» — БЕЗУСЛОВНО (ADR-178, поправка №4 — откат выреза поправки
        //    №3). Вырез под «а не только/просто/лишь» стоял на неверной оси:
        //    поверхность уточнения («можно говорить, а не только драться») и
        //    замены («Ставь Лабораторию, а не просто Мастерскую») идентична —
        //    список слов после союза семантику не различает. «Качай выносливость,
        //    а не только силу» проходило рядом с «Иди в лес, а не в горы» деньилось
        //    — асимметрия в опасную сторону. Цена отката — ложные отказы законных
        //    уточнений (`guide:npc`) — принята: уточнение тривиально
        //    перефразируется, лечится правкой источника, не рубежа.
        if (preg_match('/(?<![\p{L}])а\s+не(?![\p{L}])/u', $lower) === 1) {
            return true;
        }

        // R2.
        foreach (self::COMPARATIVE_EVALUATION_ROOTS as $root) {
            if (str_contains($lower, $root)) {
                return true;
            }
        }

        // R3.
        if (preg_match(
            '/(?<![\p{L}])(стоит|лучше|советую|рекомендую|имеет\s+смысл)\s+(\p{L}+\s+){0,2}\p{L}+(ть|ти|чь)(?![\p{L}])/u',
            $lower,
        ) === 1) {
            return true;
        }

        // R4 — сравнительная степень (тот же детектор, что у R1 «чем») И условие
        // с действием (если/когда/стоит только + инфинитив) в ОДНОМ предложении.
        if ($this->hasComparativeDegree($lower) && preg_match(
            '/(?<![\p{L}])(если|когда|стоит\s+только)(?![\p{L}])(?:[^.!?]*?)\p{L}+(ть|ти|чь)(?![\p{L}])/u',
            $lower,
        ) === 1) {
            return true;
        }

        // 🔴 R5 УДАЛЁН ЦЕЛИКОМ (ADR-178, поправка №5). Правило «клауза-императив
        // + маркер оптимизации по времени/моменту» денило ядро того, что бот
        // обязан говорить — описательные фразы про механику («Уровень здоровья
        // восстанавливается, пока ты на базе», «Маяки работают, пока стоит
        // база»): детектор «императива» опознавал суффиксы `и|й|ь` в начале
        // клаузы, а это в русском прежде всего СУЩЕСТВИТЕЛЬНЫЕ (мн.ч. на «-и»,
        // м./ж. род на «-ь»), и начало клаузы — позиция ПОДЛЕЖАЩЕГО: фильтр по
        // позиции не снимал шум, а концентрировал его. Обе фразы, которыми
        // story 73 доказывала безопасность правила, проходили случайно —
        // «Дрон заряжается, пока ты на базе» только потому, что «Дрон» короче
        // пяти букв. Класс «императивный лайфхак» признан детерминированно
        // НЕПОКРЫВАЕМЫМ по двум причинам, не снимаемым итерацией: (1)
        // морфологический детектор императива без словаря в русском не
        // строится — суффиксы многозначны, словарь глаголов-советов открыт;
        // (2) правило тривиально обходится тем же автором, против которого
        // заведено («Заходи в чужую базу, пока хозяин в походе» →
        // «В чужую базу можно зайти, пока хозяин в походе» — та же утечка,
        // ноль срабатываний). Остаточные формы держит человек, одобряющий
        // каждое слово (§5.2 плана, инвариант ADR-176) — не новая уступка, уже
        // объявленная граница. НЕ добавлять замену R5 в любом виде.

        return false;
    }

    /**
     * 🔴 ADR-178, поправка №3 — детектор сравнительной степени ОДНА функция,
     * общая для R1 («чем») и R4 (степень + условие-действие). Два отдельных
     * определения одного признака разошлись бы — тот же класс «два источника
     * правды у гейта», что уже заворачивался в этой спеке (см. отвергнутые
     * варианты ADR-178).
     *
     * Закрытый список нерегулярных форм ∪ продуктивный суффикс `-ее` — НЕ
     * список прилагательных (список в русском не закрывается: на нём уже была
     * пропущена «охотнее, чем», ADR-177, отвергнутые варианты). Суффикс `-ей`
     * НЕ используется — это окончание родительного/творительного («своей»,
     * «людей», «идей»), поймал бы пол-словаря.
     *
     * @param string $lower Уже приведённый к нижнему регистру текст.
     */
    private function hasComparativeDegree(string $lower): bool
    {
        if (preg_match(
            '/(?<![\p{L}])(больше|меньше|лучше|хуже|выше|ниже|чаще|реже|дальше|ближе|дольше|легче|проще|тише|дороже|дешевле|крепче|раньше|позже|глубже|шире)(?![\p{L}])/u',
            $lower,
        ) === 1) {
            return true;
        }

        return preg_match('/(?<![\p{L}])\p{L}{3,}ее(?![\p{L}])/u', $lower) === 1;
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
            if ($this->matchesStopWord($lower, $word)) {
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
        // Story 54: после «?» допускается любой хвост из не-букв/не-цифр (скобки,
        // многоточие, эмодзи, кавычки, обратный слэш) — живая пунктуация не должна
        // выключать проверку целиком, требуя, чтобы «?» был последним символом.
        return preg_match('/(?<![\p{L}])да\s*\?[^\p{L}\p{N}]*$/u', trim($question)) === 1;
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
            if ($this->matchesStopWord($lower, $word)) {
                return true;
            }
        }
        foreach (self::LEXICAL_STOPLIST as $word) {
            if ($this->matchesStopWord($lower, $word)) {
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

    /**
     * Story 73/78 — слова, чьё словоизменение МЕНЯЕТ буквы внутри слова, не
     * только добавляет их в конец, поэтому буквальный префикс самого слова не
     * покрывает его же формы:
     *  - «потолок» теряет беглую гласную «о» в косвенных падежах («потолка»,
     *    не «потолока») — префикс ломается на шестой букве.
     *  - «упирается»/«окупается»/«перестаёт» — спрягаемая форма 3-го лица
     *    множественного числа меняет гласную ПЕРЕД окончанием («упирает-СЯ» →
     *    «упирают-СЯ» уже другая буква на позиции 5) — story 78 нашла, что
     *    story 73's допуск на суффикс это не покрывал вовсе («упираются» не
     *    является словом «упирается» + хвост, буквы расходятся раньше конца).
     */
    private const STEM_OVERRIDES = [
        'потолок'   => 'потол',
        'упирается' => 'упира',
        'окупается' => 'окупа',
        'перестаёт' => 'переста',
    ];

    /**
     * Story 78 — допуск на лишние буквы-окончания ПЕР СЛОВНЫЙ, не по общей
     * длине стема (см. `## Findings` story 78: единая длина стема давала два
     * противоположных провала одновременно — 3 буквы ловили «достаточность»
     * от «достаточно» и «стол»/«сток» от «сто», а 4 буквы, нужные для
     * «упираются»/«окупаются», их же и не покрывали). Слово без записи здесь
     * получает безопасный default (1 буква для коротких корней story 69,
     * пойманных на случайных совпадениях — 0 для «сто», см. ниже).
     */
    private const TOLERANCE_OVERRIDES = [
        // «сто» практически не склоняется в разговорном употреблении; допуск
        // в 1 букву ловил «стол»/«сток» — посторонние слова, story 78.
        'сто'       => 0,
        'порог'     => 2, // порог/порога/пороги/порогов
        'потолок'   => 3, // от стема «потол»: -ок/-ка/-ки/-ков
        'упирается' => 4, // от стема «упира»: -ется/-ются
        'окупается' => 4, // от стема «окупа»: -ется/-ются
        'перестаёт' => 2, // от стема «переста»: -ёт/-ют
    ];

    /**
     * Story 69: `NUMERAL_WORDS`/`LEXICAL_STOPLIST` сверялись голым `str_contains()`
     * без границы слова — доказано исполнением (`## Findings`), что «сто» ловит
     * «ме**сто**»/«**сто**ит»/«про**сто**», «три» ловит «ос**три**ё», «один» ловит
     * «**один**аково». Тот же класс дефекта, что story 21 («баг»→«багаж») и
     * story 30 (хвост «…да?») — лечится тем же приёмом: граница слова + допуск
     * на лишние буквы-окончания. `SUBSTRING_FRAGMENT_WORDS` — исключение
     * («дцать»/«десят»/«сотн»): они НИКОГДА не бывают отдельным словом, это
     * инфикс-фрагменты, граница убила бы их целиком.
     *
     * 🔴 Story 78 — допуск на лишние буквы читается ПОСЛОВНО из
     * `TOLERANCE_OVERRIDES`, а не по общей формуле от длины стема (story 73's
     * подход по длине одновременно ловил «достаточность» от «достаточно» и
     * не ловил «упираются» от «упирается» — противоречие, которое ни одно
     * число не решает). Default для не перечисленных слов — 1 буква (тот же
     * консервативный допуск story 69).
     */
    private function matchesStopWord(string $lower, string $word): bool
    {
        if (in_array($word, self::SUBSTRING_FRAGMENT_WORDS, true)) {
            return str_contains($lower, $word);
        }

        $stem      = self::STEM_OVERRIDES[$word] ?? $word;
        $tolerance = self::TOLERANCE_OVERRIDES[$word] ?? 1;

        return preg_match(
            '/(?<![\p{L}])' . preg_quote($stem, '/') . '\p{L}{0,' . $tolerance . '}(?![\p{L}])/u',
            $lower,
        ) === 1;
    }

    /**
     * ADR-177 §1, поправлено ADR-178 — единица подтверждения предложение-юнит
     * корпуса, порог из `GameSettings` (`community.guard.provenance_threshold`,
     * default 0.65). Каждое предложение ответа сверяется со взвешенным покрытием
     * ОДНОГО юнита целиком — `bestRatio` ниже берёт максимум ПО ОДНОМУ юниту за
     * раз и никогда не суммирует покрытие нескольких: утверждение, набранное
     * объединением двух источников, по определению новое утверждение, которого
     * ни один источник не делал (инвариант ADR §1, story 63) — такое предложение
     * получает пометку, даже если по отдельности оба источника существуют.
     *
     * 🔴 Провенанс БЕЗ права вето (ADR-178) — метод НЕ решает allow/deny, только
     * СОБИРАЕТ пометки: каждая называет непокрытое предложение целиком, адрес
     * лучшего (даже если недостаточного) источника и его ratio — иначе ревьюеру
     * не на что смотреть в форме одобрения (story 68, не эта).
     *
     * @return list<string>
     */
    private function provenanceAdvisories(string $answer): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $answer) ?: [$answer];

        $minWords  = $this->readInt('community.guard.min_source_sentence_words', self::DEFAULT_MIN_SOURCE_SENTENCE_WORDS);
        $threshold = $this->readFloat('community.guard.provenance_threshold', self::DEFAULT_PROVENANCE_THRESHOLD);

        $units = $this->corpusUnits($minWords);

        // Мини-IDF, посчитанный ПО ЮНИТАМ-ПРЕДЛОЖЕНИЯМ (не по фрагментам, story 63):
        // редкое/специфичное слово («полис», «дрон») весит больше, чем то, что есть
        // в половине юнитов.
        $documentFrequency = [];
        foreach ($units as $unit) {
            foreach ($unit['stems'] as $stem) {
                $documentFrequency[$stem] = ($documentFrequency[$stem] ?? 0) + 1;
            }
        }

        $advisories = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence, " \t\n\r\0\x0B.!?");
            if ($sentence === '') {
                continue;
            }
            $words = array_values(array_unique($this->stemsOrdered($sentence)));
            if ($words === []) {
                // Предложение без содержательных слов (эмодзи/связка) — пометка не нужна.
                continue;
            }

            $weights     = [];
            $totalWeight = 0.0;
            foreach ($words as $stem) {
                $weights[$stem] = 1.0 / ($documentFrequency[$stem] ?? 1);
                $totalWeight    += $weights[$stem];
            }

            $bestRatio  = 0.0;
            $bestSource = null;
            foreach ($units as $unit) {
                $matchedWeight = 0.0;
                foreach ($words as $stem) {
                    if (in_array($stem, $unit['stems'], true)) {
                        $matchedWeight += $weights[$stem];
                    }
                }
                $ratio = $totalWeight > 0.0 ? $matchedWeight / $totalWeight : 0.0;
                if ($ratio > $bestRatio) {
                    $bestRatio  = $ratio;
                    $bestSource = $unit['source'];
                }
            }

            if ($bestRatio < $threshold) {
                $advisories[] = sprintf(
                    '«%s» — не подтверждено источником целиком (лучшее совпадение: %s, ratio=%.2f)',
                    $sentence,
                    $bestSource ?? '—',
                    $bestRatio,
                );
            }
        }

        return $advisories;
    }

    /**
     * ADR-177 §1 — корпус, разрезанный на предложения-юниты (не фрагменты
     * целиком). Юнит короче `$minWords` значимых слов подтверждением быть не
     * может — обрывок фразы совпадает со слишком многим случайно.
     *
     * @return list<array{source: string, stems: list<string>}>
     */
    private function corpusUnits(int $minWords): array
    {
        $units = [];
        foreach ($this->corpus as $fragment) {
            foreach ($this->sentencesOf($fragment['text']) as $sentence) {
                $stems = array_values(array_unique($this->stemsOrdered($sentence)));
                if (count($stems) < $minWords) {
                    continue;
                }
                $units[] = ['source' => $fragment['source'], 'stems' => $stems];
            }
        }

        return $units;
    }

    /**
     * Режет текст фрагмента на предложения по границе `.!?;:` или переносу строки;
     * markdown-декорации `GuideCatalog` (`*bold*`, «кавычки») сняты до резки, чтобы
     * они не мешали границе предложения.
     *
     * @return list<string>
     */
    private function sentencesOf(string $text): array
    {
        $plain = str_replace(['*', '_', '«', '»'], '', $text);
        $parts = preg_split('/(?<=[.!?;:])\s+|\n+/u', $plain) ?: [$plain];

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B.!?;:—-");
            if ($part !== '') {
                $sentences[] = $part;
            }
        }

        return $sentences;
    }

    /**
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
     * ADR-177 §3 — дефолтный белый корпус: живой `GuideCatalog` (адресами
     * `sections()`, не замороженной копией текста — иначе корпус начнёт врать про
     * кнопки) + `game_tips`. `site_posts` намеренно исключены (девблог — жанр
     * «как было / что поменяли», устаревающий по построению, без конституционного
     * обязательства актуальности вроде GUIDE-COVERAGE/TIPS-COVERAGE — провенанс
     * против такого источника легитимизировал бы устаревшее утверждение). 🔴
     * `community_answers` не входят никогда ни при каких обстоятельствах —
     * инвариант анти-храповика: корпус, питаемый собственным выходом бота, дрейфует
     * без верхней границы. Безопасная деградация при недоступной БД — как
     * `GameSettingsService::get()`: гвард сужает корпус, а не падает (известный
     * компромисс, см. `## Findings` story 07).
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
        // с game_tips.
        try {
            foreach (GuideCatalog::sections() as $section) {
                $fragments[] = ['source' => 'guide:' . $section['key'], 'text' => $section['title'] . ' ' . $section['body']];
            }
        } catch (\Throwable) {
            // См. game_tips ниже — корпус просто уже, не падаем.
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
 *
 * ADR-178 — `$advisories`: пометки рубежа 1 (провенанс), непустые ТОЛЬКО у `allow()`
 * (провенанс лишён права вето, `deny()`/`manual()` несут иную причину отказа, им
 * нечего рекомендовать вдобавок). Каждая строка называет непокрытое предложение,
 * адрес лучшего источника и его ratio — см. `CommunityGuard::provenanceAdvisories()`.
 */
final class Verdict
{
    /**
     * @param list<string> $advisories
     */
    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?string $route,
        public readonly array $advisories = [],
    ) {
    }

    /**
     * @param list<string> $advisories
     */
    public static function allow(array $advisories = []): self
    {
        return new self('allow', 'ok', null, $advisories);
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
