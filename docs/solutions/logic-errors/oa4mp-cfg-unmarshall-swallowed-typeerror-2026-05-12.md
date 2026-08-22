---
title: PHP 8 TypeError swallowed by defensive catch disguised cfg-format failure in Oa4mpClient unmarshalling
date: 2026-05-12
category: logic-errors
module: Oa4mpClient plugin (OIDC client cfg unmarshalling)
problem_type: logic_error
component: rails_model
related_components:
  - rails_model
severity: high
symptoms:
  - Operator log emits "Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration" for a structurally-valid cfg
  - The cfg is in fact a valid QDLv2 format-1 cfg (two qdl array entries; first with LDAP-connection args, second with claim mappings)
  - oa4mpUnMarshallContent's three-format fallback (QDLv3 then QDLv2 then Deprecated) emits the misleading log when the actual cause is a swallowed TypeError
  - No information in the log about which exception fired, where, or why; the catch block discarded the exception object after logging the human-readable interpretation
  - Oa4mpClientClaim rows for the cfg's claim mappings are not produced after a sync edit cycle, so the cfg appears unrecognized in the database too
root_cause: logic_error
resolution_type: code_fix
tags:
  - oa4mp
  - oidc
  - php8
  - typeerror
  - exception-handling
  - cfg-format
  - defensive-catch
  - silent-failure
---

# PHP 8 TypeError swallowed by defensive catch disguised cfg-format failure in Oa4mpClient unmarshalling

## Problem

`oa4mpUnMarshallCfgQdlv2` in `Model/Oa4mpClientOa4mpServer.php` logged "Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration" for an OIDC client whose cfg JSON was, in fact, a structurally valid QDLv2 format-1 cfg. The log message sent the operator looking for a new cfg format, an unsupported NamedConfiguration, or a malformed JSON, while the real cause was a one-line missing-key bug surfacing as a PHP 8 `TypeError` that the function's own defensive catch block swallowed before logging.

## Symptoms

- Operator log line: `Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration`
- The cfg passes JSON validation and matches the schema-documented "format 1.0.0" shape (`qdl` is an array with two entries, first carrying LDAP-connection `args`, second carrying claim-mapping `args`).
- `Oa4mpClientClaim` rows for the cfg's claim mappings (e.g., `isMemberOf → is_member_of`, `voPersonID → sub`) never appear in the database.
- The catch block discards the exception object; the operator log has no `$e->getMessage()`, `$e->getFile()`, or `$e->getLine()` to point at the real problem.
- No PHP error log entry either, because the exception was caught (not unhandled).

## What Didn't Work

These were the most plausible wrong directions, none of which would have found the root cause:

- Comparing the cfg JSON against the format-3 (QDLv3) schema to see if it was a "new format that needs a new unmarshaller". QDLv3 silently returns empty for this cfg because its `qdl['args']` lookup finds nothing when `qdl` is an array; that fall-through is correct, not the bug.
- Looking for malformed JSON or character encoding issues. The cfg parses fine; `json_decode($json, true)` returns the expected nested associative array.
- Treating the message literally and investigating `NamedConfiguration` lookup paths. The log line names NamedConfiguration as a guess, not as evidence; the function never actually reached any NamedConfiguration code path.
- Reading the cfg's `load` script paths (`MESS/TEST/identity_token_ldap_claim_source.qdl`) on the OA4MP server to see if the issue was on the server side. The script paths are metadata that the plugin's unmarshallers ignore.

## Solution

Two surgical changes in `Model/Oa4mpClientOa4mpServer.php`:

1. Guard the unconditional array-access (line 1639 pre-fix; `Model/Oa4mpClientOa4mpServer.php:1715` today) against the missing optional key:

   ```php
   // Before
   $listAttributes = $qdl_args['list_attributes'];

   // After
   // Default to an empty array when 'list_attributes' is absent so the
   // in_array() call below does not throw a TypeError under PHP 8.x.
   // An absent 'list_attributes' means no attributes are multi-valued
   // lists; every attribute defaults to return_as_list = false.
   $listAttributes = $qdl_args['list_attributes'] ?? array();
   ```

2. Extend both `catch (Exception $e)` and `catch (TypeError $e)` blocks (lines 1676 / 1679 in the original; `Model/Oa4mpClientOa4mpServer.php:1752` / `:1757` today) to log the swallowed exception's identity so the next operator who sees the message can diagnose it directly:

   ```php
   // Before
   } catch (TypeError $e) {
     $this->log("Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration");
     return array();
   }

   // After
   } catch (TypeError $e) {
     $this->log("Oa4mpClientCoOidcClient cfg is not a defined format, perhaps a NamedConfiguration"
                . " (TypeError at " . $e->getFile() . ":" . $e->getLine()
                . " - " . $e->getMessage() . ")");
     return array();
   }
   ```

   (The same shape applies to the adjacent `catch (Exception $e)` block.)

Landed as commit `ca7d349` ("fix(oa4mp-server): handle QDLv2 cfg without list_attributes"), developed on branch `fix/cfg-unmarshall-old` and now on `main`. Diff: 11 insertions, 3 deletions in `Model/Oa4mpClientOa4mpServer.php`.

## Why This Works

The full causal chain that the swallowed log was hiding:

1. The cfg's first qdl entry (`qdl[0].args`) contains LDAP-connection fields plus `return_attributes` (which attrs to fetch from LDAP). It does **not** contain `list_attributes` (which subset of return_attributes are multi-valued).
2. `oa4mpUnMarshallContent` tries `oa4mpUnMarshallCfgQdlv3` first. QDLv3 expects `qdl` to be a single object with `args` directly under it (`$cfg['tokens']['identity']['qdl']['args']`). Since `qdl` is a numerically-indexed array here, the string-key lookup yields `null` and `!empty(null)` is false. QDLv3 returns empty — correct fall-through.
3. `oa4mpUnMarshallContent` then tries `oa4mpUnMarshallCfgQdlv2`. QDLv2's format-1 path matches: `count($qdl) == 2` and both entries have `args`. The function builds the LDAP config fields from `qdl[0].args` successfully.
4. At the original line 1639, `$listAttributes = $qdl_args['list_attributes']` assigned `null` because the key was absent.
5. In the search-attribute foreach loop, `in_array($key, $listAttributes)` was called. Under PHP 8.x, `in_array($needle, null)` raises `TypeError: in_array(): Argument #2 ($haystack) must be of type array, null given` (verified on PHP 8.4.21 via `php -r 'in_array("x", null);'`).
6. The function's `catch (TypeError $e)` block at the end of the try caught the error and logged the misleading format-detection message — without the `TypeError` itself ever surfacing in the log.

The fix's two parts address two distinct things:

- **The `list_attributes` guard** restores the intended semantics: absent `list_attributes` means no attributes are multi-valued lists; every search attribute defaults to `return_as_list = false`. With the guard in place, the foreach completes, QDLv2 produces a populated `$ldapConfig`, and `oa4mpUnMarshallContent` continues into `buildClaimFromLdapMapping` to build `Oa4mpClientClaim` rows.
- **The catch-block diagnostic** makes the failure mode self-describing if a similar disguised-error case ever happens again. A future operator sees not just "is not a defined format" but the actual exception class, file, line, and message — turning a wild-goose-chase debug session into a one-glance fix.

## Prevention

Generalizable rules that compound beyond this one fix:

1. **Never swallow `$e` in a catch block without logging `$e->getMessage()`, `$e->getFile()`, and `$e->getLine()`.** A defensive catch that discards the exception details replaces a clear error message with a guess. The pattern in this file —
   ```php
   } catch (Exception $e) {
     $this->log("Some human-readable interpretation");
     return array();
   }
   ```
   — is a silent-failure trap regardless of language. PHP, Ruby, Python, Go — the same rule applies. If the caller needs the human interpretation, log both: identity first, interpretation second. Other catch blocks in this codebase that follow the same pattern are candidates for the same treatment when next touched.

2. **In PHP 8.x, every unguarded array key access is a potential TypeError downstream.** Patterns like `$x = $row['optional_key']` returned `null` silently in PHP 7 and would gracefully evaluate `in_array($needle, null)` to `false` with only a warning. PHP 8 raises a hard `TypeError` instead. When migrating or maintaining PHP 8 code, prefer `?? $default` on every optional array key whose downstream consumer requires a specific type (array, int, string, callable). The cost is negligible; the silent-failure risk it averts is real.

3. **Catch `TypeError` and `Exception` separately, but treat the message shapes identically.** PHP 8 split error hierarchies so `Error` (including `TypeError`) does **not** extend `Exception`. Code that only catches `Exception` will let `TypeError` propagate as uncaught and produce a hard 500. Code that catches both must do so via separate `catch` blocks (or `catch (Throwable $e)` if covering both is acceptable). Either way, the message logged should follow rule 1 above.

4. **Format-detection fallback chains should distinguish "this format does not match" from "this format matched but errored mid-parse".** The current three-format fallback in `oa4mpUnMarshallContent` treats an empty return from each unmarshaller as "didn't match"; a TypeError mid-parse looks identical. A small improvement is to make each unmarshaller return `null` on "didn't match" and `array()` on "matched but produced no output" — collapsing both into `array()` today means the fallback chain can't tell genuine non-matches from disguised parse errors. Out of scope for the immediate fix but a candidate for a follow-up cleanup.

5. **When a log message names a hypothesis ("perhaps X"), it must also name what evidence led to that hypothesis.** "Perhaps a NamedConfiguration" with no supporting evidence sends operators to the wrong investigation. Either name the evidence (e.g., "qdl key was missing, and named_config_id is set") or drop the hypothesis from the message.

6. **Closed 2026-08-19: this bug is now locked by a regression test.** When this doc was written the plugin had no test harness for `Model/Oa4mpClientOa4mpServer.php` (the gap was raised in `docs/brainstorms/2026-05-05-oa4mp-unmarshall-claim-output-brainstorm.md`), so cfg-format regressions relied on manual operator-side discovery. A hermetic suite now exists under `Test/` (`Test/run.sh`, gated on pull requests since commit `e4e2df8`). This bug is covered by `Test/Case/Model/CfgMarshallingTest.php::testUnmarshallQdlv2WithoutListAttributesDoesNotSwallowMappings()`, which feeds `oa4mpUnMarshallCfgQdlv2()` a valid QDLv2 cfg with `list_attributes` deliberately absent and asserts the mappings are not swallowed (commit `1600944`). Coverage status lives in `Test/README.md` under "Regression coverage status". The standing rule that survives: **a defensive catch that hides a parse failure needs a test that feeds the parser the shape which triggered it** — the diagnostic logging in rule 1 speeds diagnosis but is not a substitute.

## Related Issues

- `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md` — the prior workstream's solution doc on this same function. Shares the "swallowed log message hides root cause" theme; this doc adds the catch-block-diagnostic dimension.
- Same file, same module: `Model/Oa4mpClientOa4mpServer.php`. The `redactSecrets` helper added in the May 2026 sync-drift-log-detail workstream is unrelated to this bug but lives in the same model.
