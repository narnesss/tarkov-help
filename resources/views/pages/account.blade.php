@extends('layouts.site')
@section('content')
@include('headers.account')
<main class="account-shell">
  <a class="account-back" href="index.html">← На главную</a>
  <section class="account-hero" aria-labelledby="account-title">
    <div><span class="account-kicker">ЛИЧНЫЙ КАБИНЕТ</span><h1 id="account-title">Твой прогресс.<br><span>Всё под контролем.</span></h1><p id="account-description">Квесты, убежище и достижения — один профиль для всех твоих рейдов.</p></div>
    <div class="account-identity"><span class="account-avatar" aria-hidden="true">◈</span><div><strong id="account-name">Мой профиль</strong><p id="account-storage">Загружаем профиль…</p></div></div>
  </section>
  <p id="account-status" role="status" aria-live="polite"></p>
  <section class="account-auth-panel" id="account-auth" aria-labelledby="account-auth-title">
    <div class="account-auth-copy"><span class="account-kicker">СОХРАНИ СВОЙ ПУТЬ</span><h2 id="account-auth-title">Продолжай с любого устройства</h2><p>Войди в аккаунт, чтобы твои отметки и заметки всегда были под рукой.</p><p class="profile-help">Прогресс гостя остаётся в его профиле. Перед созданием нового аккаунта скачай его, а после входа загрузи в свой аккаунт.</p><button class="button" type="button" data-profile-tools>Скачать или перенести профиль</button></div>
    <div class="account-auth-form">
      <div class="account-auth-tabs" role="group" aria-label="Вход или регистрация"><button type="button" data-auth-view="login" aria-pressed="true">Вход</button><button type="button" data-auth-view="register" aria-pressed="false">Регистрация</button></div>
      <div class="account-social" id="account-providers"><button class="button social-google" type="button" data-oauth="google" disabled>Продолжить с Google</button><button class="button social-discord" type="button" data-oauth="discord" disabled>Продолжить с Discord</button></div>
      <p class="profile-help" id="account-provider-status">Проверяем доступные способы входа…</p>
      <div class="account-divider"><span>или по логину и паролю</span></div>
      <form id="account-form"><label class="profile-field">Логин<input name="username" autocomplete="username" required minlength="3" maxlength="32" aria-describedby="account-username-help"></label><p class="profile-help" id="account-username-help">От 3 до 32 букв или цифр, также можно использовать _ и дефис.</p><label class="profile-field">Пароль<input name="password" type="password" autocomplete="current-password" required aria-describedby="account-password-help"></label><p class="profile-help" id="account-password-help" hidden>От 12 до 72 байт. Для пароля из латинских букв — от 12 до 72 символов.</p><label class="profile-field" id="account-confirm-label" hidden>Повтори пароль<input name="confirmation" type="password" autocomplete="new-password"></label><button class="button primary" id="account-submit" type="submit">Войти</button></form>
    </div>
  </section>
  <section class="account-controls" aria-label="Настройки профиля"><div class="account-mode" role="group" aria-label="Игровой режим"><button type="button" data-account-mode="pve" aria-pressed="true">PVE</button><button type="button" data-account-mode="regular" aria-pressed="false">PVP</button></div><div class="account-actions"><button class="button" id="account-sync" type="button">Синхронизировать</button><button class="button" type="button" data-profile-tools>Управление профилем</button><button class="button" id="account-logout" type="button" hidden>Выйти</button></div></section>
  <section aria-labelledby="account-overview-title"><div class="account-section-heading"><div><span class="account-kicker" id="account-mode-label">ПРОГРЕСС PVE</span><h2 id="account-overview-title">Твой путь в Таркове</h2></div><span id="account-level"></span></div><div class="account-summary" id="account-summary" aria-live="polite"><p>Загружаем сводку…</p></div></section>
  <section class="account-detail-grid"><div class="account-detail"><h2>Активные квесты</h2><p class="profile-help">То, что ты уже начал проходить.</p><div id="account-active">Загрузка…</div></div><div class="account-detail"><h2>Убежище и достижения</h2><p class="profile-help">Отмеченные постройки и полученные награды.</p><div id="account-unlocks">Загрузка…</div></div></section>
  <section class="account-connections" id="account-connections" hidden aria-labelledby="account-connections-title"><h2 id="account-connections-title">Способы входа</h2><p class="profile-help">Подключи Google или Discord к этому аккаунту, чтобы сохранить тот же прогресс при входе любым способом.</p><div class="account-actions"><button class="button" type="button" data-oauth="google" disabled>Подключить Google</button><button class="button" type="button" data-oauth="discord" disabled>Подключить Discord</button></div><p class="profile-help" id="account-connections-status"></p></section>
  <noscript><p>Для входа и отображения личного прогресса включи JavaScript.</p></noscript>
</main>
<script type="module" src="bootstrap.mjs"></script>
@endsection
