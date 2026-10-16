@extends('layouts.site')
@section('content')
@include('headers.account')
<main class="account-shell">
  <a class="account-back" href="index.html">← На главную</a>
  <section class="account-hero" aria-labelledby="account-title">
    <div><span class="account-kicker">ЛИЧНЫЙ КАБИНЕТ</span><h1 id="account-title">Твой прогресс.<br><span>Всё под контролем.</span></h1><p id="account-description">Квесты, убежище и достижения — один профиль для всех твоих рейдов.</p></div>
    <div class="account-identity"><span class="account-avatar" id="account-avatar" aria-hidden="true">◈</span><div><strong id="account-name">Мой профиль</strong><p id="account-storage">Загружаем профиль…</p></div></div>
  </section>
  <p id="account-status" role="status" aria-live="polite"></p>
  <section class="account-settings" id="account-settings" hidden aria-labelledby="account-settings-title"><h2 id="account-settings-title">Твой профиль</h2><p class="profile-help">Имя и аватар принадлежат профилю и не меняются при выборе способа входа. При первой регистрации через Google или Discord они берутся из этого сервиса. Отображаемое имя можно изменить здесь.</p><form id="account-name-form" method="post" action="/api/auth/profile"><label class="profile-field">Отображаемое имя<input name="display_name" required maxlength="64" autocomplete="nickname" aria-describedby="account-name-help"></label><p class="profile-help" id="account-name-help">От 1 до 64 символов. Это имя профиля; логин для входа не меняется.</p><div class="account-actions"><button class="button primary" type="submit">Сохранить имя</button></div></form></section>
  <section class="account-auth-panel" id="account-auth" hidden aria-labelledby="account-auth-title">
    <div class="account-auth-copy"><span class="account-kicker">СОХРАНИ СВОЙ ПУТЬ</span><h2 id="account-auth-title">Продолжай с любого устройства</h2><p>Войди в аккаунт, чтобы твои отметки и заметки всегда были под рукой.</p><p class="profile-help">Прогресс гостя остаётся в его профиле. Перед созданием нового аккаунта скачай его, а после входа загрузи в свой аккаунт.</p><button class="button" type="button" data-profile-tools>Скачать или перенести профиль</button></div>
    <div class="account-auth-form">
      <div class="account-auth-tabs" role="group" aria-label="Вход или регистрация"><button type="button" data-auth-view="login" aria-pressed="true">Вход</button><button type="button" data-auth-view="register" aria-pressed="false">Регистрация</button></div>
      <p id="account-auth-feedback" role="status" aria-live="polite"></p>
      <div id="account-auth-login">
      <div class="account-social" id="account-providers"><button class="button social-google" type="button" data-oauth="google" disabled>Продолжить с Google</button><button class="button social-discord" type="button" data-oauth="discord" disabled>Продолжить с Discord</button></div>
      <p class="profile-help" id="account-provider-status">Проверяем доступные способы входа…</p>
      @include('components.legal-checkboxes', ['prefix'=>'account-oauth', 'purpose'=>'Для входа через Google или Discord'])
      <div class="account-divider"><span>или по логину и паролю</span></div>
      <form id="account-form" method="post" action="/api/auth/login"><p id="account-username-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Логин<input name="username" autocomplete="username" required minlength="3" maxlength="32" aria-describedby="account-username-help"></label><p class="profile-help" id="account-username-help">От 3 до 32 букв или цифр, также можно использовать _ и дефис.</p><p id="account-password-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Пароль<input name="password" type="password" autocomplete="current-password" required aria-describedby="account-password-help"></label><p class="profile-help" id="account-password-help" hidden>Минимум 12 символов (лучше 16 и больше), максимум 72 байта. Нужны заглавная и строчная латинские буквы, цифра и спецсимвол. Используй отдельный пароль для этого сайта, без популярных слов, дат, последовательностей и прежних паролей.</p><label class="profile-field" id="account-confirm-label" hidden>Повтори пароль<input name="confirmation" type="password" autocomplete="new-password"></label>@include('components.legal-checkboxes', ['prefix'=>'account-register', 'purpose'=>'Для регистрации'])
<button class="button primary" id="account-submit" type="submit">Войти</button></form>
      <button class="account-link account-forgot" type="button" id="account-forgot">Забыли пароль?</button>
      </div>
      <section id="account-recovery" hidden aria-labelledby="account-recovery-title">
        <h3 id="account-recovery-title">Восстановление пароля</h3>
        <p class="profile-help">Укажи почту, которую подтвердил в личном кабинете. Восстановление сохранит твой прежний аккаунт и прогресс.</p>
        <form id="account-recovery-request" method="post" action="/api/auth/password/request">
          <p id="account-recovery-email-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Подтверждённый e-mail<input type="email" name="email" required maxlength="254" autocomplete="email"></label>
          <button class="button" id="account-recovery-send" type="submit" disabled>Получить код восстановления</button>
        </form>
        <form id="account-recovery-reset" method="post" action="/api/auth/password/reset" hidden>
          <p id="account-recovery-code-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Код восстановления<input name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" minlength="8" maxlength="8" aria-describedby="account-recovery-code-help"></label>
          <p class="profile-help" id="account-recovery-code-help">8 цифр, срок действия — 1 час. Введи код в том же браузере, где запросил письмо.</p>
          <p id="account-recovery-password-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Новый пароль<input name="password" type="password" autocomplete="new-password" required aria-describedby="account-recovery-password-help"></label>
          <p class="profile-help" id="account-recovery-password-help">Минимум 12 символов (лучше 16 и больше), максимум 72 байта. Нужны заглавная и строчная латинские буквы, цифра и спецсимвол. Используй отдельный пароль для этого сайта, без популярных слов, дат, последовательностей и прежних паролей.</p>
          <label class="profile-field">Повтори новый пароль<input name="password_confirmation" type="password" autocomplete="new-password" required></label>
          <button class="button primary" id="account-recovery-confirm" type="submit">Сохранить новый пароль</button>
        </form>
        <p class="profile-help">Если почта ещё не подтверждена, войди прежним способом и подтверди её в кабинете. Прогресс другого гостевого профиля не переносится автоматически.</p>
        <button class="account-link account-forgot" type="button" id="account-recovery-back">Вернуться ко входу</button>
      </section>
    </div>
  </section>
  <section class="account-controls" aria-label="Настройки профиля"><div class="account-mode" role="group" aria-label="Игровой режим"><button type="button" data-account-mode="pve" aria-pressed="true">PVE</button><button type="button" data-account-mode="regular" aria-pressed="false">PVP</button></div><div class="account-actions"><button class="button" id="account-sync" type="button">Синхронизировать</button><button class="button" type="button" data-profile-tools>Управление профилем</button><button class="button" id="account-logout" type="button" hidden>Выйти</button></div></section>
  <section aria-labelledby="account-overview-title"><div class="account-section-heading"><div><span class="account-kicker" id="account-mode-label">ПРОГРЕСС PVE</span><h2 id="account-overview-title">Твой путь в Таркове</h2></div><span id="account-level"></span></div><div class="account-summary" id="account-summary" aria-live="polite"><p>Загружаем сводку…</p></div></section>
  <section class="account-detail-grid"><div class="account-detail"><h2>Активные квесты</h2><p class="profile-help">То, что ты уже начал проходить.</p><div id="account-active">Загрузка…</div></div><div class="account-detail"><h2>Убежище и достижения</h2><p class="profile-help">Отмеченные постройки и полученные награды.</p><div id="account-unlocks">Загрузка…</div></div></section>
  <section class="account-connections" id="account-connections" hidden aria-labelledby="account-connections-title"><h2 id="account-connections-title">Способы входа</h2><p class="profile-help">Google и Discord автоматически находят аккаунт по совпадающей подтверждённой почте, когда провайдер подтверждает владение адресом. Если адреса различаются или аккаунт создан только по логину, подключи второй сервис здесь — прогресс останется в этом аккаунте.</p>
@include('components.legal-checkboxes', ['prefix'=>'account-link', 'purpose'=>'Для подключения способов входа'])
<div class="account-actions"><button class="button" type="button" data-oauth="google" disabled>Подключить Google</button><button class="button" type="button" data-oauth="discord" disabled>Подключить Discord</button></div><p class="profile-help" id="account-connections-status"></p></section>
  <section class="account-settings" id="account-email" hidden aria-labelledby="account-email-title"><h2 id="account-email-title">E-mail для связи аккаунтов</h2><p class="profile-help">Подтверди почту этого аккаунта. При входе через Google или Discord с тем же подтверждённым адресом сохранятся твой аккаунт и прогресс. Для внешней почты Google может понадобиться подключить сервис из кабинета.</p><p id="account-email-status" role="status" aria-live="polite">Загружаем почту…</p><form id="account-email-form" method="post" action="/api/auth/email/send"><label class="profile-field">E-mail<input type="email" name="email" required maxlength="254" autocomplete="email"></label><button class="button" type="submit" id="account-email-send" disabled>Отправить код</button></form><form id="account-email-code-form" method="post" action="/api/auth/email/verify" hidden><label class="profile-field">Код из письма<input name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" minlength="8" maxlength="8" aria-describedby="account-email-code-help"></label><p class="profile-help" id="account-email-code-help">8 цифр, срок действия — 30 минут. Проверь также папку «Спам».</p><button class="button primary" type="submit" id="account-email-confirm">Подтвердить e-mail</button></form></section>
  <noscript><p>Для входа и отображения личного прогресса включи JavaScript.</p></noscript>
  <p class="profile-help"><a class="account-link" href="privacy.html">Как используются данные профиля</a></p>
</main>
<script type="module" src="bootstrap.mjs?v=lazy-2"></script>
@endsection
