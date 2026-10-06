import { EDGE_ARROW_ID, lineColor } from './flow';

/**
 * Defines the edges' arrowhead: an open chevron with round ends, solid even on dashed lines, like
 * Shopify Flow's. ReactFlow's built-in arrow is too thin at this size. Render once inside each canvas.
 */
export function EdgeArrowDefs() {
  return (
    <svg aria-hidden className="pointer-events-none absolute size-0">
      <defs>
        {/* Points along the line (orient="auto"); the tip (11, 8) sits on the line's end. */}
        <marker
          id={EDGE_ARROW_ID}
          viewBox="0 0 16 16"
          markerWidth={16}
          markerHeight={16}
          refX={11}
          refY={8}
          orient="auto"
          markerUnits="userSpaceOnUse"
        >
          <path
            d="M5 3 L11 8 L5 13"
            fill="none"
            // A style, not the `stroke` attribute: attributes don't take color-mix().
            style={{ stroke: lineColor }}
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </marker>
      </defs>
    </svg>
  );
}
