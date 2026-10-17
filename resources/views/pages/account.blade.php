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
  <section class="account-settings" id="account-settings" hidden aria-labelledby="account-settings-title">
    <h2 id="account-settings-title">Твой профиль</h2>
    <p class="profile-help">Имя и аватар сохраняются при любом способе входа. Дополнительные сведения необязательны и видны только тебе. Телефон не подтверждён и не используется для входа или связывания аккаунтов.</p>
    <dl class="account-profile-facts">
      <div><dt>E-mail</dt><dd id="account-contact-email">Загрузка…</dd></div>
      <div><dt>Дата регистрации</dt><dd id="account-registered">Загрузка…</dd></div>
      <div><dt>Последняя активность</dt><dd id="account-last-active">Загрузка…</dd></div>
    </dl>
    <p class="profile-help">E-mail берётся из подтверждённого контакта аккаунта. Изменить его здесь нельзя.</p>
    <form id="account-name-form" method="post" action="/api/auth/profile">
      <fieldset id="account-profile-fields" disabled>
        <legend class="account-profile-legend">Данные профиля</legend>
        <div class="account-profile-grid">
          <div><p id="account-display-name-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Отображаемое имя<input name="display_name" required maxlength="64" autocomplete="nickname" aria-describedby="account-name-help"></label><p class="profile-help" id="account-name-help">От 1 до 64 символов. Логин для входа не меняется.</p></div>
          <div><p id="account-phone-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Телефон<input type="tel" name="phone" maxlength="32" autocomplete="tel" placeholder="+7 999 123-45-67" aria-describedby="account-phone-help"></label><p class="profile-help" id="account-phone-help">Международный формат: + и от 7 до 15 цифр.</p></div>
          <div><p id="account-country-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Страна<input name="country" maxlength="80" autocomplete="country-name"></label></div>
          <div><p id="account-city-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Город<input name="city" maxlength="100" autocomplete="address-level2"></label></div>
          <div><p id="account-gender-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Пол<select name="gender"><option value="">Не указан</option><option value="male">Мужской</option><option value="female">Женский</option><option value="other">Другой</option></select></label></div>
          <div><p id="account-age-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Возраст<input type="number" name="age" min="1" max="120" step="1" inputmode="numeric"></label></div>
        </div>
        <div class="account-actions"><button class="button primary" type="submit">Сохранить профиль</button></div>
      </fieldset>
    </form>
    <div class="account-actions"><button class="button" id="account-password-reset" type="button" disabled>Сбросить пароль</button></div>
    <p class="profile-help" id="account-password-reset-help">Сброс через подтверждённый e-mail сохраняет аккаунт и прогресс. После смены пароля потребуется войти заново.</p>
  </section>
  <section class="account-auth-panel" id="account-auth" hidden aria-labelledby="account-auth-title">
    <div class="account-auth-copy"><span class="account-kicker">СОХРАНИ СВОЙ ПУТЬ</span><h2 id="account-auth-title">Продолжай с любого устройства</h2><p>Войди в аккаунт, чтобы твои отметки и заметки всегда были под рукой.</p><p class="profile-help">Прогресс гостя остаётся в его профиле. Перед созданием нового аккаунта скачай его, а после входа загрузи в свой аккаунт.</p><button class="button" type="button" data-profile-tools>Скачать или перенести профиль</button></div>
    <div class="account-auth-form">
      <div class="account-auth-tabs" role="group" aria-label="Вход или регистрация"><button type="button" data-auth-view="login" aria-pressed="true">Вход</button><button type="button" data-auth-view="register" aria-pressed="false">Регистрация</button></div>
      <p id="account-auth-feedback" role="status" aria-live="polite"></p>
      <div id="account-auth-login">
      <div class="account-social" id="account-providers"><button class="button social-google" type="button" data-oauth="google" disabled>Продолжить с Google</button><button class="button social-discord" type="button" data-oauth="discord" disabled>Продолжить с Discord</button></div>
      <p class="profile-help" id="account-provider-status">Проверяем доступные способы входа…</p>
      <div class="account-divider"><span>или по логину и паролю</span></div>
      <form id="account-form" method="post" action="/api/auth/login"><p id="account-username-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Логин<input name="username" autocomplete="username" required minlength="3" maxlength="32" aria-describedby="account-username-help"></label><p class="profile-help" id="account-username-help">От 3 до 32 букв или цифр, также можно использовать _ и дефис.</p><p id="account-password-error" class="profile-field-error" role="alert" hidden></p><label class="profile-field">Пароль<input name="password" type="password" autocomplete="current-password" required aria-describedby="account-password-help"></label><p class="profile-help" id="account-password-help" hidden>Минимум 12 символов (лучше 16 и больше), максимум 72 байта. Нужны заглавная и строчная латинские буквы, цифра и спецсимвол. Используй отдельный пароль для этого сайта, без популярных слов, дат, последовательностей и прежних паролей.</p><label class="profile-field" id="account-confirm-label" hidden>Повтори пароль<input name="confirmation" type="password" autocomplete="new-password"></label>@include('components.legal-checkboxes', ['prefix'=>'account-register', 'purpose'=>'Для регистрации'])
<button class="button primary" id="account-submit" type="submit">Войти</button></form>
      <button class="account-link account-forgot" type="button" id="account-forgot">Забыли пароль?</button>
      </div>
    </div>
  </section>
      <section class="account-settings" id="account-recovery" hidden aria-labelledby="account-recovery-title">
        <h3 id="account-recovery-title">Восстановление пароля</h3>
        <p class="profile-help">Код придёт на подтверждённую почту аккаунта. Восстановление сохранит аккаунт и прогресс.</p>
        <p id="account-recovery-feedback" role="status" aria-live="polite"></p>
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
        <p class="profile-help">Используй сохранённую подтверждённую почту. Также можно войти через подключённый Google или Discord. Прогресс другого гостевого профиля не переносится автоматически.</p>
        <button class="account-link account-forgot" type="button" id="account-recovery-back">Вернуться ко входу</button>
      </section>
  <section class="account-controls" aria-label="Настройки профиля"><div class="account-mode" role="group" aria-label="Игровой режим"><button type="button" data-account-mode="pve" aria-pressed="true">PVE</button><button type="button" data-account-mode="regular" aria-pressed="false">PVP</button></div><div class="account-actions"><button class="button" id="account-sync" type="button">Синхронизировать</button><button class="button" type="button" data-profile-tools>Управление профилем</button><button class="button" id="account-logout" type="button" hidden>Выйти</button></div></section>
  <section aria-labelledby="account-overview-title"><div class="account-section-heading"><div><span class="account-kicker" id="account-mode-label">ПРОГРЕСС PVE</span><h2 id="account-overview-title">Твой путь в Таркове</h2></div><span id="account-level"></span></div><div class="account-summary" id="account-summary" aria-live="polite"><p>Загружаем сводку…</p></div></section>
  <section class="account-detail-grid"><div class="account-detail"><h2>Активные квесты</h2><p class="profile-help">То, что ты уже начал проходить.</p><div id="account-active">Загрузка…</div></div><div class="account-detail"><h2>Убежище и достижения</h2><p class="profile-help">Отмеченные постройки и полученные награды.</p><div id="account-unlocks">Загрузка…</div></div></section>
  <section class="account-connections" id="account-connections" hidden aria-labelledby="account-connections-title"><h2 id="account-connections-title">Способы входа</h2><p class="profile-help">Google и Discord автоматически находят аккаунт по совпадающей подтверждённой почте, когда провайдер подтверждает владение адресом. Если адреса различаются или аккаунт создан только по логину, подключи второй сервис здесь — прогресс останется в этом аккаунте.</p>
<div class="account-actions"><button class="button" type="button" data-oauth="google" disabled>Подключить Google</button><button class="button" type="button" data-oauth="discord" disabled>Подключить Discord</button></div><p class="profile-help" id="account-connections-status"></p></section>
  <noscript><p>Для входа и отображения личного прогресса включи JavaScript.</p></noscript>
  <p class="profile-help"><a class="account-link" href="privacy.html">Как используются данные профиля</a></p>
</main>
<script type="module" src="bootstrap.mjs?v=profile-details-20261017"></script>
@endsection
