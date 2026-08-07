# Operations: supervising the workers

Asset Pilot is loop-safe and durable, but it is not self-running. Two long-lived Messenger consumers
and one recurring scheduler must stay up for the asynchronous pipeline to make progress. This page
gives drop-in reference configurations for systemd, Supervisor, and Kubernetes. See
[installation.md](installation.md) for the transport wiring and the reasoning behind each process.

## What must run

| Process | Command | Kind | Role |
|---------|---------|------|------|
| Async consumer | `messenger:consume asset_pilot` | long-lived daemon | Queued organization, bulk moves, actor-aware durable observer delivery |
| Maintenance consumer | `messenger:consume pimcore_maintenance` | long-lived daemon | Executes the maintenance-task messages the scheduler dispatches |
| Maintenance scheduler | `pimcore:maintenance` | recurring (cron/timer), never a daemon | Dispatches one message per task onto `pimcore_maintenance`: the organize-dispatch relay, durable-delivery outbox, audit/run retention, item-lease reconciliation, quarantine purge, storage snapshots, deletion-fence reaper, dependency reconcile |

Without the maintenance scheduler, a committed `pending_dispatch` organize run is never relayed and
automatic organization silently does nothing. Without the `asset_pilot` consumer, committed operations
never run their durable rule actions or emit operation events. The scheduler DISPATCHES; the
`pimcore_maintenance` consumer EXECUTES.

Everywhere below, `--time-limit=3600` recycles each worker hourly (bounding memory/connection drift)
and the process manager restarts it. Set the graceful-stop window (`TimeoutStopSec` / `stopwaitsecs` /
`terminationGracePeriodSeconds`) longer than the largest expected single operation so an in-flight move
finishes before the worker is killed; item leases and the reconciler recover anything that is
interrupted, but a clean stop avoids unnecessary retries.

## systemd

A single templated unit serves both consumers; the instance name is the transport.

```ini
# /etc/systemd/system/asset-pilot-worker@.service
[Unit]
Description=Asset Pilot Messenger consumer (%i)
After=network-online.target mariadb.service redis.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php bin/console messenger:consume %i --time-limit=3600 --memory-limit=256M --no-interaction
Restart=always
RestartSec=5
# Let an in-flight operation finish before SIGKILL; exceed the largest expected operation.
TimeoutStopSec=600
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now asset-pilot-worker@asset_pilot.service
systemctl enable --now asset-pilot-worker@pimcore_maintenance.service
```

Schedule `pimcore:maintenance` with a timer (a oneshot service will not overlap itself, so no `flock`
is needed):

```ini
# /etc/systemd/system/pimcore-maintenance.service
[Unit]
Description=Pimcore maintenance scheduler (dispatches maintenance-task messages)

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php bin/console pimcore:maintenance --no-interaction
```

```ini
# /etc/systemd/system/pimcore-maintenance.timer
[Unit]
Description=Run the Pimcore maintenance scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=10s
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
systemctl enable --now pimcore-maintenance.timer
```

Restart the consumers after every deploy so they load new code (`systemctl restart
'asset-pilot-worker@*'`).

## Supervisor

Supervisor manages the two daemons; the scheduler stays on cron.

```ini
# /etc/supervisor/conf.d/asset-pilot.conf
[program:asset-pilot-worker]
command=php /var/www/html/bin/console messenger:consume asset_pilot --time-limit=3600 --memory-limit=256M --no-interaction
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=600
stopsignal=TERM
stopasgroup=true
killasgroup=true

[program:asset-pilot-maintenance-worker]
command=php /var/www/html/bin/console messenger:consume pimcore_maintenance --time-limit=3600 --memory-limit=256M --no-interaction
user=www-data
numprocs=1
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=600
stopsignal=TERM
```

```cron
# The scheduler is a recurring command, not a Supervisor daemon.
* * * * * cd /var/www/html && flock -n /tmp/pimcore-maintenance.lock bin/console pimcore:maintenance --no-interaction
```

```bash
supervisorctl reread && supervisorctl update
# Restart BOTH consumer groups after each deploy so neither keeps running old code.
supervisorctl restart 'asset-pilot-worker:*' 'asset-pilot-maintenance-worker:*'
```

## Kubernetes

Two Deployments for the daemons and a CronJob for the scheduler. Replace `IMAGE` with your application
image (the one that already contains `bin/console`).

```yaml
# asset-pilot-workers.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: asset-pilot-worker
spec:
  replicas: 2
  selector:
    matchLabels: { app: asset-pilot-worker }
  template:
    metadata:
      labels: { app: asset-pilot-worker }
    spec:
      terminationGracePeriodSeconds: 600
      containers:
        - name: worker
          image: IMAGE
          command: ['bin/console', 'messenger:consume', 'asset_pilot', '--time-limit=3600', '--memory-limit=256M', '--no-interaction']
          resources:
            requests: { cpu: 100m, memory: 256Mi }
            limits: { memory: 512Mi }
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: asset-pilot-maintenance-worker
spec:
  replicas: 1
  selector:
    matchLabels: { app: asset-pilot-maintenance-worker }
  template:
    metadata:
      labels: { app: asset-pilot-maintenance-worker }
    spec:
      terminationGracePeriodSeconds: 600
      containers:
        - name: worker
          image: IMAGE
          command: ['bin/console', 'messenger:consume', 'pimcore_maintenance', '--time-limit=3600', '--memory-limit=256M', '--no-interaction']
          resources:
            requests: { cpu: 50m, memory: 256Mi }
            limits: { memory: 512Mi }
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: pimcore-maintenance
spec:
  schedule: '* * * * *'
  concurrencyPolicy: Forbid    # never overlap: replaces the flock used on cron
  successfulJobsHistoryLimit: 1
  failedJobsHistoryLimit: 3
  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: Never
          containers:
            - name: maintenance
              image: IMAGE
              command: ['bin/console', 'pimcore:maintenance', '--no-interaction']
```

```bash
kubectl apply -f asset-pilot-workers.yaml
kubectl rollout restart deployment/asset-pilot-worker            # after each deploy
kubectl rollout restart deployment/asset-pilot-maintenance-worker
```

## Verifying supervision

Each worker records a shared-cache heartbeat, and the health command turns critical when a required
consumer's heartbeat is missing or older than `async.worker_heartbeat_max_age`. Supervision is correct
when the health probe stays green across a worker restart, not just while a hand-started process runs.

```bash
bin/console asset-pilot:health              # async_transport must be OK, overall OK
bin/console messenger:stats asset_pilot asset_pilot_failed
```

`async_transport CRITICAL` with everything else healthy is most often an unsupervised or stopped
consumer, but the same check also reports critical when the `asset_pilot` transport is missing or cannot
be inspected, or when the message routing is not configured. Read the check's detail message to tell
these apart before assuming it is only supervision. Alert on the `asset_pilot_failed` depth and on queue
age, and inspect exhausted messages with
`bin/console messenger:failed:show --transport=asset_pilot_failed` where the receiver supports it.
