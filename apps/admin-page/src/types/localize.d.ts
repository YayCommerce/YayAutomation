declare global {
  interface Window {
    yayAutomationMeta: {
      plugin: {
        pluginUrl: string;
        version: string;
      };
      wp: {
        restRoot: string;
        restBase: string;
        restNonce: string;
        siteUrl: string;
        adminUrl: string;
        timezoneString: string;
        gmtOffset: number;
        adminEmail: string;
      };
      wc: {
        active: boolean;
        /** Slugs without the `wc-` prefix. Empty when WooCommerce is inactive. */
        orderStatuses: { value: string; label: string }[];
        currencySymbol: string;
      };
      docs: {
        /** Filter `yay_automation_workflow_runs_docs_url`. */
        workflowRuns: string;
      };
    };
  }
}

export {};
