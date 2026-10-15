/**
 * Shared TypeScript types for YayCommerce WordPress plugins.
 * Import via `@yaymatik/types` in any plugin scaffold.
 */

/** WordPress site settings localized by the PHP backend. */
export interface WpMeta {
  /** IANA timezone string, e.g. "America/New_York". Empty when using GMT offset. */
  timezoneString: string;
  /** GMT offset in hours, e.g. 5.5 for UTC+5:30. Used when timezoneString is empty. */
  gmtOffset: number;
  /** WordPress date format string, e.g. "F j, Y". */
  dateFormat?: string;
  /** WordPress time format string, e.g. "g:i a". */
  timeFormat?: string;
}

/** Standard shape of the localized meta object injected by wp_localize_script. */
export interface PluginMeta {
  /** WordPress site settings. */
  wp: WpMeta;
  /** URL of the plugin root (trailing slash). */
  pluginUrl?: string;
  /** WordPress nonce for REST API requests. */
  nonce?: string;
  /** Current user capabilities. */
  userCaps?: Record<string, boolean>;
}

/** Generic paginated REST API response shape. */
export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  totalPages: number;
  page: number;
  perPage: number;
}

/** WordPress REST API error shape. */
export interface WpRestError {
  code: string;
  message: string;
  data?: {
    status: number;
    params?: Record<string, string>;
  };
}

export * from './workflow';
