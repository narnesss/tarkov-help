// Keep custom map tooltips readable by mouse and keyboard, including moving
// the pointer from a marker onto the tooltip itself.
export function bindMapTooltip(marker, content) {
  marker.bindTooltip(content, {className:'raid-tooltip', direction:'auto', offset:[10,0], opacity:1, interactive:true});
  let leaveTimer;
  const cancel = () => clearTimeout(leaveTimer);
  const leave = () => {
    cancel();
    leaveTimer = setTimeout(() => {
      if (document.activeElement !== marker.getElement()) marker.closeTooltip();
    }, 180);
  };
  marker.off('mouseout', marker.closeTooltip);
  marker.on('mouseout', leave).on('mouseover', cancel).on('remove', cancel);
  marker.on('tooltipopen', () => {
    const tooltip = marker.getTooltip().getElement();
    tooltip.onmouseenter = cancel;
    tooltip.onmouseleave = leave;
    tooltip.style.translate = '';
    requestAnimationFrame(() => {
      if (!tooltip.isConnected) return;
      const box = tooltip.getBoundingClientRect(), frame = tooltip.closest('#raid-map')?.getBoundingClientRect();
      if (!frame) return;
      const left = Math.max(frame.left, 0) + 12, right = Math.min(frame.right, innerWidth) - 12;
      const shift = box.left < left ? left - box.left : box.right > right ? right - box.right : 0;
      tooltip.style.translate = `${shift}px 0`;
    });
  });
  return marker;
}
