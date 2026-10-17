@php($navigationKnown = isset($authenticated))
<a class="button profile-trigger" id="profile-button" href="account.html"@unless($navigationKnown) aria-busy="true"@endunless>{{ $navigationKnown && !$authenticated ? 'Мой прогресс' : 'Личный кабинет' }}</a>
@if (!($authenticated ?? false))
<a class="button player-login" href="account.html?view=login" data-player-guest{{ !$navigationKnown || $page === 'account' ? ' hidden' : '' }}>Войти</a>
<a class="button primary player-register" href="account.html?view=register" data-player-guest{{ !$navigationKnown || $page === 'account' ? ' hidden' : '' }}>Регистрация</a>
@endif
