/** Keep map canvases and drawers below the actual, potentially wrapped header. */
export function observeHeader() {
  const header = document.querySelector('.topbar'); if (!header) return;
  let previous = -1;
  const measure = () => {
    const height = Math.ceil(header.getBoundingClientRect().bottom);
    if (height === previous || height < 1) return;
    previous = height; document.documentElement.style.setProperty('--header-height', height + 'px');
    globalThis.dispatchEvent(new Event('resize'));
  };
  if (globalThis.ResizeObserver) new ResizeObserver(measure).observe(header);
  else globalThis.addEventListener('resize', () => { const h=Math.ceil(header.getBoundingClientRect().bottom);document.documentElement.style.setProperty('--header-height',h+'px'); });
  measure();
}
