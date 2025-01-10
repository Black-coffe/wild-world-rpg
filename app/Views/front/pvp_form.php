<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PvP Бой</title>
    <!-- Подключение Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        var csrfName = '<?= csrf_token() ?>'; // CSRF Token name
        var csrfHash = '<?= csrf_hash() ?>'; // CSRF hash
    </script>
</head>
<body class="bg-light">
<div class="container my-5">
    <h1 class="text-center mb-4">PvP Бой: Тестовая Симуляция</h1>

    <!-- Пояснительный текст -->
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="alert alert-info">
                <p><strong>Добро пожаловать!</strong> На этой странице вы можете протестировать систему пошагового боя между персонажами. Здесь происходит симуляция реальных боев, которые могут происходить в игре, но это <strong>тестовая версия</strong>, и результаты <strong>не влияют на игру</strong>.</p>
                <p>Тестирование поможет вам понять логику боёвки и формулы расчёта урона. Вы можете выбрать двух персонажей и биом, чтобы симулировать бой, и изучить, как проходят сражения.</p>
                <p>Помните, что в реальной игре результаты могут быть другими в зависимости от многих факторов. Здесь же вы можете попробовать разные комбинации и проверить механику боёв, прежде чем вступить в реальные сражения!</p>
            </div>
        </div>
    </div>

    <!-- Форма с выбором персонажей и биома -->
    <div class="row justify-content-center">
        <div class="col-md-12">
            <form id="pvpForm" class="p-4 border rounded bg-white shadow-sm d-flex flex-wrap align-items-end justify-content-between">
                <div class="mb-3 flex-fill me-2">
                    <label for="character1" class="form-label">Выберите персонажа №1:</label>
                    <select name="character1" id="character1" class="form-select">
                        <?php foreach ($characters as $character): ?>
                            <option value="<?= $character['id']; ?>"><?= $character['name']; ?> | id:<?= $character['id']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3 flex-fill me-2">
                    <label for="character2" class="form-label">Выберите персонажа №2:</label>
                    <select name="character2" id="character2" class="form-select">
                        <?php foreach ($characters as $character): ?>
                            <option value="<?= $character['id']; ?>"><?= $character['name']; ?> | id:<?= $character['id']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3 flex-fill me-2">
                    <label for="biome" class="form-label">Выберите биом:</label>
                    <select name="biome" id="biome" class="form-select">
                        <?php foreach ($biomes as $biome): ?>
                            <option value="<?= $biome['id']; ?>"><?= $biome['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">Начать бой</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Блок для вывода логов боя -->
    <div class="row mt-5">
        <div class="col-md-12 mx-auto">
            <div id="fightLogs" class="p-3 bg-white border rounded shadow-sm">
                <h2 class="text-center">Логи боя</h2>
                <div id="logContent">
                    <!-- Здесь будет выводиться результат боя -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Подключение Bootstrap 5 JS и Popper.js для интерактивных элементов -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- AJAX-запрос для отправки формы и вывода логов -->
<script>
    document.getElementById('pvpForm').addEventListener('submit', function(event) {
        event.preventDefault();  // Останавливаем стандартную отправку формы

        // Получаем данные формы
        const formData = new FormData(this);

        // Добавляем CSRF-токен
        formData.append(csrfName, csrfHash);

        // Отправляем AJAX-запрос
        fetch('/pvp/startFight', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                // Обновляем CSRF-токен из заголовков ответа
                csrfHash = response.headers.get('X-CSRF-Hash') || csrfHash;
                return response.json();
            })
            .then(data => {
                // Очищаем предыдущие логи
                const logContent = document.getElementById('logContent');
                logContent.innerHTML = '';

                // Создаем аккордеон
                const accordion = document.createElement('div');
                accordion.id = 'accordionLogs';

                data.fightLogs.forEach((roundLog, index) => {
                    const roundNumber = index + 1;
                    const collapseId = 'collapseRound' + roundNumber;

                    // Карточка аккордеона для раунда
                    const card = document.createElement('div');
                    card.classList.add('accordion-item');

                    // Заголовок раунда
                    const cardHeader = document.createElement('h2');
                    cardHeader.classList.add('accordion-header');
                    cardHeader.id = 'heading' + roundNumber;

                    const button = document.createElement('button');
                    button.classList.add('accordion-button', 'collapsed');
                    button.type = 'button';
                    button.setAttribute('data-bs-toggle', 'collapse');
                    button.setAttribute('data-bs-target', '#' + collapseId);
                    button.setAttribute('aria-expanded', 'false');
                    button.setAttribute('aria-controls', collapseId);
                    button.innerHTML = `Раунд ${roundLog.round}`;

                    cardHeader.appendChild(button);
                    card.appendChild(cardHeader);

                    // Контент раунда
                    const collapseDiv = document.createElement('div');
                    collapseDiv.id = collapseId;
                    collapseDiv.classList.add('accordion-collapse', 'collapse');
                    collapseDiv.setAttribute('aria-labelledby', 'heading' + roundNumber);
                    collapseDiv.setAttribute('data-bs-parent', '#accordionLogs');

                    const cardBody = document.createElement('div');
                    cardBody.classList.add('accordion-body');

                    // Здесь добавляем подробную информацию о раунде
                    cardBody.innerHTML = generateRoundDetails(roundLog);

                    collapseDiv.appendChild(cardBody);
                    card.appendChild(collapseDiv);

                    accordion.appendChild(card);
                });

                logContent.appendChild(accordion);
            })
            .catch(error => {
                console.error('Ошибка при обработке запроса:', error);
            });

        function generateRoundDetails(roundLog) {
            let html = '';

            // Информация об атакующем и защищающемся
            html += `<p><strong>Атакующий:</strong> ${roundLog.attacker.name}</p>`;
            html += `<p><strong>Защищающийся:</strong> ${roundLog.defender.name}</p>`;

            // Показатели перед атакой
            html += '<h5>Показатели перед атакой:</h5>';
            html += '<ul>';
            html += `<li><strong>${roundLog.attacker.name} Здоровье:</strong> ${Number(roundLog.attacker.health).toFixed(2)}</li>`;
            html += `<li><strong>${roundLog.defender.name} Здоровье:</strong> ${Number(roundLog.defender.health).toFixed(2)}</li>`;
            html += `<li><strong>${roundLog.attacker.name} Усталость:</strong> ${Number(roundLog.attacker_tired_before).toFixed(2)}</li>`;
            html += `<li><strong>${roundLog.defender.name} Усталость:</strong> ${Number(roundLog.defender_tired_before).toFixed(2)}</li>`;
            html += '</ul>';

            // Результат атаки
            const attack = roundLog.attack;
            if (attack.action === 'miss') {
                html += `<p><strong>Атака:</strong> ${roundLog.attacker.name} атаковал, но ${roundLog.defender.name} уклонился!</p>`;
            } else {
                html += `<p><strong>Атака:</strong> ${roundLog.attacker.name} нанес ${Number(attack.damage).toFixed(2)} урона ${roundLog.defender.name}!</p>`;
            }

            // Подробности расчета
            html += '<h5>Детали расчёта:</h5>';
            html += '<table class="table table-sm">';
            html += '<thead><tr><th>Параметр</th><th>Значение</th></tr></thead><tbody>';

            // Подсказки для параметров
            const tooltips = {
                'levelCoefficient': 'Модификатор уровня: зависит от уровня атакующего. Чем выше уровень, тем выше значение.',
                'tiredModifier': 'Модификатор усталости: отражает усталость атакующего. При 100% усталости модификатор равен 1.',
                'levelModifier': 'Модификатор уровня для увеличения урона на низких уровнях.',
                'baseDamage': 'Базовый урон: рассчитывается на основе силы атакующего.',
                'critChance': 'Шанс критического удара: вероятность нанесения критического урона.',
                'critRoll': 'Случайное число для определения критического удара.',
                'isCrit': 'Критический удар: указывает, был ли критический удар.',
                'critMultiplier': 'Множитель урона при критическом ударе.',
                'biomeModifier': 'Модификатор биома: учитывает влияние окружающей среды на бой.',
                'factionModifier': 'Модификатор фракции: бонусы или штрафы, связанные с фракцией.',
                'totalDamage': 'Общий урон: урон, который был бы нанесён без учёта защиты противника.',
                'defense': 'Защита: уменьшает получаемый урон, зависит от ловкости защищающегося.',
                'dodgeChance': 'Шанс уклонения: вероятность того, что защищающийся уклонится от удара.',
                'dodgeRoll': 'Случайное число для определения уклонения.',
                'isDodge': 'Уклонение: указывает, уклонился ли защищающийся.',
                'damageDealt': 'Нанесённый урон: итоговый урон после учёта всех модификаторов и защиты.'
            };

            for (const [key, value] of Object.entries(attack.calculations)) {
                const tooltip = tooltips[key] ? `title="${tooltips[key]}"` : '';
                html += `<tr><td ${tooltip}>${key}</td><td>${value}</td></tr>`;
            }

            html += '</tbody></table>';

            // Показатели после атаки
            html += '<h5>Показатели после атаки:</h5>';
            html += '<ul>';
            const defenderHealthAfter = Number(roundLog.defender.health).toFixed(2);
            html += `<li><strong>${roundLog.attacker.name} Усталость:</strong> ${Number(roundLog.attacker_tired_after).toFixed(2)}</li>`;
            html += `<li><strong>${roundLog.defender.name} Здоровье:</strong> ${defenderHealthAfter}</li>`;
            html += `<li><strong>${roundLog.defender.name} Усталость:</strong> ${Number(roundLog.defender_tired_after).toFixed(2)}</li>`;
            html += '</ul>';

            // Результат раунда
            if (roundLog.result) {
                html += `<p class="text-danger"><strong>Результат раунда:</strong> ${roundLog.result}</p>`;
            }

            return html;
        }

    });
</script>
</body>
</html>
