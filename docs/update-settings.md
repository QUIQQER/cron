# Update settings

The three checkboxes under **System settings → Updates** read the cron activation state.
Changing a checkbox and saving activates or deactivates the corresponding cron; it does not
write a second configuration flag. Changes made in the cron manager appear when the settings
are opened again. Saving unchanged checkboxes does not overwrite changes made elsewhere.

New installations enable update checks and security/bug-fix (patch) updates. General automatic
updates start disabled. The existing security-update command selects patch versions rather
than filtering individual security advisories.

Package setup migrates existing installations once. An update check or general update remains
enabled only if both its cron and its previous configuration flag were enabled. Existing
security cron states, schedules and parameters are preserved. Missing jobs on an existing
installation are recreated inactive. The old `update.auto_check` and `update.auto_update`
configuration values are removed after migration.

If a job is missing when settings are saved, it is created with the selected activation state
and the schedule from `cron.xml`. If several schedules exist for one update task, the checkbox
is checked when any schedule is active; explicitly switching it applies to all those schedules.

Crons defined with `required` or `autocreate` are system crons. They can be activated,
deactivated and rescheduled, but cannot be deleted or changed into another task. Mixed deletion
requests containing a system cron are rejected before deleting any selected cron.

Run the checkbox tests with `node --test tests/javascript/update-setting.test.cjs` and the PHP
tests with `./tools/phpunit`.
