#!/usr/bin/env bash
#
# Single entry command for the Oa4mpClient hermetic test suite (U2).
#
# Brings up a real COmanage Registry + Postgres with the plugin-under-test
# overlaid, creates the schema via Registry's native `cake database` (which
# applies the overlaid plugin's schema.xml), runs the thin-runner test suite,
# and exits with the suite's status. Usable by a developer, in CI, and by Claude.
#
# Usage: Test/run.sh
set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$TEST_DIR/docker"

# Captured suite output, so the sentinel check below has something to grep.
suite_log="$(mktemp)"

cleanup() {
  rm -f "$suite_log"
  docker compose down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Bringing up Registry + Postgres..."
docker compose up -d

echo "==> Creating schema (applies the overlaid plugin schema via cake database)..."
# `cake database`'s exit code is not trustworthy: Test/README.md's "Known
# wrinkle" records it returning success while emitting a non-fatal "Possibly
# failed to update database schema" warning. Capture and print the output
# instead of discarding it, and prove the plugin's tables actually exist below
# rather than trusting this exit code alone.
docker compose exec -T comanage-registry bash -c '
  mkdir -p /srv/comanage-registry/local/Config
  source /usr/local/lib/comanage_utils.sh
  comanage_utils::prepare_database_config
  cd /srv/comanage-registry/app && ./Console/cake database'

echo "==> Verifying the plugin's tables actually exist..."
# schema.xml declares 22 <table> elements but only 15 unique table names (some
# tables, e.g. oa4mp_client_dynamo_configs, are defined twice), so an exact
# count of <table> tags would be fragile; use the unique-name count as a floor
# instead. This is the post-condition `cake database`'s exit code cannot be
# trusted to provide (see the comment above).
min_plugin_tables=15
plugin_table_count="$(docker compose exec -T comanage-registry-database \
  psql -U registry_user -d registry -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'cm_oa4mp_client_%'")"
plugin_table_count="${plugin_table_count//[[:space:]]/}"
if [ -z "$plugin_table_count" ] || [ "$plugin_table_count" -lt "$min_plugin_tables" ]; then
  echo "==> ERROR: expected at least $min_plugin_tables cm_oa4mp_client_* tables," \
    "found ${plugin_table_count:-0}. The plugin schema likely failed to apply" \
    "-- see Test/README.md's Known wrinkle." >&2
  exit 1
fi

echo "==> Running the thin-runner test suite..."
# Three independent gates, because none is sufficient alone:
#
#   1. The exec exit status, which catches a failing assertion.
#   2. The runner's ALL_TESTS_PASSED sentinel, which it prints only after every
#      discovered test has run and passed.
#   3. A floor on how many tests actually ran, because 2 says nothing about how
#      many tests discovery found in the first place.
#
# The exit status alone is not a backstop: a test that reaches exit(0) -- its
# own, or one inside the code under test, for example Controller::redirect()'s
# _stop() -- ends the whole process mid-run with a success status, and a run
# that stopped after three of a hundred tests is indistinguishable from a
# completed one. Requiring the sentinel closes that hole mechanically.
suite_status=0
docker compose exec -T comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake Oa4mpClient.Oa4mp_test' 2>&1 \
  | tee "$suite_log" || suite_status=$?

if [ "$suite_status" -ne 0 ]; then
  echo "==> ERROR: the test suite exited with status $suite_status." >&2
  exit 1
fi

# The runner's verdict block: everything from its "N tests run, M failed."
# summary onward. Both gates below read it rather than the whole log, because
# the log is not a trustworthy place to search for the sentinel as a substring.
# Test cases now read this script and assert on its text, so the literal
# appears in the suite's own test data; a failing assertion that dumped its
# haystack, or any test that echoed this file, would satisfy an unanchored
# grep. The verdict block cannot be forged that way: the runner prints its
# summary only after the last test has run, so nothing a test emits lands
# inside it. (Trailing Cake shutdown warnings can follow the sentinel, so it is
# the runner's last *statement*, not reliably the log's last line.)
suite_tail="$(tr -d '\r' < "$suite_log" \
  | sed -n '/^[0-9][0-9]* tests run, [0-9][0-9]* failed\.$/,$p')"

# Gate 2: the sentinel as a line of its own inside that block, which the runner
# prints only after every discovered test has run and passed.
if ! grep -q '^ALL_TESTS_PASSED$' <<< "$suite_tail"; then
  echo "==> ERROR: the suite exited 0 but never reached the runner's" \
    "ALL_TESTS_PASSED verdict, so it ended early rather than passing." >&2
  exit 1
fi

echo "==> Verifying the suite ran a plausible number of tests..."
# Gate 3. The sentinel above proves the runner finished the tests it
# DISCOVERED, not that discovery found the suite. Discovery is silent about
# what it misses: a `test*` method that acquires a `private` keyword drops out
# of get_class_methods() (which returns public methods only), and a file
# renamed off the *Test.php glob drops out of the scan. Either one retires a
# regression test while both gates above stay green -- the runner's only floor
# of its own is `$total === 0`.
#
# The floor sits a few tests below the suite's current size: the hermetic tier
# runs 238 tests today, so 235 leaves three tests of headroom. That way
# consolidating a case or two never forces an edit here, while any loss of four
# or more goes red -- that is every test file in the tree except the two
# smallest. Raise it deliberately as the suite grows; lower it only together
# with a deliberate removal, never to make a red run green.
#
# The 238 is 234 plus the four methods added when the cfg capability contract
# gained readers for the last two groups nothing was reading.
#
# Two are in Test/Case/Model/ContractAllowlistTest.php and close
# tokens.identity.qdl.args and dynamo_module_config the way the claim mappings
# were already closed: every key a client emits under either is a name the
# contract declares, the four CONDITIONAL authorization args included; and a
# name taken out of either group stops being emitted and is named in the
# withheld signal, which is what shows the declaration is really read rather
# than merely matched by hand.
#
# Two are in Test/Case/Model/SyncVerificationTest.php and cover the DynamoDB
# sort key: a configuration carrying a populated sort_key, and one carrying
# sort_key_template, now report IN SYNC. cfg_contract.json declares neither
# name, so the marshaller writes neither -- but the comparator compared both
# and the unmarshaller read both back, so an operator who filled either
# editable field got a client that was permanently and unrepairably out of
# sync. Test/Case/Model/ClaimCfgFallbackTest.php stopped pinning both to null
# in the same change; that is not a new method, so it is not in the four.
#
# The 234 is 228 plus the six methods in
# Test/Case/Model/UnmarshallFailureDiagnosticsTest.php, which cover what the
# OA4MP server model logs and concludes when unmarshalling the server's
# representation of a client fails: two for the credential leak the contract
# reader made reachable (the server object logged on the failure path is masked
# rather than print_r'd, and the extras blob is masked in both the line written
# when it is captured and the line written when it is merged back), one that
# client_secret is never captured into that blob at all, and three for the
# misleading failure (the catch names the exception's class, file and line; a
# comparison that could not run reports itself as an internal error rather than
# as a mismatch; and the operator-facing verdict for that is the generic edit
# error, never the "modified outside of the Registry" tampering message).
#
# The 228 is the 218 below plus the ten methods added to
# Test/Case/Lib/QdlConformanceTest.php when the QDL conformance check's own
# review findings were fixed: three for the contract's growth (a contract
# group mapped to no QDL declaration variable now FAILS instead of passing
# with a note, a group excused by name in groupsNotRequired() still passes
# with its note, and a malformed contract entry is unreadable here rather than
# accepted by a check whose model raises on it), two for the reading of the
# QDL text (a DynamoDB item field is excluded by PREFIX in every read form,
# not only the extraction operator; a block comment is stripped and an
# apostrophe inside one hides nothing behind it), four for gitShow() -- the
# only code implementing R8's distinguishing property, that a NAMED TIER's QDL
# is read out of the object store rather than out of a shared working copy,
# and previously untested -- and one that drives the script as a process to
# pin that an operator-supplied absolute --qdl-path is refused rather than
# rendered into a report pasted on a PUBLIC repository.
#
# The 218 is 211 plus the seven methods in
# Test/Case/ContractGuidanceTest.php, which lock the two contributor-facing
# records of the cross-repository obligation the contract creates: AGENTS.md
# (the contract artifact and the conformance check by name, the deployment
# ordering rule, the repository, tier branches and path the QDL lives at, and
# the boundary that the rule covers dynamodb_claims.qdl only) and the pull
# request template (the conformance result a contract_version bump records, and
# a moved golden value naming the behaviour change it reflects). One of the
# seven asserts an ABSENCE instead: this repository is PUBLIC, so the guidance
# may name that repository and its branches and must say nothing about what it
# holds.
#
# The 211 is 204 plus the seven methods in
# Test/Case/Lib/QdlConformanceTest.php, which cover the QDL conformance check
# bin/qdl-conformance.php performs against a named tier: the two directions
# (a contract capability the QDL does not implement fails and is named; a
# capability the QDL implements beyond the contract does not, since a tier is
# deployed ahead of the plugin), the fail-closed cross-check against the QDL's
# declaration block (an undeclared literal in the code, and a read form the
# extractor does not recognize), and the three outcomes that must stay
# distinct in a report pasted into a PUBLIC pull request -- a pass, a tier
# with no QDL at that path, and a QDL with no declaration block -- plus one
# that no content read from the private QDL reaches that report at all.
#
# The 240 is 235 plus the five methods added to
# Test/Case/Model/ContractRedactionTest.php for the legacy-cfg LDAP bind
# password: two that drive oa4mpUnMarshallContent() down the QDLv2 and the
# deprecated branch and assert no logged line carries the credential, one that
# pins both spellings (password, bind_password) into the literal residue and
# through it into the fallback list, one substring-safety control proving
# neither name over-matches a neighbouring key such as password_expires_at,
# and one source scan holding oa4mpUnMarshallContent() free of print_r()
# dumps, which the redactor cannot see into.
#
# The 204 is 203 plus the one method added to ClaimCfgDriftTest's Half B, which
# walks a column added to the claim table through the path such a column takes
# now that the emitted field set is read out of cfg_contract.json rather than
# out of schema.xml: undeclared it is emitted by nothing, declared it is read
# back by all three comparator sites without any of them being edited, and the
# moment one site keeps a list of its own the new column is what the drift
# report names.
#
# The 203 is 199 plus the four methods in
# Test/Case/Model/ContractRedactionTest.php, which cover the cfg-side half of
# the log-redaction name list now being derived from cfg_contract.json's
# secret_bearing flag: one that a contract-declared credential never reaches an
# emitted log line, one that the four names with no cfg counterpart still
# redact, one that an empty derivation raises rather than passing quietly, and
# one that an unreadable contract still masks a logged cfg.
#
# The 199 is 193 plus the six methods in
# Test/Case/Model/ContractVersionStampTest.php, which cover the contract
# version every marshalled cfg is now stamped with: one per marshalling path,
# three for the operator-authored named-configuration JSON the stamp has to
# survive, and one read-back.
#
# The 193 before that was 183 plus the ten methods the cfg capability contract
# added: nine in Test/Case/Model/ContractAllowlistTest.php, which closes the
# marshaller to the contract's vocabulary, and one more in ClaimCfgDriftTest's
# Half B, whose two checks became three when the emitted field set moved from
# schema.xml to cfg_contract.json.
#
# Raised from 240 to 248 for the pre-flight internal-error verdict work, which
# added nine tests (245 -> 252): seven in
# Test/Case/Controller/PreflightVerdictTest.php, which covers both verdict
# branches at the two harness-drivable guards and locks the whole set of
# thirteen by source scan, and two in UnmarshallFailureDiagnosticsTest for the
# bare verify form's third state. The floor sits a few below the real count on
# purpose, per the slack rule below.
#
# These numbers are hand-maintained and have drifted once already: the floor
# was raised from 143 to 155 while this comment went on citing the 146 tests it
# was originally derived from. ClaimsControllerHarnessTest::
# testRunShRequiresAPlausibleTestCount now counts the tree independently and
# reddens when the floor falls materially behind, so a stale floor is caught
# even when a stale comment is not. Update both together.
min_tests_run=248
tests_run="$(sed -n 's/^\([0-9][0-9]*\) tests run, [0-9][0-9]* failed\.$/\1/p' \
  <<< "$suite_tail" | head -n 1)"
if [ -z "$tests_run" ]; then
  echo "==> ERROR: the suite printed no 'N tests run, M failed.' line, so how" \
    "many tests actually ran cannot be established." >&2
  exit 1
fi
if [ "$tests_run" -lt "$min_tests_run" ]; then
  echo "==> ERROR: only $tests_run tests ran, expected at least" \
    "$min_tests_run. Tests have gone missing from discovery -- e.g. a test*" \
    "method turned private, or a test file renamed off the *Test.php glob." >&2
  exit 1
fi

echo "==> Suite passed (ALL_TESTS_PASSED, $tests_run tests)."
