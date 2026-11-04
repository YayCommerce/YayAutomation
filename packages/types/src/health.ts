/** PHP `HealthRoutes`: `GET /health/checks`, `POST /health/fix`. */
export type HealthCheckId = 'runs_queued' | 'runs_running' | 'jobs_late' | 'cleanup_job' | 'inactive_sweep_job';

export type FixableHealthCheckId = Exclude<HealthCheckId, 'jobs_late'>;

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
}

export interface HealthChecksResponse {
  checks: HealthCheck[];
  checkedAt: string;
}

export interface HealthFixResponse {
  fixed: number;
  checks: HealthCheck[];
}
