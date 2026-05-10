<?php
/**
 * F2.12 — общая часть <head> для admin layout'ов:
 *   - admin/layouts/default.php
 *   - templates/dashboard.php
 *   - templates/admin.php
 *
 * Не включает специфичные для конкретного layout стили (datatables-fixedcolumns,
 * quill, simplemde, pdf.js и т.п.) — они остаются в layout'ах.
 *
 * Доступ к переменной $title — через $this->include() передаются данные
 * родителя автоматически. Если родитель его не передал (напр. Shield-страницы
 * login/signup, рендерящие этот partial без 'title') — фолбэк 'Wild World',
 * иначе PHP 8 ронял ErrorException "Undefined variable $title" (CRITICAL в лог).
 */
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Wild World' ?></title>

    <meta content="Система, помогающая в своевременном и регулируемом режиме проводить технические проверки (чекины) автомобилей с использованием онлайн-сервиса." name="description" />
    <meta content="Checking of cars" name="author" />

    <!-- App favicon -->
    <link rel="shortcut icon" href="<?= base_url('images/favicon.ico') ?>">

    <!-- Theme Config Js -->
    <script src="<?= base_url('js/hyper-config.js') ?>"></script>
    <!-- App css -->
    <link href="<?= base_url('css/app-saas.min.css') ?>" rel="stylesheet" type="text/css" id="app-style" />
    <!-- Icons css -->
    <link href="<?= base_url('css/icons.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- My css -->
    <link href="<?= base_url('css/my.css') ?>" rel="stylesheet" type="text/css" />
