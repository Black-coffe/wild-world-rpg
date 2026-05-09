<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->include('admin/partials/_head_common', ['title' => $title ?? 'Создание нового пароля']) ?>
</head>

<body class="authentication-bg">

<div class="position-absolute start-0 end-0 start-0 bottom-0 w-100 h-100">
    <svg xmlns='http://www.w3.org/2000/svg' width='100%' height='100%' viewBox='0 0 800 800'>
        <g fill-opacity='0.22'>
            <circle style="fill: rgba(var(--ct-primary-rgb), 0.1);" cx='400' cy='400' r='600'/>
            <circle style="fill: rgba(var(--ct-primary-rgb), 0.2);" cx='400' cy='400' r='500'/>
            <circle style="fill: rgba(var(--ct-primary-rgb), 0.3);" cx='400' cy='400' r='300'/>
            <circle style="fill: rgba(var(--ct-primary-rgb), 0.4);" cx='400' cy='400' r='200'/>
            <circle style="fill: rgba(var(--ct-primary-rgb), 0.5);" cx='400' cy='400' r='100'/>
        </g>
    </svg>
</div>
<?php
if (session('errors')){
    echo "<pre>";
    print_r(session('errors'));

    echo "<br>";
    print_r($errors);
    echo "</pre>";
}

?>
<div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xxl-4 col-lg-5">
                <div class="card">
                    <!-- Logo-->
                    <div class="card-header py-4 text-center bg-primary">
                        <a href="<?= site_url()?>">
                            <span><img src="<?= site_url('images/logo.png')?>" alt="logo" height="22"></span>
                        </a>
                    </div>

                    <div class="card-body p-4">

                        <div class="text-center w-75 m-auto">
                            <h4 class="text-dark-50 text-center mt-0 fw-bold">Задать новый пароль</h4>
                            <p class="text-muted mb-4">После завершения процедуры, этот пароль будет вашим новым паролем для входа</p>
                        </div>

                        <?= form_open("/password/process-reset/$token"); ?>

                            <!-- Добавьте следующий код в вашу форму -->
                            <div class="form-group mb-3">
                                <label for="password" class="form-label">Пароль:</label>
                                <div class="input-group input-group-merge">
                                    <input type="password" class="form-control" name="password" id="password" placeholder="Придумайте пароль">
                                    <div class="input-group-text" data-password="false">
                                        <span class="password-eye"></span>
                                    </div>
                                </div>
                                <div id="password-error" class="alert alert-danger mt-2"></div>

                                <?php if(isset($validation['password'])): ?>
                                    <div class="alert alert-danger mt-2"><?= $validation['password'] ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group mb-3">
                                <label for="password_confirmation" class="form-label">Подтверждение пароля:</label>
                                <div class="input-group input-group-merge">
                                    <input type="password" class="form-control" name="password_confirmation" id="password_confirmation" placeholder="Подтвердите пароль">
                                    <div class="input-group-text" data-password="false">
                                        <span class="password-eye"></span>
                                    </div>
                                </div>
                                <?php if(isset($validation['password_confirmation'])): ?>
                                    <div class="alert alert-danger mt-2"><?= $validation['password_confirmation'] ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3 text-center form-group">
                                <button class="btn btn-primary" type="submit" id="resetButton" disabled>Восстановление</button>
                            </div>

                        </form>

                    </div> <!-- end card-body -->
                </div>
                <!-- end card -->

            </div> <!-- end col -->
        </div>
        <!-- end row -->
    </div>
    <!-- end container -->
</div>
<!-- end page -->

<footer class="footer footer-alt">
    2018 - <script>document.write(new Date().getFullYear())</script> © Checking of cars - checkuptruck.com
</footer>

<?= $this->include('admin/partials/_scripts_common') ?>

<script>
    $(document).ready(function() {
        // Селекторы для полей пароля и вывода сообщений об ошибках
        const passwordField = $('#password');
        const passwordError = $('#password-error');

        // Функция для проверки пароля
        function validatePassword(password) {
            const minLength = 8;
            const hasUpperCase = /[A-Z]/.test(password);
            const hasLowerCase = /[a-z]/.test(password);
            const hasSpecialChar = /[!@#$%^&*()_+[\]{};':"\\|,.<>/?]+/.test(password);

            return (
                password.length >= minLength &&
                hasUpperCase &&
                hasLowerCase &&
                hasSpecialChar
            );
        }

        // Функция для обновления сообщений об ошибках
        function updatePasswordError() {
            const password = passwordField.val().trim();
            const isValid = validatePassword(password);

            if (password.length === 0) {
                passwordError.text('Введите пароль');
            } else if (!isValid) {
                passwordError.text('Пароль должен содержать минимум 8 символов, включая минимум один символ в верхнем регистре, один в нижнем регистре и один спец-символ.');
            } else {
                passwordError.text('');
            }
        }

        // Обновление сообщений об ошибках при вводе
        passwordField.on('input', function() {
            updatePasswordError();

            // Получаем состояние валидации
            const isValid = validatePassword(passwordField.val().trim());

            // Активируем/деактивируем кнопку восстановления в зависимости от состояния валидации
            const resetButton = $('#resetButton');
            if (isValid) {
                resetButton.prop('disabled', false);
            } else {
                resetButton.prop('disabled', true);
            }
        });
    });
</script>
</body>
</html>

