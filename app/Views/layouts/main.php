<!-- app/Views/layouts/main.php -->
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <!-- Заголовок страницы: -->
    <title><?= $this->renderSection('title') ?></title>

    <!-- Подключаем общий CSS с главного домена -->
    <link rel="stylesheet" href="https://wildworld.fun/assets/css/main.css">

    <!-- Подключаем Bootstrap 5 CSS (пример, можно убрать/заменить) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">

    <!-- Секция для дополнительных CSS от конкретных страниц, если надо -->
    <?= $this->renderSection('extraCss') ?>

    <!-- Пример META-тегов -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta description="Описание страницы">
    <!-- favicon -->
    <link rel="icon" type="image/png" href="https://wildworld.fun/assets/img/favicon.png">
</head>
<body>

<!-- Шапка сайта (header / navbar) -->
<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container">
        <!-- Логотип / название -->
        <a href="/" class="navbar-brand">My Game</a>

        <!-- Кнопка-гамбургер (для мобильных) -->
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarMain"
                aria-controls="navbarMain"
                aria-expanded="false"
                aria-label="Переключить меню">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Меню -->
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a href="/" class="nav-link">Главная</a>
                </li>
                <li class="nav-item">
                    <a href="/battles" class="nav-link">Список боёв</a>
                </li>
                <li class="nav-item">
                    <a href="/inventory" class="nav-link">Инвентарь</a>
                </li>
                <li class="nav-item">
                    <a href="/map" class="nav-link">Карта</a>
                </li>
                <!-- Добавьте иконку/аватар или выпадающее меню профиля, если нужно -->
            </ul>
        </div>
    </div>
</nav>

<!-- Основной контейнер -->
<div class="container mt-4 mb-5">
    <!-- Секция контента из конкретной страницы -->
    <?= $this->renderSection('content') ?>
</div>

<!-- Пример подвала (footer) -->
<footer class="bg-light py-3">
    <div class="container text-center">
        <small class="text-muted">© 2025 My Game. Все права защищены.</small>
    </div>
</footer>

<!-- Подключаем Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Секция для дополнительных JS от конкретных страниц, если надо -->
<?= $this->renderSection('extraJs') ?>

</body>
</html>
