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
