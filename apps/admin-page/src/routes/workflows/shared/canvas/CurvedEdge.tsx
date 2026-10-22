import { BaseEdge, type EdgeProps } from '@xyflow/react';

/** Straight run at each end, so lines leave and enter cards upright and the arrow sits on a straight piece. */
const LEAD = 14;

/**
 * Line between cards: straight down out of the card, an S-curve with vertical ends, then straight
 * down into the next card. Cards in one column get a plain vertical line. Dashes, animation, arrow
 * and label come from the edge's props as usual.
 */
function CurvedEdge({
  id,
  sourceX,
  sourceY,
  targetX,
  targetY,
  label,
  style,
  markerEnd,
  labelStyle,
  labelShowBg,
  labelBgStyle,
  labelBgPadding,
  labelBgBorderRadius,
}: EdgeProps) {
  const top = sourceY + LEAD;
  const bottom = targetY - LEAD;
  // Pull both control points straight up/down: the curve bends in the middle, not near the cards.
  const pull = Math.max((bottom - top) / 2, LEAD);
  const path = [
    `M ${sourceX},${sourceY}`,
    `L ${sourceX},${top}`,
    `C ${sourceX},${top + pull} ${targetX},${bottom - pull} ${targetX},${bottom}`,
    `L ${targetX},${targetY}`,
  ].join(' ');

  return (
    <BaseEdge
      id={id}
      path={path}
      style={style}
      markerEnd={markerEnd}
      label={label}
      labelX={(sourceX + targetX) / 2}
      labelY={(top + bottom) / 2}
      labelStyle={labelStyle}
      labelShowBg={labelShowBg}
      labelBgStyle={labelBgStyle}
      labelBgPadding={labelBgPadding}
      labelBgBorderRadius={labelBgBorderRadius}
    />
  );
}

/** Edge types for every workflow canvas; `toFlowEdges` uses `curved`. */
export const edgeTypes = { curved: CurvedEdge };
