export function createEditorMap(L, canvas, options = {}) {
  const map = L.map(canvas, {minZoom:-5, maxZoom:8, zoomControl:true, ...options});
  map.createPane('artwork').style.zIndex = 200;
  return map;
}

export function editorMarkerIcon(L, url, {selected=false, dirty=false, archived=false} = {}) {
  const span = document.createElement('span'), image = document.createElement('img');
  image.src = url; image.alt = ''; span.append(image);
  return L.divIcon({html:span, className:'me-marker'+(selected?' is-selected':'')+(dirty?' is-dirty':'')+(archived?' is-archived':''), iconSize:[44,44], iconAnchor:[22,22]});
}

export function editorListButton(url, title, {selected=false, dirty=false, archived=false} = {}) {
  const button = document.createElement('button'), image = document.createElement('img'), label = document.createElement('span');
  button.type = 'button'; image.src = url; image.alt = ''; label.textContent = title; button.append(image,label);
  button.classList.toggle('is-selected',selected); button.classList.toggle('is-dirty',dirty); button.classList.toggle('is-archived',archived);
  button.setAttribute('aria-pressed',String(selected));
  return button;
}
