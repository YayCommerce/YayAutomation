/** PHP `SettingsRoutes`: `GET` / `PUT /settings`. */
export interface Settings {
  cartTracking: boolean;
  abandonMinutes: number;
  captureGuestEmail: boolean;
  cartRetentionDays: number;
  runRetentionDays: number;
  unsubscribeText: string;
}

export interface SettingsResponse extends Settings {
  /** The unsubscribe page's own text, shown while `unsubscribeText` is blank. Read-only. */
  unsubscribeDefault: string;
}

/** One entry of a `PUT /settings` 400's `data.errors`. */
export interface SettingsFieldError {
  field: keyof Settings;
  message: string;
}
