---
title: View title h1s double-encoded the OIDC client name, rendering apostrophes as the literal &#39;
date: 2026-08-03
category: ui-bugs
module: Oa4mpClient plugin (view title h1s across controllers)
problem_type: ui_bug
component: rails_controller
related_components:
  - rails_view
severity: low
symptoms:
  - "An OIDC client named scott's confidential client rendered in an edit-page h1 as the literal Edit Claim for scott&#39;s confidential client (the HTML entity shown as text, not an apostrophe)"
  - "The OIDC clients index view rendered the same name correctly, so the corruption was specific to the title h1 render path"
root_cause: logic_error
resolution_type: code_fix
tags: [cakephp, comanage, html-encoding, double-encoding, filter-var, title-for-layout, oa4mp, view-escaping]
---

# View title h1s double-encoded the OIDC client name

## Problem

Edit/add/manage pages in the plugin build their page-title `<h1>` by embedding the OIDC client name, e.g. `Edit Claim for <name>`. For a client whose name contained an apostrophe (or any character `FILTER_SANITIZE_SPECIAL_CHARS` encodes), the h1 rendered the literal HTML entity — `Edit Claim for scott&#39;s confidential client` — instead of `scott's`.

## Symptoms

- Reported h1: `Edit Claim for scott&#39;s confidential client` for a client named `scott's confidential client`.
- The OIDC clients **index** view rendered the name correctly (`scott's`) — it prints the name through `$this->Html->link(...)`, which HTML-escapes exactly once.
- The defect was systemic: 11 sites across 8 controllers, every view that embeds the client name in `title_for_layout`.

## What Didn't Work

Three detours, all traceable to acting on approximate evidence instead of the exact rendered bytes:

1. **Fixed the wrong controller first.** The bug was reported as "the OIDC clients edit view" showing "Edit scott's confidential client", so the first fix targeted `Oa4mpClientCoOidcClientsController::edit()`. `md5sum` of the deployed controller confirmed the fix was live on the running pod, yet the symptom persisted. The actual h1 was `Edit Claim **for** scott&#39;s...` — the **Claims** edit view (`Oa4mpClientClaimsController`, title `Edit Claim for %s`), a different controller entirely. **Lesson: for a rendering bug, get the exact h1 string (ideally raw View-Source) before choosing which code path to edit.** The paraphrased title sent the fix to the wrong file.

2. **Chased opcache and cache-clearing as red herrings.** Because a confirmed-deployed fix didn't change the output, the investigation turned to PHP opcache serving stale bytecode, and `find tmp/cache/ -type f -delete` was tried. That deletes **CakePHP's application cache**, which is unrelated to PHP opcache — and neither was the cause. The real tell had already been produced: a matching `md5sum` (fix on disk) *plus* a persistent symptom means the running code is a **different code path**, not that the fix failed to take effect. Deployment/opcache is only a live hypothesis once you've confirmed you edited the file that actually renders the symptom.

3. **A single-line grep under-counted the sites.** `grep 'title_for_layout' | grep 'filter_var'` found only the sites where both tokens sat on one physical line, hiding every two-line call where `_txt(...)` wraps to put `filter_var(...)` on the next line. A multi-line-aware search (`grep -A2 title_for_layout | grep filter_var`) surfaced all 11 sites.

## Solution

Each controller pre-encoded the name with `filter_var($name, FILTER_SANITIZE_SPECIAL_CHARS)` before passing it to `_txt()` for `title_for_layout`. The COmanage core `pageTitleAndButtons` element (`app/View/Elements/pageTitleAndButtons.ctp`) then sanitizes the whole title **again** at render. Remove the redundant controller-side `filter_var` at every site so the name is escaped exactly once, matching COmanage core:

```php
// Before — double-encodes: scott's -> scott&#39;s (controller) -> scott&amp;#39;s (element)
$this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.edit.name',
           array(filter_var($client['Oa4mpClientCoOidcClient']['name'], FILTER_SANITIZE_SPECIAL_CHARS))));

// After — element escapes once: scott's -> scott&#39;s (rendered as scott's)
$this->set('title_for_layout', _txt('pl.oa4mp_client_co_oidc_client.claims.edit.name',
           array($client['Oa4mpClientCoOidcClient']['name'])));
```

Applied at all 11 sites across 9 controllers:

| Controller | Sites | Title |
|---|---|---|
| `Oa4mpClientClaimsController` | add + edit (3) | Edit/Add Claim for %s (the reported bug) |
| `Oa4mpClientCoOidcClientsController` | edit (1) | Edit %s |
| `Oa4mpClientCoNamedConfigsController` | edit + manage (2) | Edit-a / Manage %s |
| `Oa4mpClientCoCallbacksController` | add + edit (3) | Callbacks add/edit |
| `Oa4mpClientCoScopesController` | edit (1) | Scope edit |
| `Oa4mpClientAccessTokensController` | edit (1) | Access token edit |
| `Oa4mpClientAuthorizationsController` | edit (1) | Authorization edit |
| `Oa4mpClientAccessControlsController` | manage (1) | Access control manage |
| `Oa4mpClientRefreshTokensController` | edit (1) | Refresh token edit |

## Why This Works

The full chain has exactly two encoding sites, and only one is intended:

- `_txt()` (COmanage core `app/Lib/lang.php`) is a plain `sprintf` over the lang string (e.g. `pl.oa4mp_client_co_oidc_client.claims.edit.name` = `Edit Claim for %1$s`). It does **not** HTML-encode its substitution arguments.
- `pageTitleAndButtons.ctp` prints the title through `filter_var($title, FILTER_SANITIZE_SPECIAL_CHARS)` — this is the single, intended escaping point.

With the controller pre-encoding, `scott's` became `scott&#39;s` (first pass), and the element then encoded the `&` to `&amp;`, producing `scott&amp;#39;s`, which a browser displays as the literal `&#39;`. Remove the pre-encode and the element does it once: raw `scott's` -> `scott&#39;s` in the HTML source -> browser renders `scott's`.

COmanage core's `StandardController` sets `title_for_layout` by passing the **raw** title to `_txt('op.edit-a', array($title))` (no `filter_var`) — confirming the element is the intended escape point and the controllers deviated. The index view never double-encoded because `HtmlHelper::link()` escapes its text exactly once.

A useful diagnostic invariant for this class of bug: in raw View-Source, a single `&#39;` is **correct** (the browser renders it as `'`); `&amp;#39;` is the double-encoded, broken form.

## Prevention

- **In COmanage, `title_for_layout` is escaped by the `pageTitleAndButtons` element at render time. Controllers must pass the raw value to `_txt()` — never pre-encode it with `filter_var(..., FILTER_SANITIZE_SPECIAL_CHARS)`.** Pre-encoding double-encodes. Mirror core `StandardController`, which passes the raw title. The same rule applies to any value handed to a view helper/element that already escapes: escape once, at the render boundary, not in the controller.
- **For a rendering bug, capture the exact rendered bytes (View-Source) before choosing what to fix.** A paraphrased symptom sent the first fix to the wrong controller and triggered a fruitless opcache/deploy chase. The discriminator here is `&#39;` (correct) vs `&amp;#39;` (double-encoded) in the HTML source.
- **A matching `md5sum` plus a persistent symptom means wrong code path, not a failed deploy.** Only treat opcache/deployment as the cause after confirming you edited the file that renders the symptom. (Echoes the deploy-gap lesson in the sibling `hasone-duplicate-insert` doc, but the opposite conclusion: there the fix was right and undeployed; here the fix was deployed but in the wrong file.)
- **`tmp/cache/` (CakePHP application cache) is not PHP opcache.** Deleting it does not evict compiled bytecode; opcache clears only via a web-SAPI `opcache_reset()`, a web-server reload, or a pod restart.
- **When a code pattern can span lines, use a multi-line-aware search.** A same-line `grep` for `title_for_layout` + `filter_var` missed every two-line `_txt(...)` call and under-counted the blast radius.
- **Test gap:** this plugin has no runnable suite (`Test/` is empty scaffolding), so no test could have caught this. If a view-rendering harness is ever added, assert that an edit-page h1 for a client named with an apostrophe contains a single `&#39;` in the response body, never `&amp;#39;`.

## Related Issues

- [oa4mp-admin-client-hasone-duplicate-insert-2026-06-30](../logic-errors/oa4mp-admin-client-hasone-duplicate-insert-2026-06-30.md) — Same plugin, and its "What Didn't Work" carries the mirror-image deploy lesson: there a persistent symptom meant the fix was right but not deployed; here a `md5sum`-confirmed deploy with a persistent symptom meant the fix was in the wrong file. Together they bound the "confirm which file actually runs" heuristic from both sides.
- The broader OA4MP plugin corpus under [docs/solutions/logic-errors/](../logic-errors/) documents CakePHP 2.x / COmanage plugin pitfalls in the same codebase.
- Commit `2a6ee43` on `main` ("fix(titles): stop double-encoding client name in view title h1s").
