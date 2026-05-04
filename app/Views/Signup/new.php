<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Регистрация | CoC - Checking of cars</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Система мониторинга и учета, а также предотвращения и оповещения по управлению транспортной компанией" name="description" />
    <meta content="Andrivskii" name="author" />

    <!-- App favicon -->
    <link rel="shortcut icon" href="<?= base_url('images/favicon.ico')?>">

    <!-- Theme Config Js -->
    <script src="<?= base_url('js/hyper-config.js')?>"></script>

    <!-- App css -->
    <link href="<?= base_url('css/app-saas.min.css')?>" rel="stylesheet" type="text/css" id="app-style" />

    <!-- Icons css -->
    <link href="<?= base_url('css/icons.min.css')?>" rel="stylesheet" type="text/css" />
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
                            <h4 class="text-dark-50 text-center mt-0 fw-bold">Регистрация администратора</h4>
                            <p class="text-muted mb-4">После регистрации вы не сможете войти, пока вам не дадут разрешение внутри системы</p>
                        </div>

                        <form action="<?= site_url('/signup/create') ?>" method="post">
                            <?= csrf_field() ?>

                            <div class="form-group mb-3">
                                <label for="name" class="form-label">Имя:</label>
                                <input type="text" class="form-control" name="name" id="name" placeholder="Ваше имя" value="<?= old('name', $data->name ?? '') ?>">
                                <?php if(isset($validation['name'])): ?>
                                    <div class="alert alert-danger mt-2"><?= $validation['name'] ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group mb-3">
                                <label for="email" class="form-label">Email:</label>
                                <input type="email" class="form-control" name="email" id="email" placeholder="Ваша email почта" value="<?= old('email', $data->email ?? '') ?>">
                                <?php if(isset($validation['email'])): ?>
                                    <div class="alert alert-danger mt-2"><?= $validation['email'] ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group mb-3">
                                <label for="password" class="form-label">Пароль:</label>
                                <div class="input-group input-group-merge">
                                    <input type="password" class="form-control" name="password" id="password" placeholder="Придумайте пароль">
                                    <div class="input-group-text" data-password="false">
                                        <span class="password-eye"></span>
                                    </div>
                                </div>
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
                                <button class="btn btn-primary" type="submit"> Регистрация </button>
                            </div>

                        </form>

                    </div> <!-- end card-body -->
                </div>
                <!-- end card -->

                <div class="row mt-3">
                    <div class="col-12 text-center">
                        <p class="text-muted">Уже есть аккаунт? <a href="<?= site_url()?>" class="text-muted ms-1"><b>Войти</b></a></p>
                    </div> <!-- end col-->
                </div>
                <!-- end row -->

            </div> <!-- end col -->
        </div>
        <!-- end row -->
    </div>
    <!-- end container -->
</div>
<!-- end page -->

<footer class="footer footer-alt">
    2018 - <script>document.write(new Date().getFullYear())</script> © Hyper - Coderthemes.com
</footer>

<!-- Vendor js -->
<script src="<?= site_url('js/vendor.min.js')?>"></script>

<!-- App js -->
<script src="<?= site_url('js/app.min.js')?>"></script>

</body>
</html>
