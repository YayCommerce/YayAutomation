import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react';
import { healthJobs } from '@edition/health';
import type {
  FixableHealthCheckId,
  HealthCheck,
  HealthCheckId,
  HealthChecksResponse,
  HealthExtension,
} from '@yaymatik/types';
import { CircleAlert, CircleCheck, ExternalLink, Info, RefreshCw, TriangleAlert } from 'lucide-react';
import { Link } from 'react-router';
import { humanTimeDiff } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { LoadingButton } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { errorMessage } from '@/routes/workflows/api/workflow.api';
import { useFixHealthCheck, useHealthQuery } from './api/health.query';
import { SettingsErrorAlert } from './SettingsErrorAlert';

/** Problems first, then the info row. */
const ORDER: HealthCheckId[] = [
  'runs_queued',
  'runs_running',
  'cleanup_job',
  ...(Object.keys(healthJobs) as HealthCheckId[]),
  'missing_plugin',
  'needs_pro',
  'jobs_late',
];

/** A capped scan's count is a lower bound: "1+" still takes the plural. */
const countText = (check: HealthCheck) => (check.capped ? `${check.count}+` : String(check.count));
const pluralCount = (check: HealthCheck) => (check.capped ? Math.max(check.count, 2) : check.count);

/** A scan can answer in a few milliseconds; the button stays busy this long, so a click is seen. */
export const MIN_CHECK_MS = 600;

const shownChecks = (data: HealthChecksResponse | undefined) =>
  data ? ORDER.flatMap((id) => data.checks.filter((check) => check.id === id && check.status !== 'ok')) : [];

export function HealthPanel() {
  const { data, error, isFetching, refetch } = useHealthQuery();
  const shown = shownChecks(data);
  const [checking, setChecking] = useState(false);
  // The scan outlives a click: no toast or state update once the page is left.
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  const checkAgain = async () => {
    if (checking || isFetching) return;
    setChecking(true);
    const [result] = await Promise.all([refetch(), new Promise((resolve) => setTimeout(resolve, MIN_CHECK_MS))]);
    if (!mounted.current) return;
    setChecking(false);
    // A failed scan says so in the card.
    if (result.isError) return;
    const problems = (result.data?.checks ?? []).filter((check) => check.status === 'problem').length;
    if (problems) {
      toast.info(
        sprintf(
          /* translators: %d: number of problems */ _n('Found %d problem.', 'Found %d problems.', problems, 'yaymatik'),
          problems,
        ),
      );
    } else {
      toast.success(__('No problems found.', 'yaymatik'));
    }
  };
  // A failed scan hides the rows it replaced; a scan running again shows them until it ends.
  const failed = isFetching ? null : error;

  return (
    <Card>
      <CardHeader className="border-b">
        <CardTitle className="text-base">{__('Health check', 'yaymatik')}</CardTitle>
        {data && !failed ? (
          <CardDescription title={formatDateTime(data.checkedAt)}>{summary(shown, data.checkedAt)}</CardDescription>
        ) : (
          !failed && <Skeleton className="h-3 w-40" />
        )}
        <CardAction>
          <LoadingButton variant="outline" size="sm" loading={isFetching || checking} onClick={() => void checkAgain()}>
            <RefreshCw />
            {__('Check again', 'yaymatik')}
          </LoadingButton>
        </CardAction>
      </CardHeader>
      <CardContent>
        {failed ? (
          <SettingsErrorAlert
            message={sprintf(
              /* translators: %s: why it failed */ __('Couldn’t run the health check. %s', 'yaymatik'),
              failed.message,
            )}
            onRetry={() => refetch()}
          />
        ) : !data ? (
          <RowsSkeleton />
        ) : shown.length ? (
          <div className="flex flex-col gap-4">
            {shown.map((check, index) => (
              <Fragment key={check.id}>
                {index > 0 && <Separator />}
                {check.id === 'jobs_late' ? (
                  <LateJobsRow check={check} />
                ) : check.id === 'missing_plugin' ? (
                  <MissingPluginRow check={check} />
                ) : check.id === 'needs_pro' ? (
                  <NeedsProRow check={check} />
                ) : (
                  <FixRow check={check} id={check.id} />
                )}
              </Fragment>
            ))}
          </div>
        ) : (
          <Empty className="p-2">
            <EmptyHeader>
              <EmptyMedia className="bg-success/15 text-success-soft-foreground mb-0 size-11 rounded-full">
                <CircleCheck className="size-5" />
              </EmptyMedia>
              <EmptyTitle className="font-semibold">{__('No problems found', 'yaymatik')}</EmptyTitle>
              <EmptyDescription>
                {__(
                  'Runs are being queued and picked up as they should, and the daily cleanup is scheduled.',
                  'yaymatik',
                )}
              </EmptyDescription>
            </EmptyHeader>
          </Empty>
        )}
        {data && !failed && <ExtensionsBlock extensions={data.extensions} />}
      </CardContent>
    </Card>
  );
}

function summary(shown: HealthCheck[], checkedAt: string) {
  const time = humanTimeDiff(checkedAt);
  const problems = shown.filter((check) => check.status === 'problem').length;
  if (problems) {
    return sprintf(
      /* translators: 1: number of problems, 2: when, e.g. "5 minutes ago" */
      _n('%1$d problem · checked %2$s', '%1$d problems · checked %2$s', problems, 'yaymatik'),
      problems,
      time,
    );
  }
  if (shown.length) {
    return sprintf(/* translators: %s: when, e.g. "5 minutes ago" */ __('No problems · checked %s', 'yaymatik'), time);
  }
  return sprintf(/* translators: %s: when, e.g. "5 minutes ago" */ __('Checked %s', 'yaymatik'), time);
}

interface FixRowText {
  icon: ReactNode;
  title: (check: HealthCheck) => string;
  help: (check: HealthCheck) => string;
  fix: (check: HealthCheck) => string;
  button: string;
}

/** Core's fixable checks, each with its text; the edition's daily jobs share one wording. */
const coreRows: Record<'runs_queued' | 'runs_running' | 'cleanup_job', FixRowText> = {
  runs_queued: {
    icon: <TriangleAlert className="text-warning-soft-foreground" />,
    title: (check) =>
      sprintf(
        /* translators: %s: number of runs */
        _n('%s run is stuck before it starts', '%s runs are stuck before they start', pluralCount(check), 'yaymatik'),
        countText(check),
      ),
    help: (check) =>
      _n(
        'It is queued or waiting, but no background job will pick it up. This happens when queueing failed.',
        'They are queued or waiting, but no background job will pick them up. This happens when queueing failed.',
        pluralCount(check),
        'yaymatik',
      ),
    fix: (check) =>
      _n(
        'Fix: queue it again. It continues from where it stopped.',
        'Fix: queue them again. Each run continues from where it stopped.',
        pluralCount(check),
        'yaymatik',
      ),
    button: __('Queue them again', 'yaymatik'),
  },
  runs_running: {
    icon: <CircleAlert className="text-destructive" />,
    title: (check) =>
      sprintf(
        /* translators: %s: number of runs */
        _n(
          '%s run stopped in the middle of a step',
          '%s runs stopped in the middle of a step',
          pluralCount(check),
          'yaymatik',
        ),
        countText(check),
      ),
    help: (check) =>
      _n(
        'It is still marked running, but its recovery job died. Runs like this never resume on their own.',
        'They are still marked running, but their recovery jobs died. Runs like this never resume on their own.',
        pluralCount(check),
        'yaymatik',
      ),
    fix: (check) =>
      _n(
        'Fix: queue recovery again. The interrupted step fails; it never runs twice.',
        'Fix: queue recovery again. The interrupted steps fail; none runs twice.',
        pluralCount(check),
        'yaymatik',
      ),
    button: __('Queue recovery again', 'yaymatik'),
  },
  cleanup_job: {
    icon: <TriangleAlert className="text-warning-soft-foreground" />,
    title: () => __('The daily cleanup isn’t running on time', 'yaymatik'),
    help: () => __('It’s missing or more than an hour late, so old runs and carts aren’t deleted on time.', 'yaymatik'),
    fix: () => __('Fix: schedule it again.', 'yaymatik'),
    button: __('Schedule again', 'yaymatik'),
  },
};

const fixRows: Record<string, FixRowText> = {
  ...coreRows,
  ...Object.fromEntries(
    Object.entries(healthJobs).map(([id, job]): [string, FixRowText] => [
      id,
      {
        icon: <TriangleAlert className="text-warning-soft-foreground" />,
        title: job.title,
        help: job.help,
        fix: () => __('Fix: schedule it again.', 'yaymatik'),
        button: __('Schedule again', 'yaymatik'),
      },
    ]),
  ),
};

function fixedMessage(id: FixableHealthCheckId, fixed: number) {
  if (id === 'cleanup_job') return __('The daily cleanup is scheduled again.', 'yaymatik');
  if (healthJobs[id]) return healthJobs[id].fixed();
  return id === 'runs_queued'
    ? /* translators: %d: number of runs */
      sprintf(_n('%d run queued again.', '%d runs queued again.', fixed, 'yaymatik'), fixed)
    : /* translators: %d: number of runs */
      sprintf(_n('%d run queued for recovery.', '%d runs queued for recovery.', fixed, 'yaymatik'), fixed);
}

function FixRow({ check, id }: { check: HealthCheck; id: FixableHealthCheckId }) {
  const fix = useFixHealthCheck(id);
  const row = fixRows[id];
  // `mutateAsync`, not `mutate` callbacks: a fixed row unmounts, which drops those.
  const run = () =>
    fix.mutateAsync().then(
      ({ fixed }) =>
        fixed ? toast.success(fixedMessage(id, fixed)) : toast.info(__('Nothing to fix any more.', 'yaymatik')),
      (error) =>
        /* translators: %s: why it failed */
        toast.error(sprintf(__('Couldn’t fix it. %s', 'yaymatik'), errorMessage(error))),
    );

  return (
    <Row
      icon={row.icon}
      title={row.title(check)}
      help={row.help(check)}
      extra={<span className="text-muted-foreground-600 text-sm">{row.fix(check)}</span>}
      action={
        <LoadingButton variant="outline" size="sm" loading={fix.isPending} onClick={() => !fix.isPending && run()}>
          {row.button}
        </LoadingButton>
      }
    />
  );
}

function LateJobsRow({ check }: { check: HealthCheck }) {
  return (
    <Row
      icon={<Info className="text-link" />}
      title={sprintf(
        /* translators: %d: number of jobs */
        _n('%d background job is late', '%d background jobs are late', check.count, 'yaymatik'),
        check.count,
      )}
      help={__(
        'They were due more than 15 minutes ago. WP-Cron only runs when someone visits the site, so a quiet store runs jobs late.',
        'yaymatik',
      )}
      extra={
        check.guide && (
          <a
            href={check.guide}
            target="_blank"
            rel="noopener"
            className="text-link inline-flex w-fit items-center gap-1 text-sm font-medium hover:underline"
          >
            {__('How to run WP-Cron from the server', 'yaymatik')}
            <ExternalLink className="size-3.5" aria-hidden />
          </a>
        )
      }
    />
  );
}

/** Active workflows that use a plugin that's off: no fix here, the store owner turns the plugin back on. */
function MissingPluginRow({ check }: { check: HealthCheck }) {
  return (
    <Row
      icon={<TriangleAlert className="text-warning-accent" />}
      title={sprintf(
        /* translators: %d: number of workflows */
        _n(
          'Active workflows use a plugin that isn’t active: %d',
          'Active workflows use a plugin that isn’t active: %d',
          check.count,
          'yaymatik',
        ),
        check.count,
      )}
      help={sprintf(
        /* translators: %s: plugin names, e.g. "YayMail" */
        __(
          'Their runs skip the steps of %s, and one whose trigger is from it doesn’t start. Activate it to run them again.',
          'yaymatik',
        ),
        (check.plugins ?? []).join(', '),
      )}
      extra={
        <span className="flex flex-wrap gap-x-3 gap-y-1 text-sm">
          {(check.workflows ?? []).map(({ id, name }) => (
            <Link key={id} to={`/workflows/${id}`} className="text-link font-medium hover:underline">
              {name || /* translators: %s: workflow id */ sprintf(__('Workflow #%s', 'yaymatik'), id)}
            </Link>
          ))}
          <a href={`${window.yayMatikMeta.wp.adminUrl}plugins.php`} className="text-link font-medium hover:underline">
            {__('Plugins', 'yaymatik')}
          </a>
        </span>
      }
    />
  );
}

/** Active workflows that use YayMatik Pro while it isn't here: they don't start. */
function NeedsProRow({ check }: { check: HealthCheck }) {
  return (
    <Row
      icon={<TriangleAlert className="text-warning-accent" />}
      title={sprintf(
        /* translators: %d: number of workflows */
        _n('Active workflows need YayMatik Pro: %d', 'Active workflows need YayMatik Pro: %d', check.count, 'yaymatik'),
        check.count,
      )}
      help={__('They don’t start until YayMatik Pro is active. Nothing in them was changed.', 'yaymatik')}
      extra={
        <span className="flex flex-wrap gap-x-3 gap-y-1 text-sm">
          {(check.workflows ?? []).map(({ id, name }) => (
            <Link key={id} to={`/workflows/${id}`} className="text-link font-medium hover:underline">
              {name || /* translators: %s: workflow id */ sprintf(__('Workflow #%s', 'yaymatik'), id)}
            </Link>
          ))}
        </span>
      }
    />
  );
}

/** Other plugins' modules: core's own are never listed. */
function ExtensionsBlock({ extensions }: { extensions: HealthExtension[] }) {
  return (
    <section aria-label={__('Extensions', 'yaymatik')} className="mt-4 flex flex-col gap-3 border-t pt-4">
      <h3 className="text-sm font-medium">{__('Extensions', 'yaymatik')}</h3>
      {extensions.length ? (
        extensions.map((extension) => <ExtensionRow key={extension.id} extension={extension} />)
      ) : (
        <p className="text-muted-foreground text-sm">{__('No other plugin adds to YayMatik.', 'yaymatik')}</p>
      )}
    </section>
  );
}

function ExtensionRow({ extension }: { extension: HealthExtension }) {
  const name = extension.requiresApi
    ? sprintf(
        /* translators: 1: plugin name, 2: Extension API version, e.g. "1.0" */
        __('%1$s (needs Extension API %2$s)', 'yaymatik'),
        extension.label,
        extension.requiresApi,
      )
    : extension.label;
  return (
    <div className="flex gap-3 [&>svg]:mt-0.5 [&>svg]:size-4 [&>svg]:shrink-0" data-extension={extension.id}>
      {extension.errors.length ? (
        <CircleAlert className="text-destructive" />
      ) : (
        <CircleCheck className="text-success-soft-foreground" />
      )}
      <div className="flex min-w-0 flex-col gap-1">
        <span className="text-sm font-medium">{name}</span>
        {extension.errors.length ? (
          <ul className="flex flex-col gap-1">
            {extension.errors.map((error) => (
              <li key={`${error.callback}|${error.time}`} className="text-muted-foreground text-sm">
                <span className="text-foreground">{error.message}</span>{' '}
                {sprintf(
                  /* translators: 1: where it failed, e.g. "listen", 2: how many times, 3: when, e.g. "5 minutes ago" */
                  _n(
                    '(%1$s, at least %2$d time, last %3$s)',
                    '(%1$s, at least %2$d times, last %3$s)',
                    error.count,
                    'yaymatik',
                  ),
                  error.callback,
                  error.count,
                  humanTimeDiff(error.time),
                )}
              </li>
            ))}
          </ul>
        ) : (
          <span className="text-muted-foreground text-sm">{__('No problems', 'yaymatik')}</span>
        )}
      </div>
    </div>
  );
}

/** Below md the action goes under the text. */
function Row({
  icon,
  title,
  help,
  extra,
  action,
}: {
  icon: ReactNode;
  title: string;
  help: string;
  extra: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="flex gap-3 [&>svg]:mt-0.5 [&>svg]:size-4 [&>svg]:shrink-0">
      {icon}
      <div className="flex min-w-0 flex-1 flex-col items-start gap-3 md:flex-row md:justify-between">
        <div className="flex min-w-0 flex-col gap-1">
          <span className="text-sm font-semibold">{title}</span>
          <span className="text-muted-foreground text-sm">{help}</span>
          {extra}
        </div>
        {action}
      </div>
    </div>
  );
}

function RowsSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-hidden>
      {[0, 1].map((index) => (
        <Fragment key={index}>
          {index > 0 && <Separator />}
          <div className="flex flex-col gap-2">
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-[70%]" />
          </div>
        </Fragment>
      ))}
    </div>
  );
}
