# Workspace extraction — status & adaptation guide

Phase C of `SIDECAR-ECOSYSTEM-PLAN.md`. Goal: move AI Projects (Workbench) out of the
instance clone into this sidecar, with per-instance task data owned here.

## Done
- Scaffold on the Sidecar Kit (public/index.php, controls/Sso.php, controls/Index.php).
- Registered in core: `[sidecar.workspace]` (feature=workspace, label "AI Projects").
- **Data model LOCKED** (plan Appendix): per-instance `{instanceDir}/data/workspace.db`,
  gitignored, sidecar-owned, selected via `lib/WorkspaceDb::select($instanceDir,$slug)`
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
   `WorkspaceDb::select(PipeFiles::instanceDir($inst), $inst['slug'])`. All Workbench
   R::/Bean:: then hit THAT instance's workspace.db. (Reuse core's `PipeFiles::instanceDir`.)
3. **Orchestration** — PlanRunner/PlanExecutor/tmux/ClaudeRunner spawned by Workbench write
   task logs/snapshots. The spawned process MUST `WorkspaceDb::select()` the SAME per-instance
   DB on bootstrap (pass instanceDir+slug through, select before first store). This is the one
   place the selector has to be threaded into a child process.
4. **Firehose reverse-dep** — core's `controls/Firehose.php` creates workbenchtask rows
   directly. After the move, task creation is the sidecar's job: Firehose must POST to the
   sidecar (or a shared server-to-server endpoint) instead of writing `workbenchtask` in core.
5. **Data migration** — split core's `workbenchtask` (+ taskcomment/tasklog/tasksnapshot) by
   `instance_id` into each `{instance}/data/workspace.db`. One-time script; fluid mode means
   no DDL, just dispense+store into the selected DB.
6. **Flip** — core nav `/workbench` → `/sidecar/app/workspace`; then `trim-instance.php`
   already lists `controls/Workbench.php`+`views/workbench` in its DROP set.

## Deploy (owner TODO)
- vhost + DNS for `workspace.tiknix.com`; php-fpm pool; `conf/config.ini` present (gitignored).

---

## Board slice DONE + PROVEN (2026-07-24)

The read path is built and verified end-to-end (CLI harness + render smoke against live
core, read-only):

- `lib/WorkspaceDb` — `select()` / `instanceDir()` / `selectInstance()` (RedBean per-instance
  selector; fluid auto-creates tables). **Proven:** two instances' `workspace.db` fully
  isolated, filters + counts correct.
- `lib/WorkspaceAccess` — instance-scoped replacement for core's `TaskAccessControl` with the
  SAME method surface the controller calls (`getVisibleTasks`/`getTaskCounts`/`getInstanceTags`/
  `can*`/`canAccessInstance`/`getAccessibleInstanceIds`). Identity/instances answered from core
  via `Sidecar\Access`; tasks from the selected `workspace.db`. `setCurrent()` selects the DB
  (self-consistent). **Proven:** accessible-instance scoping + per-instance tab counts + current
  restored after a multi-DB tab scan.
- `controls/Workbench.php` — constructor rewritten: no `parent::__construct()`; member from
  `Sso::session()` + core `member` row (`loadMember`); `WorkspaceAccess` instead of
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
  `WorkspaceDb::select` it before touching tasks. Right now only the constructor's default/`?inst`
  selection is wired, so a task-by-id action without `?inst` hits the default instance's DB.
- **Orchestration** children (PlanRunner/tmux) must `WorkspaceDb::select` the same per-instance DB.
- **Firehose reverse-dep** (core writes `workbenchtask` directly) → POST to the sidecar.
- **Data migration** — split core `workbenchtask`(+comment/log/snapshot) by `instance_id`.
- **Instances must gitignore `data/workspace.db`** (so an upgrade checkpoint never commits it).
- **Flip** nav + owner deploys `workspace.tiknix.com` vhost/DNS.
