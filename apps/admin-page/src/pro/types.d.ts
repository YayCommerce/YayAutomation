declare global {
  interface Window {
    /** PHP `Pro\Boot::localize()`. */
    yayMatikPro: {
      /** Pro features may run; without a license PHP registers no Pro step and the site runs as the free edition. */
      licensed: boolean;
    };
  }
}

export {};
