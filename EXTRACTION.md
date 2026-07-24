# Workspace extraction — status & adaptation guide

Phase C of `SIDECAR-ECOSYSTEM-PLAN.md`. Goal: move AI Projects (Workbench) out of the
instance clone into this sidecar, with per-instance task data owned here.

## Done
- Scaffold on the Sidecar Kit (public/index.php, controls/Sso.php, controls/Index.php).
- Registered in core: `[sidecar.workbench]` (feature=workspace, label "AI Projects").
- **Data model LOCKED** (plan Appendix): per-instance `{instanceDir}/data/workbench.db`,
  gitignored, sidecar-owned, selected via `lib/WorkbenchDb::select($instanceDir,$slug)`
  (RedBean `addDatabase`/`selectDatabase`, fluid → tables auto-create). No R::/Bean:: rewrite.
- **Copied verbatim from core** (NOT yet adapted): `controls/Workbench.php` (3599 lines,
  36 methods), `views/workbench/*` (7 views). Core's `/workbench` remains LIVE — this is a
  copy; we flip nav only once the sidecar proves out.
- Routing wired: `workbench => Workbench` in the Kernel map + guard allowlist; `/` → `/workbench`.

## Remaining adaptation (the real build)
Follow the WORKING pattern in `../pipelines.tiknix/controls/Edit.php` — the sidecar does NOT
use core's session-based `$this->member`; it uses the SSO session explicitly:

1. **Auth** — replace every `$this->member` / core-session / `Flight::hasLevel()` use with:
   `$s = Sso::session()` (→ `member_id`,`email`); accessible instances from
   `new Access(Kernel::coreDb())->instances($s['member_id'])`. A slug is a lookup hint, never
   authorization — authorize the selected instance against that set every request (see
   `Edit::guard()`).
2. **Per-instance DB** — at the top of each action, after resolving `$inst`:
   `WorkbenchDb::select(PipeFiles::instanceDir($inst), $inst['slug'])`. All Workbench
   R::/Bean:: then hit THAT instance's workbench.db. (Reuse core's `PipeFiles::instanceDir`.)
3. **Orchestration** — PlanRunner/PlanExecutor/tmux/ClaudeRunner spawned by Workbench write
   task logs/snapshots. The spawned process MUST `WorkbenchDb::select()` the SAME per-instance
   DB on bootstrap (pass instanceDir+slug through, select before first store). This is the one
   place the selector has to be threaded into a child process.
4. **Firehose reverse-dep** — core's `controls/Firehose.php` creates workbenchtask rows
   directly. After the move, task creation is the sidecar's job: Firehose must POST to the
   sidecar (or a shared server-to-server endpoint) instead of writing `workbenchtask` in core.
5. **Data migration** — split core's `workbenchtask` (+ taskcomment/tasklog/tasksnapshot) by
   `instance_id` into each `{instance}/data/workbench.db`. One-time script; fluid mode means
   no DDL, just dispense+store into the selected DB.
6. **Flip** — core nav `/workbench` → `/sidecar/app/workspace`; then `trim-instance.php`
   already lists `controls/Workbench.php`+`views/workbench` in its DROP set.

## Deploy (owner TODO)
- vhost + DNS for `workbench.tiknix.com`; php-fpm pool; `conf/config.ini` present (gitignored).

---

## Board slice DONE + PROVEN (2026-07-24)

The read path is built and verified end-to-end (CLI harness + render smoke against live
core, read-only):

- `lib/WorkbenchDb` — `select()` / `instanceDir()` / `selectInstance()` (RedBean per-instance
  selector; fluid auto-creates tables). **Proven:** two instances' `workbench.db` fully
  isolated, filters + counts correct.
- `lib/WorkbenchAccess` — instance-scoped replacement for core's `TaskAccessControl` with the
  SAME method surface the controller calls (`getVisibleTasks`/`getTaskCounts`/`getInstanceTags`/
  `can*`/`canAccessInstance`/`getAccessibleInstanceIds`). Identity/instances answered from core
  via `Sidecar\Access`; tasks from the selected `workbench.db`. `setCurrent()` selects the DB
  (self-consistent). **Proven:** accessible-instance scoping + per-instance tab counts + current
  restored after a multi-DB tab scan.
- `controls/Workbench.php` — constructor rewritten: no `parent::__construct()`; member from
  `Sso::session()` + core `member` row (`loadMember`); `WorkbenchAccess` instead of
  `TaskAccessControl`; `resolveSelected()` picks the instance from `?instance_tag`/`?inst` (or
  first accessible) and selects its DB. Overrides `requireLogin()` (SSO gate) and `render()`
  (lean sidecar layout). `index()` patched: `Flight::hasLevel`→`member->level`, raw
  `Bean::find('instance')`→`accessibleInstances()`.
- `views/layouts/sidecar.php` — iframe shell (Bootstrap/icons/jQuery via CDN, postMessage height).
- **Render smoke:** member 1 → 13.7KB board, title "AI Projects", status tabs, all three
  instance tabs (bidsurge/mileage/core), New Task button, no fatal.

### Still TODO (unchanged, minus the board)
- **The other 35 methods** (create/store/view/run/decompose/…): apply the same pattern — each
  action must resolve its instance (thread `?inst=<slug>` through the views' links/forms) and
  `WorkbenchDb::select` it before touching tasks. Right now only the constructor's default/`?inst`
  selection is wired, so a task-by-id action without `?inst` hits the default instance's DB.
- **Orchestration** children (PlanRunner/tmux) must `WorkbenchDb::select` the same per-instance DB.
- **Firehose reverse-dep** (core writes `workbenchtask` directly) → POST to the sidecar.
- **Data migration** — split core `workbenchtask`(+comment/log/snapshot) by `instance_id`.
- **Instances must gitignore `data/workbench.db`** (so an upgrade checkpoint never commits it).
- **Flip** nav + owner deploys `workbench.tiknix.com` vhost/DNS.

---

## CRUD write-path DONE + PROVEN (2026-07-24, session 2)

The read+write CRUD core is built and integration-tested against live core (read-only for
identity; per-instance workbench.db for tasks; all seeded data cleaned up):

- **`WorkbenchAccess::instanceMeta($id)`** — core-backed camelCase instance object (replaces
  `Bean::load('instance')`, which is wrong in the sidecar — that table isn't in workbench.db).
  Access-gated. ALL 10 `Bean::load/find('instance')` sites converted (CRUD + orchestration).
- **`WorkbenchAccess::findTaskInstance($taskId)`** — scans accessible instances' workbench.db
  for a task id. Powers the self-locating resolver so existing task links work unchanged.
- **`resolveSelected()`** now resolves the target instance in priority order:
  `?inst`/`?instance_tag` → `?instance_id` (store/create) → self-locate by task `?id` → first
  accessible. So a task action needs NO `?inst` in its link, and a new task lands in the
  chosen instance's DB (not the default).
- **Proven:** `view()` with only `?id=` self-locates the right instance (43KB, task rendered);
  `create()` renders the instance picker; `store()` (POST + CSRF) writes the new task to the
  CHOSEN instance's workbench.db and does NOT leak into another accessible instance.

update/delete/comment are POST handlers on the same machinery (self-locate by task id + CSRF +
instanceMeta) — structurally equivalent; verify live once the vhost is up.

### Still TODO
- **Orchestration methods** (run/rerun/decompose/plan*/consolidate/startserver/stopserver/
  console/progress): instance loads are converted, but the SPAWNED children (PlanRunner/
  PlanExecutor/tmux/ClaudeRunner) must `WorkbenchDb::select` the same per-instance DB on
  bootstrap, and they read more instance fields (port/engine/status) than instanceMeta carries.
- Firehose reverse-dep → POST to sidecar. Data migration (split core workbenchtask by
  instance_id). Instances gitignore `data/workbench.db`. Nav flip + workbench.tiknix.com vhost.

---

## Orchestration DB-routing DONE + PROVEN (2026-07-24, session 3)

The hard part — making spawned children write task state to the per-instance workbench.db —
is solved with ONE inert keystone + env propagation (no rewrite of the runners' logic):

- **Keystone (core `bootstrap.php`):** honors `TIKNIX_WORKBENCH_DB` — if set, `R::addDatabase('ws')`
  + `selectDatabase('ws')` + fluid. **INERT for core + normal instances** (env unset). Proven:
  inert when unset (core reads normally), redirects writes when set, core db uncontaminated.
- **Runner propagation (core `lib/PlanRunner`, `lib/ClaudeRunner`):** each exports
  `TIKNIX_WORKBENCH_DB` into its generated child script IFF the env is set in its own process.
  Proven via reflection: export present when set, absent when unset — inert for core's /workbench.
- **Sidecar (`controls/Workbench` ctor):** `putenv(TIKNIX_WORKBENCH_DB = WorkbenchDb::path(selected))`
  once an instance is resolved. `startOrchestrator()` also writes the export into run-orchestrator.sh.
- **Coverage:** the authoritative CLI writers all `require` core bootstrap and inherit the env →
  `plan-ingest.php` (decomposed plan), `cli/task-complete.php` (status + tasklog on completion),
  `plan-orchestrate.php` + its per-task agents. All land in the instance's workbench.db.

### Residual RESOLVED (core 24302d0)
- **Live progress stream** now lands in the workbench.db: `ClaudeRunner::getHookUrl` targets the
  INSTANCE own /mcp/message in sidecar regime (env set) instead of core localhost:8080, and
  the workbench MCP tools (`AddTaskLog`/`UpdateTask`/`CompleteTask`/`UploadScreenshot`/`AskQuestion`)
  `selectWorkbenchDb()` the instance data/workbench.db when `!is_control_plane()`. INERT for core
  (env unset -> localhost:8080; control-plane -> ambient core db). Both proven. Kills the core coupling.
- ~~**Live progress stream:**~~ ClaudeRunner sets `TIKNIX_HOOK_URL → localhost:8080/mcp/message`
  (core web). In-run progress hooks POST there and write CORE's db, so live tasklog streaming
  won't appear in the sidecar's workbench.db view (final state via task-complete.php IS correct).
  Fix later: make the progress hook instance-aware (carry slug/ws path → select workbench.db),
  or point the hook at the sidecar.
- Firehose reverse-dep → POST to sidecar. Data migration (split core workbenchtask by instance_id).
  Instances gitignore `data/workbench.db`. Nav flip + workbench.tiknix.com vhost.
