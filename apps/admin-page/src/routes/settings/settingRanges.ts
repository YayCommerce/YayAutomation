/** PHP `Settings::RANGES`: [ min, max ] each integer setting saves. */
export const settingRanges = {
  abandonMinutes: [15, 1440],
  cartRetentionDays: [7, 365],
  runRetentionDays: [7, 730],
} satisfies Record<string, [number, number]>;
