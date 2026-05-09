<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->include('admin/partials/_head_common', ['title' => $title ?? 'Увійти | Система адміністрування rishuchi']) ?>
</head>

<body class="authentication-bg position-relative">
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

                    <!-- Logo -->
                    <div class="card-header py-4 text-center bg-primary">
                        <a href="<?= base_url()?>">
                            <span><img src="<?= base_url('images/logo.png')?>" alt="logo" height="22" title="Checking of cars"></span>
                        </a>
                    </div>

                    <div class="card-body p-4">

                        <div class="text-center w-75 m-auto">
                            <h4 class="text-dark-50 text-center pb-0 fw-bold">Увійти в систему</h4>
                            <p class="text-muted mb-4">Введіть адресу електронної пошти та пароль для доступу до панелі адміністратора.</p>
                        </div>

                        <form action="<?= site_url('/login/authenticate') ?>" method="post">
                            <?= csrf_field() ?>

                            <div class="form-group mb-3">
                                <label for="email" class="form-label">Email:</label>
                                <input type="email" class="form-control" name="email" id="email" placeholder="admin@example.com" value="<?= old('email', $data->email ?? '') ?>" required>
                            </div>

                            <div class="form-group mb-3">
                                <label for="password" class="form-label">Пароль:</label>
                                <input type="password" class="form-control" name="password" id="password" placeholder="Введите ваш пароль">

                            </div>

                            <div class="mb-3 form-group">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="checkbox-signin" checked>
                                    <label class="form-check-label" for="checkbox-signin">Запам'ятати мене</label>
                                </div>
                            </div>

                            <div class="mb-3 mb-0 text-center">
                                <button class="btn btn-primary" type="submit"> Увійти </button>
                            </div>

                        </form>
                    </div> <!-- end card-body -->
                </div>
                <!-- end card -->
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

<?= $this->include('admin/partials/_scripts_common') ?>

</body>
</html>
