# Cron execution contract, version 1

Related: [quiqqer/cron#62](https://dev.quiqqer.com/quiqqer/cron/-/work_items/62)

## Machine CLI

From the QUIQQER installation directory, run:

```sh
php packages/quiqqer/cron/bin/cron-run.php --json --lock-mode=skip
php packages/quiqqer/cron/bin/cron-run.php --json --lock-mode=wait --lock-timeout=300
```

This is a non-interactive, CLI-only entrypoint executing as the system user, like the existing external
cron entrypoint. Use the installation's configured PHP version. It requires `proc_open` and a local
filesystem supporting `flock`. `--json` is optional: this entrypoint always returns JSON.
Options use `--name=value`. Unknown or duplicate options are rejected before bootstrap.

`skip` is the default. It returns immediately if another cycle owns the lock. `wait` retries until it
can acquire the same lock and then executes a cycle of its own, recalculating which jobs are due.
The default timeout is 300 seconds. CLI timeouts accept decimal seconds from 0 to 86400 inclusive;
zero permits one immediate acquisition attempt. The timeout bounds contention, not bootstrap,
filesystem I/O, or job execution. There is no option to bypass the lock.

Example of a partially failed cycle:

```json
{
  "contract_version": 1,
  "status": "completed_with_errors",
  "scheduled": 12,
  "executed": 11,
  "skipped": 0,
  "failed": 1,
  "started": true
}
```

stdout contains exactly one JSON document followed by a newline. Nonzero results also produce a short,
fixed diagnostic on stderr. Neither channel forwards exception text, job parameters, or arbitrary job
output. The parent starts a worker with stdout/stderr discarded and reads the bounded result through a
separate pipe. This also isolates bootstrap messages, warnings, direct `fwrite(STDOUT, ...)` calls,
and output from child commands. This is output isolation, not a sandbox for untrusted PHP jobs.

The parent verifies both the report and worker exit code. A worker that exits early, crashes, returns
invalid JSON, or cannot bootstrap produces `execution_failed`. When no trustworthy final report exists,
`started` is `null` and all counters are zero placeholders, not evidence that no side effects occurred.
As with other CLI programs, termination of the parent itself (for example SIGKILL) cannot yield a JSON
document; schedulers must also treat missing output or a signal exit as failure.

## Status and exit codes

| Status | Exit | Meaning |
| --- | ---: | --- |
| `executed` | 0 | Cycle completed without failed jobs, including no due jobs. |
| `completed_with_errors` | 1 | At least one job or schedule evaluation failed; other jobs were processed. |
| `invalid_arguments` | 2 | Invalid options; no cycle started. |
| `already_running` | 3 | `skip` found another owner; no cycle started. |
| `system_update_running` | 4 | An update was detected before processing; no cycle started. |
| `lock_timeout` | 5 | `wait` could not obtain the lock before its deadline. |
| `lock_failed` | 6 | Lock acquisition, compatibility-marker handling, or release failed. |
| `execution_failed` | 7 | Technical initialization/execution failure, or missing/invalid worker report. |
| `execution_interrupted` | 8 | Processing began, then an update or `stopAfterCurrentCron()` prevented remaining due jobs. |

These codes and meanings are stable within contract version 1. Only `executed` is successful.
Consumers should reject unsupported contract versions and treat unknown statuses as non-success.

Counters:

- `scheduled`: active, due candidates considered by this cycle. A candidate whose schedule/date
  cannot be evaluated is included and counted as failed, so corrupt schedules cannot produce success.
- `executed`: jobs whose callback and normal history update completed successfully.
- `skipped`: due candidates not executed, for example CLI-only jobs in a web cycle or remaining jobs
  after interruption. Inactive and not-yet-due jobs are not counted here.
- `failed`: schedule evaluation failures, non-callable jobs, or jobs throwing an exception or PHP error.
- `started`: `true` after the active list was loaded and processing began; `false` for known pre-start
  outcomes; `null` when the worker ended without a trustworthy report.

For a reported cycle, `scheduled = executed + skipped + failed`. Technical failures can leave a partial
inventory of candidates; these counts do not claim that every configured job was inspected. A job that
handles its own error and returns normally cannot be identified as failed by the manager. Callbacks
must throw to report a failure. A failing job may already have performed side effects: retries are not
an exactly-once guarantee.

## PHP API

```php
$Result = (new \QUI\Cron\Manager())->executeWithResult('wait', 300);
$status = $Result->status;
$exitCode = $Result->exitCode();
$json = json_encode($Result, JSON_THROW_ON_ERROR);
```

`ExecutionResult` is immutable and JSON-serializable. The existing `execute(bool $force = false): void`
and `executeCron(int $cronId): static` signatures remain available. `execute()` delegates to the same
cycle implementation, discards the structured result and preserves propagation of technical execution
exceptions. Lock contention and per-job failures still return normally to legacy callers.
The PHP API does not suppress callback output; use the machine CLI for isolated stdout/stderr.

## Local process locking

The approved implementation uses Symfony's `FlockStore` under `VAR_DIR/locks/`, with an installation-specific
key derived from `CMS_DIR`. CLI, HTTP, administration, and legacy full-cycle calls use the same lock.
Acquisition is atomic. Ownership is the non-serializable kernel file handle; another process or an old
lock object cannot release the current owner's lock. An unexpected process exit releases the lock.
Lock files must never be removed while workers may be running, including by cache cleanup or manual scripts.

**Approved deviation from #62:** this is a local process lock without TTL, rather than an expiring lease.
It remains held throughout a long or blocked callback, without a heartbeat thread, signal handler,
or cooperative changes to existing jobs. There is consequently no lease-renewal failure state to test.
The existing `cron_lock_time` setting remains the threshold for the long-run notification and the
legacy cache marker; expiration never authorizes another process to start a cycle.

All participating processes must address the same installation and local lock directory. This implementation
does not provide distributed locking across independent hosts/containers/filesystems. It deliberately
does not use a configured Redis/DBAL Core lock backend, because expiring leases cannot safely protect
arbitrary blocking callbacks without additional supervision. Distributed execution remains outside this
approved scope. Jobs must not fork a long-lived process that inherits the lock handle.

`--force`/`execute(true)` keep their signatures but no longer bypass an active cycle. `--unlock` and
`Manager::unlockExecutionLock()` can clear an obsolete legacy cache marker only after acquiring the
process lock themselves. They refuse to remove a live owner's lock, even after its cache marker expired.
For a genuinely stuck cycle, terminate its owning process using normal operational procedures.

Single-job calls through `executeCron()` / `package:cron --cron` retain their existing semantics and
are not full cycles. They are outside the full-cycle exclusivity contract.

## Compatibility investigation and decision

The existing output/exit behavior is not documented as a machine contract in this package's README,
but there are known consumers. The following paths were inspected before implementation:

- `src/QUI/Cron/Console/ExecCrons.php`: `package:cron --run` prints human-readable progress and previously
  had no result distinction. Its output and exit behavior remain unchanged outside the safety changes above.
- `bin/cron.php`: returns HTTP 200 after a normal `execute()` return, including a skipped cycle. Its
  existing response behavior remains unchanged; this endpoint does not opt into the new contract.
- Core `src/QUI/System/Console.php`, `case 'cron'`: performs a legacy cache-lock precheck and exits 1
  when it finds an active marker. New cycles continue to publish that marker. The process lock is authoritative
  if the marker expires or is cleared; legacy exitcodes remain unsuitable for reliable scheduling.
- `ajax/execute.php`: delegates to `execute()` and therefore shares the new full-cycle lock.
- [DDEV's cron wrapper](https://dev.quiqqer.com/quiqqer/ddev/-/blob/main/.ddev/scripts/quiqqer-cron-run.php)
  includes the existing HTTP entrypoint and maps HTTP status >=400 to process exit 1.
- [DDEV's cron controller](https://dev.quiqqer.com/quiqqer/ddev/-/blob/main/.ddev/scripts/quiqqer-cron-control)
  additionally holds a run lock and records its success timestamp from that wrapper's exit status.

Decision: add the independent, optional machine entrypoint rather than changing legacy HTTP/CLI codes.
The existing console can emit output during bootstrap and terminal setup, before a package tool executes;
isolating the machine worker allows a reliable JSON channel without patching Core or changing job signatures.
No unknown external consumers were assumed absent. DDEV's wrapper, run lock, import gate and activation
logic remain unchanged. DDEV can adopt the new CLI contract in a separate, tested migration.

For deployment, stop scheduler entrypoints and let pre-upgrade cycles finish before replacing code,
then restart them. The compatibility cache marker is respected while valid, but cannot make concurrently
running old code atomic or prevent an old `--force` caller from bypassing its own legacy lock.

## Validation and review

Tests cover result counters, empty cycles, update checks before/after acquisition, mid-cycle interruption,
job errors, missing callables, initialization and lock failures, owner-only release, process death, actual
cross-process skip/wait/timeout, runs beyond the old TTL, recursive calls, and raw-output isolation.
Integration fixtures select only their own database rows; entrypoint contention tests hold the process
lock so installed jobs cannot run. The new Symfony Lock dependency is explicit and does not require
the recently added Core process-lock API.

Run `./tools/phpcs`, `./tools/phpstan`, and `./tools/phpunit` (the integration suite should pass twice).
Use the compatibility decision and the approved local-lock deviation above in the merge request description.
