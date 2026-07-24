# AI Builder → converged into the workbench sidecar

Fold `controls/Aibuilder.php` (1242 lines, 25 methods) + `views/aibuilder/*` into THIS sidecar
so there's ONE "build" sidecar: task board (Workbench) + AI Builder (terminal, plan pipeline,
provisioning UI). Copied in verbatim (core's `/aibuilder` stays live until cutover).

## The write audit (why "core" was a scapegoat)
Every `R::store/trash` in Aibuilder, categorized:

| Category | Writes | Home |
|---|---|---|
| **A. Workbench data** | plan listing, `planapprove`, `planrun`, `planstatus`, delete-cleanup of `tasklog`/`taskcomment`/`tasksnapshot` — all `workbenchtask` (+children) | **per-instance `workbench.db` via the selector** (same as the board). Currently writes core = LEGACY + a live divergence bug since the migration. |
| **B. Instance registry** | `create`/`fork`/`delete`/`share` → `dispense/store/trash('instance')` + member-ownership link + `sharedTeamList` | **core** — irreducibly central: it's the authz source of truth `Sidecar\Access` reads; the instance being created doesn't exist yet. ~5 admin-gated ops. |
| **C. Connector custody** | `connections` (`lastUsedAt`, delete-cleanup) | **core** — Tier-3 broker custody by design. |

So the "sidecar writes core" problem is NOT the whole controller — it's category B+C only (~5 ops).
The bulk (plan pipeline, browse, checkpoints=git, uploads, terminal) is selector/read/SSO — like Workbench.

## Convergence plan (one foundation, two facets)
1. **Shared build-controller base** — extract Workbench's ctor plumbing (SSO member via `Sso::session()`
   + core `member` row; `WorkbenchAccess`; `resolveSelected()` + `WorkbenchDb::select` + `putenv
   TIKNIX_WORKBENCH_DB`; sidecar `render()` override) into a base both `Workbench` and `Aibuilder` extend.
   Do this WITHOUT breaking live Workbench (move its logic to the base, Workbench extends).
2. **Aibuilder access helpers → WorkbenchAccess.** Map:
   - `accessibleInstance($id)` → `WorkbenchAccess::instanceMeta($id)` (null if not accessible)
   - `ownedInstance($id)` → instanceMeta + `ownsInstance()`
   - `isInstanceOwner($inst)` → `ownsInstance()`
   - `teamIdsForInstance()` → add to WorkbenchAccess (reads core `instance_team` via the PDO)
   Aibuilder currently reads instance BEANS it mutates; for read paths use `instanceMeta` (object).
3. **Category A (plan pipeline) → selector.** planapprove/planrun/planstatus/plan listing + delete-cleanup
   run against the selected instance's `workbench.db` (ctor already selects it + sets the env, so spawned
   PlanRunner/plan-ingest write there too — fixes the divergence bug).
4. **Category B+C (the ~5-op write-seam) — DECISION PENDING.** Options:
   - (recommended) thin **core provisioning API**: a small core controller owns create/fork/delete/share
     (the `instance` writes + capricorn shell-outs + broker key mint); the sidecar calls it server-to-server
     (authenticated). Writes/custody stay in core = north-star-clean. Only ~5 endpoints.
   - or a scoped RW handle to core just for these ops (fast; breaks "sidecar never writes core").
5. **Terminal.** `mintToken(slug, memberId)` HMAC — read the bridge secret from core's `conf/aibuilder.ini`
   (via `core_root`); point the xterm at CORE's node-runner wss (`wss://tiknix.com/aibuilder/ws`), not
   `location.host`. PTY/jail stays on core where the instance lives. (OpenSwoole PHP rewrite of the node
   bridge = deferred, owner's call.)
6. **Nav/route.** Route `aibuilder` wired in `public/index.php`. AI Builder becomes a section within the
   workbench sidecar (feature `workbench`) — no separate domain/feature.

## Status
- DONE: copied controller+views; wired `aibuilder` route; **shared `BuildControl` base** (SSO member +
  WorkbenchAccess + selector + render + level helpers); **Workbench migrated to it** (verified, fixed the
  TIKNIX_WORKBENCH_DB env mismatch); **Aibuilder migrated** — extends BuildControl, ctor→base, resolveSelected
  override (?id=instance / ?plan=task), access helpers (owned/accessible/isOwner/teamIds) → WorkbenchAccess,
  `renderHome` → accessibleInstances+instanceMeta, `cfg()` reads CORE aibuilder.ini (terminal secret),
  Flight::hasLevel → $this->hasLevel. WorkbenchAccess::instanceMeta now returns ALL columns camelCased +
  teamIdsForInstance. Lints clean.
- DONE: **plan pipeline → selector.** The base ctor selects the instance workbench.db per request (?id=instance,
  ?plan=workbenchtask→findTaskInstance), so plan/plansave/plangenerate/planstatus/planapprove/planrun/planprogress
  + ownedPlan run their workbenchtask R:: ops against that db (no code change needed — R:: facade follows the
  selection). Fixed planrun: run-orchestrator.sh now exports TIKNIX_WORKBENCH_DB so plan-orchestrate + its
  per-task agents write there too (was the divergence bug). Verified: ?id=6→R:: on bidsurge workbench.db + env set;
  ?plan=1→self-locates bidsurge.
- NEXT: (a) prove the Aibuilder PAGE renders (member+instance) + terminal wsBase → CORE host in the view;
  (b) the B+C WRITE-SEAM: create/fork/delete/share (6 core R:: ops + 2 hasLevel) — decide core-API vs RW.
