<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->include('admin/partials/_head_common', ['title' => $title ?? 'Восстановление пароля']) ?>
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
                            <h4 class="text-dark-50 text-center pb-0 fw-bold">Восстановление пароля</h4>
                            <p class="text-muted mb-4">Введите свой адрес электронной почты используемый при регистрации.</p>
                        </div>

                        <?= form_open("/password/process-forgot") ?>

                            <div class="form-group mb-3">
                                <label for="email" class="form-label">Email:</label>
                                <input type="email" class="form-control" name="email" id="email" value="<?= old('email') ?>" required>
                            </div>

                            <div class="mb-3 mb-0 text-center">
                                <button class="btn btn-primary" type="submit"> Отправить </button>
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

<?php if (session('warning')): ?>
<!-- Basic Toast -->
<div class="toast fade show" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
        <strong class="me-auto">Checking of cars</strong>
        <button type="button" class="ms-2 btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body">
        <?=session('warning') ?>
    </div>
</div> <!--end toast-->
<?php endif;?>

<footer class="footer footer-alt">
    2018 - <script>document.write(new Date().getFullYear())</script> © Hyper - Coderthemes.com
</footer>
<?= $this->include('admin/partials/_scripts_common') ?>

</body>
</html>
