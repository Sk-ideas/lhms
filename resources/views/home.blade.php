@extends('layouts.home_page.master')

@section('content')
<style>
    :root {
    --primary-color: {{ $settings['theme_primary_color'] ?? '#56cc99' }};
    --secondary-color: {{ $settings['theme_secondary_color'] ?? '#215679' }};
    --secondary-color1: {{ $settings['theme_secondary_color_1'] ?? '#38a3a5' }};
    --primary-background-color: {{ $settings['theme_primary_background_color'] ?? '#f2f5f7' }};
    --text--secondary-color: {{ $settings['theme_text_secondary_color'] ?? '#5c788c' }};
    
}
</style>
<script src="{{ asset('assets/home_page/js/jquery-1-12-4.min.js') }}"></script>

<header class="navbar">
    <div class="container">
        <div class="navbarWrapper">
            <div class="navLogoWrapper">
                <div class="navLogo">
                    <a href="{{ url('/') }}">
                        <img src="{{ $settings['horizontal_logo'] ?? asset('assets/landing_page_images/Logo1.png') }}" class="logo" alt="">
                    </a>

                </div>
            </div>
            <div class="menuListWrapper">
                <ul>
                    <li>
                        <a href="{{ route('login') }}">{{ __('login') }}</a>
                    </li>
                    {{-- <li>
                        <a href="#features">{{ __('features') }}</a>
                    </li>
                    <li>
                        <a href="#about-us">{{ __('about_us') }}</a>
                    </li>
                    <li>
                        <a href="#pricing">{{ __('pricing') }}</a>
                    </li>
                    <li>
                    <a href="#faq">{{ __('FAQ') }}</a>
                    </li>
                    <li>
                        <a href="#contact-us">{{ __('contact') }}</a>
                    </li>
                    @if (count($guidances))
                        <li>
                            <div class="dropdown">
                                <a class="btn btn-secondary dropdown-toggle" href="#" role="button"
                                    id="dropdownMenuLink" data-bs-toggle="dropdown" aria-expanded="false">
                                    {{ __('guidance') }}
                                </a>                                
                                <ul class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                                    @foreach ($guidances as $key => $guidance)
                                        <li><a class="dropdown-item" href="{{ $guidance->link }}">{{ $guidance->name }}</a></li>
                                        @if (count($guidances) > ($key + 1))
                                            <hr>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                    @endif
                    <li>
                        <div class="dropdown">
                            <a class="btn btn-secondary dropdown-toggle" href="#" role="button"
                                id="dropdownMenuLink" data-bs-toggle="dropdown" aria-expanded="false">
                                {{ __('language') }}
                            </a>

                            <ul class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                                @foreach ($languages as $key => $language)
                                    <li><a class="dropdown-item" href="{{ url('set-language') . '/' . $language->code }}">{{ $language->name }}</a></li>
                                    @if (count($languages) > ($key + 1))
                                        <hr>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    </li> --}}

                </ul>
                {{-- <div class="hamburg">
                    <span data-bs-toggle="offcanvas" data-bs-target="#offcanvasRight"
                        aria-controls="offcanvasRight"><i class="fa-solid fa-bars"></i></span>
                </div> --}}
            </div>

            {{-- <div class="loginBtnsWrapper">
                <button class="commonBtn redirect-login">{{ __('login') }}</button>
                <button class="commonBtn" data-bs-toggle="modal" data-bs-target="#staticBackdrop">{{ __('start_trial') }}</button>
            </div> --}}
        </div>

        {{-- <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasRight"
            aria-labelledby="offcanvasRightLabel">
            <div class="offcanvas-header">
                <div class="navLogoWrapper">
                    <div class="navLogo">
                        <img src="{{ $settings['horizontal_logo'] ?? asset('assets/landing_page_images/Logo1.svg') }}" alt="">
                    </div>
                </div>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas"
                    aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <ul class="listItems">
                    <li>
                        <a href="#home">{{ __('home') }}</a>
                    </li>
                    <li>
                        <a href="#features">{{ __('features') }}</a>
                    </li>
                    <li>
                        <a href="#about-us">{{ __('about_us') }}</a>
                    </li>
                    <li>
                        <a href="#pricing">{{ __('pricing') }}</a>
                    </li>
                    <li>
                        <a href="#faq">{{ __('FAQ') }}</a>
                    </li>
                    <li>
                        <a href="#contact-us">{{ __('contact') }}</a>
                    </li>
                    @if (count($guidances))
                        <li>
                            <div class="dropdown">
                                <a class="btn btn-secondary dropdown-toggle" href="#" role="button"
                                    id="dropdownMenuLink" data-bs-toggle="dropdown" aria-expanded="false">
                                    {{ __('guidance') }}
                                </a>                                
                                <ul class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                                    @foreach ($guidances as $key => $guidance)
                                        <li><a class="dropdown-item" href="{{ $guidance->link }}">{{ $guidance->name }}</a></li>
                                        @if (count($guidances) > ($key + 1))
                                            <hr>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                    @endif
                    <li>
                        <div class="dropdown">
                            <a class="btn btn-secondary dropdown-toggle" href="#" role="button"
                                id="dropdownMenuLink" data-bs-toggle="dropdown" aria-expanded="false">
                                {{ __('language') }}
                            </a>

                            <ul class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                                @foreach ($languages as $key => $language)
                                    <li><a class="dropdown-item" href="{{ url('set-language') . '/' . $language->code }}">{{ $language->name }}</a></li>
                                    @if (count($languages) > ($key + 1))
                                        <hr>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    </li>

                </ul>

                <div class="loginBtnsWrapper">
                    <button class="commonBtn redirect-login">{{ __('login') }}</button>
                    <button class="commonBtn" data-bs-toggle="modal" data-bs-target="#staticBackdrop">{{ __('start_trial') }}</button>
                </div>
            </div>
        </div> --}}
    </div>
</header>

<!-- navbar ends here  -->

<div class="main">

    <section class="heroSection" id="home">
        <div class="linesBg">
            <div class="colorBg">
                <div class="container">
                    <div class="row">
                        <div class="col-md-12 col-lg-6">
                            <div class="flex_column_start">
                                <span class="commonTitle">{{ __('MGM School') }}</span>
                                <span class="commonDesc">
                                    {{ $settings['tag_line'] }}
                                </span>
                                <span class="commonText">
                                {{ $settings['hero_description'] }}</span>
                            </div>
                        </div>
                        <div class="col-md-12 col-lg-6 heroImgWrapper">
                            <div class="heroImg">
                                <img src="{{ $settings['home_image'] ?? asset('assets/landing_page_images/heroImg.png') }}" alt="">
                                <div class="topRated card">
                                    <div>
                                        <img src="{{ $settings['hero_title_2_image'] ?? asset('assets/landing_page_images/user.png') }}" alt="">
                                    </div>
                                    <div>
                                        <span>{{ $settings['hero_title_2'] }}</span>
                                    </div>
                                </div>
                                <div class="textWrapper">
                                    <span>{{ $settings['hero_title_1'] }}</span>
                                </div>    
                                
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- heroSection ends here  -->

    {{-- <section class="features commonMT container" id="features">
        <div class="row">
            <div class="col-12">
                <div class="sectionTitle">
                    <span>{{ __('explore_our_top_features') }}</span>
                </div>
            </div>
            <div class="col-12">
                <div class="row cardWrapper">
                    @foreach ($features as $key => $feature)
                        @if ($key < 9)
                            <div class="col-sm-12 col-md-6 col-lg-4">
                                <div class="card">
                                    <div>
                                        <img src="{{ asset('assets/landing_page_images/features/') }}/{{ $feature->name }}.svg" alt="">
                                    </div>
                                    <div><span>{{ __($feature->name) }}</span></div>
                                </div>
                            </div>
                        @else
                            <div class="col-sm-12 col-md-6 col-lg-4 default-feature-list" style="display: none">
                                <div class="card">
                                    <div>
                                        <img src="{{ asset('assets/landing_page_images/features/') }}/{{ $feature->name }}.svg" alt="">
                                    </div>
                                    <div><span>{{ __($feature->name) }}</span></div>
                                </div>
                            </div>
                        @endif
                        
                    @endforeach
                    <div class="col-12">
                        <button class="commonBtn view-more-feature" value="1">{{ __('view_more_features') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </section> --}}
    <!-- features ends here  -->

    <section class="swiperSect container commonMT">
        <div class="row">
            <div class="col-12">
                <div class="commonSlider">
                    <div class="slider-content owl-carousel">
                        <!-- Example slide -->
                        @foreach ($schoolSettings as $school)
                            @if (Storage::disk('public')->exists($school->getRawOriginal('data')))
                                <div class="swiperDataWrapper">
                                    <div class="card">
                                        <img src="{{ $school->data }}" class="normalImg" alt="">
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        <!-- Add more swiperDataWrapper elements here -->
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- swiperSect ends here  -->

    {{-- <section class="counterSect commonMT container">
        <div class="">
            <div class="row counterBG">
                <div class="col-4 col-sm-4 col-md-4 col-lg-4">
                    <div class="card">
                        <div><span class="numb" data-target="{{ $counter['school'] }}">0</span><span>+</span></div>
                        <div><span class="text">{{ __('schools') }}</span></div>
                    </div>
                </div>
                <div class="col-6 col-sm-6 col-md-6 col-lg-6">
                    <div class="card">
                        <div><span class="numb" data-target="{{ $counter['teacher'] }}">0</span><span>+</span></div>
                        <div><span class="text">{{ __('teachers') }}</span></div>
                    </div>
                </div>
                <div class="col-6 col-sm-6 col-md-6 col-lg-6">
                    <div class="card">
                        <div><span class="numb" data-target="{{ $counter['student'] }}">0</span><span>+</span></div>
                        <div><span class="text">{{ __('students') }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section> --}}

    {{-- <section class="ourApp container commonMT">
        <div class="row">
            <div class="col-lg-6">
                <img src="{{ $settings['download_our_app_image'] ?? asset('assets/landing_page_images/ourApp.png') }}" class="ourAppImg" alt="">
            </div>
            <div class="col-lg-6 content">
                <div class="text">
                    <span class="title">{{ __('download_our_app_now') }}</span>
                    <span>
                        {{ $settings['download_our_app_description'] ?? '' }}
                    </span>
                </div>
                <div class="storeImgs">
                    <a href="{{ $settings['app_link'] ?? '' }}" target="_blank"> <img src="{{ asset('assets/landing_page_images/Google play.png') }}" alt=""> </a>
                    <a href="{{ $settings['ios_app_link'] ?? ''}}" target="_blank"> <img src="{{ asset('assets/landing_page_images/iOS app Store.png') }}" alt=""> </a>
                </div>
            </div>
        </div>
    </section> --}}
</div>


@endsection

@section('script')
    @foreach ($featureSections as $key => $section)
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const tabs = document.querySelectorAll('.left-section-{{ $section->id }} .tab');
                const contents = document.querySelectorAll('.left-section-{{ $section->id }} .content');

                function switchTab(event, tabNumber) {
                    tabs.forEach((tab) => {
                        tab.classList.remove('active');
                    });

                    event.target.classList.add('active');

                    contents.forEach((content) => {
                        content.classList.remove('active');
                    });

                    contents[tabNumber - 1].classList.add('active');
                }

                tabs.forEach((tab, index) => {
                    tab.addEventListener('click', (event) => {
                        switchTab(event, index + 1);
                    });
                });

                setTimeout(() => {
                    tabs[0].click();
                }, 1000);
            });

            document.addEventListener('DOMContentLoaded', () => {
                const tabs = document.querySelectorAll('.right-section-{{ $section->id }} .tab');
                const contents = document.querySelectorAll('.right-section-{{ $section->id }} .content');

                function switchTab(event, tabNumber) {
                    tabs.forEach((tab) => {
                        tab.classList.remove('active');
                    });

                    event.target.classList.add('active');

                    contents.forEach((content) => {
                        content.classList.remove('active');
                    });

                    contents[tabNumber - 1].classList.add('active');
                }

                tabs.forEach((tab, index) => {
                    tab.addEventListener('click', (event) => {
                        switchTab(event, index + 1);
                    });
                });

                setTimeout(() => {
                    tabs[0].click();
                }, 1000);
            });
        </script>
    @endforeach
    <script>
        $('.redirect-login').click(function (e) { 
            e.preventDefault();
            window.location.href = "{{ url('login') }}"
        });
    </script>
    <script>
        @if (Session::has('success'))
        $.toast({
            text: '{{ Session::get('success') }}',
            showHideTransition: 'slide',
            icon: 'success',
            loaderBg: '#f96868',
            position: 'top-right',
            bgColor: '#20CFB5'
        });
        @endif

        @if (Session::has('error'))
        $.toast({
            text: '{{ Session::get('error') }}',
            showHideTransition: 'slide',
            icon: 'error',
            loaderBg: '#f2a654',
            position: 'top-right',
            bgColor: '#FE7C96'
        });
        @endif
    </script>
@endsection