/** PHP `Settings`: what the free edition saves. Abandon minutes isn't shown; the stored value keeps working. */
export const settingRanges = {
  abandonMinutes: null,
  cartRetentionDays: [7, 60] as [number, number],
  runRetentionDays: [7, 90] as [number, number],
};
