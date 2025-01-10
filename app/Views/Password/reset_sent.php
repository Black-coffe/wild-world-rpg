<?= $this->extend('templates/front') ?>

<?= $this->section('content') ?>

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
                    <div class="card-body p-4">
                        <h1>Пароль успешно сброшен</h1>
                        <p>Запрос на сброс пароля выполнен. Пожалуйста посмотрите ваш почтовый ящик.</p>
                        <p class="text-danger"><small>(Если нет во входящих, проверьте папку спам)</small></p>
                    </div> <!-- end card-body-->
                </div>
                <!-- end card-->
            </div> <!-- end col -->
        </div>
        <!-- end row -->
    </div>
    <!-- end container -->
</div>
<!-- end page -->

<?= $this->endSection() ?>
