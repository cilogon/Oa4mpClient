---
title: Sync comparator and cfg marshaller disagreed about which claim data is real
date: 2026-08-22
category: logic-errors
module: Oa4mpClient plugin (OIDC client sync verification and cfg marshalling)
problem_type: logic_error
component: rails_model
severity: high
symptoms:
  - "A claim carrying a half-populated constraint (only constraint_field or only constraint_value) reports out of sync on every verify pass, with no edit able to repair it"
  - "A claim whose source_model_claim_value_field holds the string '0' reports out of sync on every verify pass, with no edit able to repair it"
  - "Re-sending the identical data does not clear the drift: the plugin marshals one shape to the server and compares against a different one"
  - "The permanent out-of-sync verdict blocks the client's edit path, because one comparator verdict gates every tab of the client"
  - "The empty-string sibling of the '0' case is silently masked: the comparator's final array comparison is loose, so '' == null passes"
root_cause: logic_error
resolution_type: code_fix
related_components:
  - Model/Oa4mpClientOa4mpServer.php (oa4mpMarshallCfgQdl, isClientDataSynchronized)
  - Test/Case/Model/ClaimConstraintSymmetryTest.php (regression lock)
  - Test/Case/Model/ClaimCfgContractTest.php (characterization rows for the two value-field states)
tags:
  - oa4mp
  - oidc
  - sync-verification
  - comparator
  - marshaller
  - claim
  - claim-constraint
  - normalization-asymmetry
  - php-empty-semantics
  - half-done-fix
---

# Sync comparator and cfg marshaller disagreed about which claim data is real

## Problem

`Model/Oa4mpClientOa4mpServer.php` contains two independent decisions about
which parts of a claim row count as real data:

- `oa4mpMarshallCfgQdl()` decides what is **sent** to the OA4MP server.
- `isClientDataSynchronized()` decides what is **compared** against what came
  back.

Those two decisions have to be the same decision. When they are not, the plugin
sends one shape and then compares against a different one, and the comparator
reports drift that no edit can repair: re-saving the client marshals the same
cfg, which unmarshals to the same server side, which compares unequal to the
same plugin side, forever. Because one comparator verdict gates the whole
client (not merely its claims tab), a client in that state is effectively
frozen.

Two fields had drifted apart, in the same way and for the same reason.

### Defect A - half-populated claim constraints

Commit `7684cbb` tightened the marshaller's constraint guard from `||` to `&&`,
so a constraint is emitted only when **both** `constraint_field` and
`constraint_value` are populated. That commit touched one file and one line and
left the two comparator normalizations still filtering with `||`.

The result: a claim carrying a half-populated constraint marshals to **zero**
constraints but normalizes to **one** on the plugin side. The normalized claim
arrays then differ, and the client reports out of sync permanently.

### Defect B - the string-zero value field

The marshaller strips every claim column whose value is `empty()`:

```php
// Unset any fields that are empty.
foreach($mapping as $key => $value) {
  if(empty($value)) {
    unset($mapping[$key]);
  }
}
```

`empty('0')` is true in PHP, so a `source_model_claim_value_field` holding the
string `'0'` is never sent. Both comparator normalizations, however, read that
one column with a null coalesce rather than an emptiness test:

```php
$normalized['source_model_claim_value_field'] = $claim['source_model_claim_value_field'] ?? null;
```

`??` only substitutes for null and undefined, so `'0'` survives on the plugin
side and is compared against the server's absent value. The comparator's final
array comparison is loose (`!=`), which is exactly why this was invisible for
the empty-string case -- `'' == null` is true -- and why only `'0'` bites:
`'0' == null` is false. Every sibling column on the same lines already used
`!empty(...) ? ... : null`; this one column was the odd one out.

## Symptoms

- A client whose claim carries a half-populated constraint, or a `'0'` value
  field, reports out of sync on every verify pass.
- The drift is unrepairable by the normal remedy (re-save the client), because
  both sides of the comparison are regenerated from the same data each time.
- The empty-string case looks fine, which makes the `'0'` case read as an
  inexplicable one-off rather than as a class of bug.

## Solution

Make the comparator apply the marshaller's rule, on both sides, for both
fields. `Model/Oa4mpClientOa4mpServer.php`, in `isClientDataSynchronized()`,
in both the `$curClaims` and the `$oa4mpClaims` normalization loops.

The value field, before and after:

```php
// Before
$normalized['source_model_claim_value_field'] = $claim['source_model_claim_value_field'] ?? null;

// After
$normalized['source_model_claim_value_field'] = !empty($claim['source_model_claim_value_field']) ? $claim['source_model_claim_value_field'] : null;
```

The constraint filter, before and after:

```php
// Before
if(!empty($constraint['constraint_field']) || !empty($constraint['constraint_value'])) {
  $constraints[] = array(...);
}

// After
if(!empty($constraint['constraint_field']) && !empty($constraint['constraint_value'])) {
  $constraints[] = array(...);
} elseif(!empty($constraint['constraint_field']) || !empty($constraint['constraint_value'])) {
  $this->log("Oa4mpClientClaim: dropping half-populated constraint from"
             . " the plugin-side comparison"
             . " (client=" . var_export($clientIdentifier, true)
             . ", constraint_field=" . var_export($constraint['constraint_field'] ?? null, true) . ")");
}
```

The `elseif` branch is the mitigation for the one thing this change costs, and
is discussed under "Why This Works" below. It logs the client identifier and
the constraint's field name and nothing else: no constraint value, and no
row-shaped payload. That last restriction is not cosmetic -- structures in this
model carry the DynamoDB access key and secret, and the file's `redactSecrets()`
helper exists precisely because a log line in this file already had to mask
them. Logging only two scalars sidesteps the question.

The marshaller was deliberately **not** touched. It already states the intended
rule; the comparator was the side that had not caught up.

## Why This Works

The comparator runs the *same* filter over both sides. Anything the filter
removes is removed from the plugin side and the server side alike, so the
change can only ever relax a verdict, never tighten one: no client that reports
in sync today can begin reporting out of sync. That property is what makes the
change safe to land under a verdict consumed by fourteen call sites.

The cost is the mirror of the benefit, and it is real. Where the **server's**
copy carries a half-populated constraint and the plugin's does not, the
mismatch is no longer reported, and the next edit silently rewrites it away.
That is the state the drop log makes greppable. It is the right trade: the
degenerate constraint was never interpretable by the server's QDL in the first
place, and the alternative is a client nobody can edit.

## Prevention

1. **A marshaller and its comparator encode one decision; change them
   together.** Any predicate that decides "is this field real" must exist once
   in intent and be applied identically on the way out and on the way back. If
   a fix tightens one side, the same commit tightens the other, or the fix has
   created a permanent-drift bug where there was a merely-degenerate one.

2. **Finish the sweep: `grep` the predicate, not the line.** Commit `7684cbb`
   was a correct one-line fix to a correct target that changed a rule applied
   in three places. Before landing a predicate change, search the file for the
   other sites that test the same thing -- `constraint_field` appeared in three
   guards, and only one moved.

3. **`??` and `empty()` are different questions; do not mix them across a
   comparison boundary.** `??` fires on null and undefined only. `empty()` also
   fires on `''`, `'0'`, `0`, `0.0`, `false` and `array()`. When one side of a
   round trip is built with one and the other with the other, the difference
   surfaces only for the values that separate them -- most memorably the string
   `'0'`, which is the value that makes PHP's `empty()` notorious.

4. **An odd one out in a block of parallel assignments is a defect until
   proven otherwise.** Seven adjacent lines normalized their column with
   `!empty(...) ? ... : null`; one used `??`. Parallel code that stops being
   parallel for one element is worth a second look every time -- the
   inconsistency is the whole tell.

5. **A loose comparison downstream can mask an asymmetry for years.** The
   `''` case never surfaced because the final array comparison is `!=` and
   `'' == null`. Masking is not correctness: it hides the class of bug and
   leaves the surviving member of the class (`'0'`) looking like an
   unreproducible one-off. When a bug reproduces for one value of a field and
   not its neighbours, suspect a coercion rule, not the data.

6. **When a fix makes a state invisible, log the state.** Relaxing a check
   removes a signal. Replacing that signal with a log line -- identifier plus
   the minimum needed to find the row, never the payload -- keeps the operator
   able to answer "did this ever happen?" later. Deciding what may appear in
   that line is part of writing it, not an afterthought: in this file the row
   shapes carry credentials, so the log carries scalars.

## Related Issues

- `docs/solutions/logic-errors/oa4mp-claim-migration-three-latent-bugs-2026-05-18.md`
  -- its Fix 3 is commit `7684cbb`, the marshaller half of Defect A. That doc
  records the guard as "latent, unreachable on current data"; correct about the
  marshaller, but the comparator's matching `||` was never brought along, which
  is what turned a latent guard mismatch into a live permanent-drift bug.
- `docs/solutions/logic-errors/oa4mp-unmarshall-claim-comparator-drift-2026-05-05.md`
  -- the earlier round of comparator-versus-unmarshaller key mismatches in the
  same function. Same shape of defect (two layers disagreeing about the same
  data), different pair of layers.
- `Test/Case/Model/ClaimConstraintSymmetryTest.php` -- the regression lock for
  both defects, including the fully-populated-versus-fully-populated negative
  control that keeps the fix distinguishable from "stop comparing constraints".
- `Test/Case/Model/ClaimCfgContractTest.php` -- rows
  `source_model_claim_value_field/string_zero` and `/empty`. The string-zero row
  characterized the pre-fix out-of-sync verdict and now asserts in sync; the
  empty row's verdict is unchanged but its reasoning is no longer "the loose
  comparison saves us".
- `Test/Case/Model/CfgMarshallingTest.php::testEmptyConstraintValueIsNotSerialized`
  -- the marshaller-side lock for the constraint rule the comparator now shares.
- `docs/solutions/logic-errors/oa4mp-named-config-claims-inert-2026-08-22.md`
  -- the opposite failure in the same pair of functions. There the marshaller
  and the comparator disagree and produce permanent silence instead of
  permanent drift: both skip the claim comparison for a named-configuration
  client, and nothing gates the tab that keeps producing claims for that
  skipped path.
