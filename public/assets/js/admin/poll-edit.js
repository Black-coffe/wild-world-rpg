// Динамическое добавление полей для новых вариантов ответа
document.addEventListener('DOMContentLoaded', function() {
    const container = document.querySelector('.mb-3');
    const addBtn = document.getElementById('addNewAnswerBtn');
    if (!addBtn || !container) { return; }

    addBtn.addEventListener('click', function() {
        const div = document.createElement('div');
        div.classList.add('mb-2');
        div.innerHTML = '<input type="text" name="newAnswers[]" class="form-control" placeholder="Новый вариант ответа">';
        container.appendChild(div);
    });
});
