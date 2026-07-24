# AI Projects (Workbench) — tiknix sidecar

The AI Projects task board, extracted from core `controls/Workbench.php` into a
Sidecar-Kit app (Phase C of SIDECAR-ECOSYSTEM-PLAN.md).

Boots on core's `vendor/autoload` (the Sidecar Kit + shared classes). SSO'd via
`/sidecar/launch/workbench`. Reads core identity via `Kernel::coreDb()`.

**Open: data ownership.** Workbench read-*writes* control-plane tables
(workbenchtask/taskcomment/tasklog/tasksnapshot). The Kit convention is "read core,
write your own DB." Decision pending: own the task tables in this sidecar's DB
(clean, needs a one-time data move) vs. a trusted read-write handle to core's DB.
