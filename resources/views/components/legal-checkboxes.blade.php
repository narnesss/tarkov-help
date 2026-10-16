<fieldset class="legal-checkboxes" id="{{ $prefix }}-legal" hidden disabled>
<legend>{{ $purpose ?? 'Документы и согласия' }}</legend>
<p class="profile-help">Прочитай документы и отметь каждое подтверждение самостоятельно. Галочки не установлены заранее.</p>
<label><input type="checkbox" name="legal_terms" aria-describedby="{{ $prefix }}-legal-error" required> <span>Принимаю <a href="/terms" target="_blank" rel="noopener">условия использования</a>.</span></label>
<label><input type="checkbox" name="legal_privacy" aria-describedby="{{ $prefix }}-legal-error" required> <span>Ознакомлен(а) с <a href="/privacy" target="_blank" rel="noopener">политикой конфиденциальности</a>.</span></label>
<label><input type="checkbox" name="legal_personal_data" aria-describedby="{{ $prefix }}-legal-error" required> <span>Даю отдельное <a href="/personal-data" target="_blank" rel="noopener">согласие на обработку персональных данных</a> для создания и работы аккаунта.</span></label>
<p class="profile-help">О служебных cookie и локальных настройках — на <a href="/cookies" target="_blank" rel="noopener">отдельной странице</a>. Согласие на рекламу, рассылки или публикацию данных здесь не запрашивается.</p>
<p class="profile-field-error" id="{{ $prefix }}-legal-error" data-legal-error role="alert" hidden></p>
</fieldset>
