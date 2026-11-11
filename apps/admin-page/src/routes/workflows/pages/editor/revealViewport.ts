/**
 * The viewport that brings a node into the free area beside the step panel, axis by axis: an axis on which the
 * node already fits keeps its offset, so a node hidden only by the panel moves sideways and not up or down.
 * Null when the node is in view on both axes. Same zoom.
 */
export function revealViewport(
  node: { x: number; y: number; w: number; h: number },
  viewport: { x: number; y: number; zoom: number },
  area: { panelInset: number; width: number; height: number },
): { x: number; y: number; zoom: number } | null {
  const { zoom } = viewport;
  const left = node.x * zoom + viewport.x;
  const top = node.y * zoom + viewport.y;
  const fitsX = left >= area.panelInset && left + node.w * zoom <= area.width;
  const fitsY = top >= 0 && top + node.h * zoom <= area.height;
  if (fitsX && fitsY) return null;
  const centerX = area.panelInset + (area.width - area.panelInset) / 2;
  return {
    x: fitsX ? viewport.x : centerX - (node.x + node.w / 2) * zoom,
    y: fitsY ? viewport.y : area.height / 2 - (node.y + node.h / 2) * zoom,
    zoom,
  };
}
