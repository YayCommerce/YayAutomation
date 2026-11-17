/** PHP `HealthRoutes`: `GET /health/checks`, `POST /health/fix`. A daily job the edition adds (PHP `DailyJobs`) is `<name>_job`. */
export type HealthCheckId =
  | 'runs_queued'
  | 'runs_running'
  | 'jobs_late'
  | 'cleanup_job'
  | 'missing_plugin'
  | `${string}_job`;

export type FixableHealthCheckId = Exclude<HealthCheckId, 'jobs_late' | 'missing_plugin'>;

export interface HealthCheck {
  id: HealthCheckId;
  status: 'ok' | 'problem' | 'info';
  count: number;
  runIds: number[];
  /** Null unless a problem; `jobs_late` never has one. */
  fix: 'enqueue' | 'recover' | 'schedule' | null;
  /** The scan read its limit of runs, so `count` is a lower bound. */
  capped?: boolean;
  /** `jobs_late` only: the server cron guide. */
  guide?: string;
  /** `missing_plugin` only: the active workflows with such a step, and the plugins' names. */
  workflows?: { id: string; name: string }[];
  plugins?: string[];
}

/** Another plugin's module, or one refused at registration. */
export interface HealthExtension {
  id: string;
  label: string;
  /** The Extension API version the module asks for; null when it gives none. */
  requiresApi: string | null;
  /** Last 24 hours, newest first; `count` is a lower bound. */
  errors: { callback: string; message: string; count: number; time: string }[];
}

export interface HealthChecksResponse {
  checks: HealthCheck[];
  extensions: HealthExtension[];
  checkedAt: string;
}

export interface HealthFixResponse {
  fixed: number;
  checks: HealthCheck[];
}
