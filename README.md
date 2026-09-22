![QUIQQER Blog](bin/images/Readme.jpg)

QUIQQER Cron (Recurring tasks)
==============================

The Cron-module extends QUIQQER by a cron manager.
The manager can schedule, manage and run recurring tasks.

Other plugins can use the cron modules API to provide their own recurring tasks.

Packagename:

    quiqqer/cron


Features
--------

- Cron-management
- Cron API


Installation
------------

```
composer require "quiqqer/cron" "dev-master"
```


CLI-only crons
--------------

Long-running crons can be restricted to command-line execution in `cron.xml`:

```xml
<cron exec="\Vendor\Package\Cron::execute" cliOnly="true">
    <!-- title and description -->
</cron>
```

If `cliOnly` is omitted or set to `false`, the cron remains available through
both CLI and web execution.

Machine-readable execution
--------------------------

From the QUIQQER installation directory:

```sh
php packages/quiqqer/cron/bin/cron-run.php --json --lock-mode=skip
php packages/quiqqer/cron/bin/cron-run.php --json --lock-mode=wait --lock-timeout=300
```

This optional CLI entrypoint returns versioned JSON and stable exit codes. Existing
CLI and HTTP callers keep their response behavior. Full cycles share a local process
lock; `--force` and `--unlock` can no longer bypass a running cycle.

See [the execution contract and migration notes](docs/cron-execution.md) for statuses,
counters, locking scope, and compatibility details.


Contribute
----------

- Source Code: https://dev.quiqqer.com/quiqqer/package-cron/tree/master
- Issue Tracker: https://dev.quiqqer.com/quiqqer/package-cron/issues


Support
-------

You can contact us by mail `support@pcsg.de `,
if you have encountered an error or want to express a wish or feature request.  
We will try to fulfill your wishes and will redirect them to the according developers.



License
-------
GPL-3.0+
