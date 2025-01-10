<!-- Start  portfolio-slider Section-->
<section class="portfolio portfolio-blocks mega-section" id="portfolio">
    <div class="container">
        <div class="sec-heading  ">
            <div class="content-area"><span class=" pre-title wow fadeInUp " data-wow-delay=".2s"><?=$portfolio['0']['content'];?></span>
                <h2 class=" title    wow fadeInUp" data-wow-delay=".4s"><?=$portfolio['1']['content'];?></h2>
            </div>
            <div class=" cta-area   wow fadeInUp" data-wow-delay=".8s"><a class="cta-btn btn-solid" href="<?= base_url('portfolio')?>">see more <i class="bi bi-arrow-right icon "></i></a></div>
        </div>
        <div class="portfolio-wrapper  ">
            <!--a menu of links to show the photos users needs   -->
            <ul class="portfolio-btn-list wow fadeInUp" data-wow-delay=".2s">
                <li class="portfolio-btn active" data-filter="*"><?=$portfolio['2']['content'];?></li>
                <li class="portfolio-btn" data-filter=".mobile"><?=$portfolio['3']['content'];?></li>
                <li class="portfolio-btn" data-filter=".web"><?=$portfolio['4']['content'];?></li>
                <li class="portfolio-btn" data-filter=".data"><?=$portfolio['5']['content'];?></li>
                <li class="portfolio-btn" data-filter=".hosting"><?=$portfolio['6']['content'];?></li>
            </ul>
            <div class="portfolio-group wow fadeIn" data-wow-delay=".4s">
                <div class="row ">
                    <div class="col-12  col-md-6  col-lg-4  portfolio-item mobile ">
                        <div class="item">
                            <a class="portfolio-img-link">
                                <img class="portfolio-img   img-fluid " loading="lazy" src="<?= base_url().$portfolio['7']['content']?>" alt="portfolio item photo"/>
                            </a>
                            <div class="item-info ">
                                <h3 class="item-title"><?=$portfolio['8']['content'];?></h3><i class="bi bi-arrow-right icon "></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-12  col-md-6  col-lg-4  portfolio-item web  ">
                        <div class="item">
                            <a class="portfolio-img-link">
                                <img class="portfolio-img   img-fluid " loading="lazy" src="<?= base_url().$portfolio['9']['content']?>" alt="portfolio item photo"/>
                            </a>
                            <div class="item-info ">
                                <h3 class="item-title"><?=$portfolio['10']['content'];?></h3><i class="bi bi-arrow-right icon "></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-12  col-md-6  col-lg-4  portfolio-item data ">
                        <div class="item">
                            <a class="portfolio-img-link">
                                <img class="portfolio-img   img-fluid " loading="lazy" src="<?= base_url().$portfolio['11']['content']?>" alt="portfolio item photo"/>
                            </a>
                            <div class="item-info ">
                                <h3 class="item-title"><?=$portfolio['12']['content'];?></h3><i class="bi bi-arrow-right icon "></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-12  col-md-6  col-lg-4  portfolio-item mobile ">
                        <div class="item">
                            <a class="portfolio-img-link">
                                <img class="portfolio-img   img-fluid " loading="lazy" src="<?= base_url().$portfolio['13']['content']?>" alt="portfolio item photo"/>
                            </a>
                            <div class="item-info ">
                                <h3 class="item-title"><?=$portfolio['14']['content'];?></h3><i class="bi bi-arrow-right icon "></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-12  col-md-6  col-lg-4  portfolio-item hosting ">
                        <div class="item">
                            <a class="portfolio-img-link">
                                <img class="portfolio-img   img-fluid " loading="lazy" src="<?= base_url().$portfolio['15']['content']?>" alt="portfolio item photo"/>
                            </a>
                            <div class="item-info ">
                                <h3 class="item-title"><?=$portfolio['16']['content'];?></h3><i class="bi bi-arrow-right icon "></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-12  col-md-6  col-lg-4  portfolio-item mobile">
                        <div class="item">
                            <a class="portfolio-img-link">
                                <img class="portfolio-img   img-fluid " loading="lazy" src="<?= base_url().$portfolio['17']['content']?>" alt="portfolio item photo"/>
                            </a>
                            <div class="item-info ">
                                <h3 class="item-title"><?=$portfolio['18']['content'];?></h3><i class="bi bi-arrow-right icon "></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End  portfolio-slider Section-->