/** PHP `Pro\Boot::register()` widens the save ranges; without a license they are the free edition's. */
export const settingRanges = window.yayMatikPro.licensed
  ? {
      abandonMinutes: [15, 1440] as [number, number],
      cartRetentionDays: [7, 365] as [number, number],
      runRetentionDays: [7, 730] as [number, number],
    }
  : {
      abandonMinutes: null,
      cartRetentionDays: [7, 60] as [number, number],
      runRetentionDays: [7, 90] as [number, number],
    };
