// Разрешённые теги для Telegram parse_mode=HTML
const ALLOWED_TAGS = [
    'b', 'i', 'u', 's', 'strike', 'del', 'strong', 'em',
    'code', 'pre', 'a', 'tg-spoiler', 'br'
];

document.addEventListener('DOMContentLoaded', function() {
    // 1) Логика добавления новых полей для вариантов ответа
    const answersContainer = document.getElementById('answers-container');
    const addAnswerBtn = document.getElementById('add-answer-btn');

    if (addAnswerBtn && answersContainer) {
        addAnswerBtn.addEventListener('click', function() {
            const newField = document.createElement('input');
            newField.type = 'text';
            newField.name = 'answers[]';
            newField.classList.add('form-control', 'mb-2');
            newField.placeholder = 'Новый вариант ответа';
            answersContainer.appendChild(newField);
        });
    }

    // 2) Валидация HTML-тегов в вопросе перед отправкой формы
    const pollForm = document.getElementById('pollForm');
    if (pollForm) {
        pollForm.addEventListener('submit', function(e) {
            const questionField = document.getElementById('question');
            const questionValue = questionField.value.trim();

            if (!validateTelegramHtml(questionValue)) {
                e.preventDefault();
                alert('В тексте вопроса обнаружены HTML-теги, не поддерживаемые Telegram parse_mode=HTML.\nДопустимые теги: ' + ALLOWED_TAGS.join(', '));
            }
        });
    }
});

/**
 * Простейшая проверка HTML-тегов: парсим контент через DOMParser,
 * проверяем, что все встречающиеся теги входят в список ALLOWED_TAGS.
 */
function validateTelegramHtml(htmlString) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(htmlString, 'text/html');

    if (doc.querySelector('parsererror')) {
        return false;
    }

    const allElements = doc.body.querySelectorAll('*');
    for (let el of allElements) {
        const tagName = el.tagName.toLowerCase();
        if (!ALLOWED_TAGS.includes(tagName)) {
            if (tagName === 'a') {
                if (!el.hasAttribute('href')) {
                    return false;
                }
            } else {
                return false;
            }
        }
    }
    return true;
}
