const form = document.querySelector('.important-filters');
const search = form?.elements.namedItem('q');
if (form && search) {
  const error = document.getElementById('important-search-error');
  const validate = () => {
    const value = search.value.trim();
    const message = /[\u0000-\u001f\u007f]/u.test(value) ? 'Уберите управляющие символы из поиска.' : [...value].length > 160 ? 'Поиск ограничен 160 символами.' : '';
    search.setCustomValidity(message);
    search.classList.toggle('is-invalid', !!message);
    search.setAttribute('aria-invalid', String(!!message));
    error.textContent = message; error.hidden = !message;
    return !message;
  };
  search.addEventListener('input', validate);
  form.addEventListener('submit', event => {
    if (!validate()) { event.preventDefault(); search.reportValidity(); }
    else search.value = search.value.trim();
  });
}
