// Динамическое добавление полей для новых вариантов ответа
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('answers-container');
    const addBtn = document.getElementById('addNewAnswerBtn');
    if (!addBtn || !container) { return; }

    addBtn.addEventListener('click', function() {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'newAnswers[]';
        input.classList.add('aui-input');
        input.style.marginBottom = 'var(--sp-2)';
        input.placeholder = 'Новый вариант ответа';
        container.insertBefore(input, addBtn);
    });
});
