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

# The Registry image reference after interpolation, override included. Asking
# compose for one service's images also lists its dependencies, so subtract
# the database's.
registry_image="$(docker compose config --images comanage-registry \
  | grep -vxF -f <(docker compose config --images comanage-registry-database))"

if [ -n "${OA4MP_TEST_REGISTRY_IMAGE:-}" ]; then
  echo "==> WARNING: OA4MP_TEST_REGISTRY_IMAGE is set, so this run uses" \
    "$registry_image instead of the compose default. Unset it once the" \
    "default image works again."
fi

echo "==> Pulling images..."
# Pull every run, then start with pulling disabled. Compose's default policy
# only pulls a missing image, so a cached copy of a tag would otherwise stand
# in for the tag's current target; and starting without a second pull keeps
# the image that runs the one this pull fetched and the report below names.
# Capture the status rather than letting `set -e` exit with no message.
pull_status=0
pull_output="$(docker compose pull --quiet 2>&1)" || pull_status=$?
if [ "$pull_status" -ne 0 ]; then
  echo "==> ERROR: could not pull the test images (Registry: $registry_image)." >&2
  echo "$pull_output" >&2
  exit 1
fi

echo "==> Bringing up Registry + Postgres..."
docker compose up -d --pull never

# Report the image that actually ran, so a red run can be told apart from
# image drift by comparing digests across runs. The repo digest is the entry
# for the reference's own repository; a locally built image has none.
registry_container="$(docker compose ps -q comanage-registry)"
registry_image_id="$(docker inspect --format '{{.Image}}' "$registry_container")"
registry_repo="${registry_image%@*}"
case "${registry_repo##*/}" in
  *:*) registry_repo="${registry_repo%:*}" ;;
esac
registry_digest="$(docker image inspect \
  --format '{{range .RepoDigests}}{{println .}}{{end}}' "$registry_image_id" \
  | grep -m1 -F "$registry_repo@" || true)"
echo "==> Registry image: $registry_image"
echo "==> Registry image ID: $registry_image_id"
echo "==> Registry image digest: ${registry_digest:-UNKNOWN}"

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
# schema.xml declares 15 tables, each once (Test/Case/Config/
# SchemaDeclarationTest.php holds it to that); use that count as a floor. This
# is the post-condition `cake database`'s exit code cannot be trusted to
# provide (see the comment above).
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
# runs 289 tests today, so 286 leaves three tests of headroom. That way
# consolidating a case or two never forces an edit here, while any loss of four
# or more goes red -- that is every test file in the tree except the two
# smallest. Raise it deliberately as the suite grows; lower it only together
# with a deliberate removal, never to make a red run green.
#
# Raised from 521 to 538 on 2026-11-11 for reserving the DynamoDB key claim's
# copy name on the Claims tab (cilogon/Oa4mpClient#67), which added fourteen
# (527 -> 541): nine in Test/Case/Controller/ClaimsWritePathTest.php (an add
# and an edit rename of cilogon_sub refused before any server call, a
# per-client configuration keyed on uid reserving cilogon_uid and not the
# default's cilogon_sub, an all-null per-client row leaving the default in
# force, no configuration reserving no name, the case and suffix near misses
# accepted, a claim named like the key claim accepted, a stored reserved-name
# claim still deletable, and the predicate itself); and five in the new
# Test/Case/View/ClaimReservedNameWarningTest.php (the add, re-rendered add and
# edit forms given the reserved name, the rendered form carrying it and the
# warning, no reserved name without a configuration, the script disabling
# Save on a match and enabling it otherwise, and its strings coming from
# lang.php through json_encode()). Before this change the suite ran 527
# tests against the floor of 521.
#
# Raised from 516 to 521 on 2026-11-05 for the client edit page while a Named
# configuration is applied (cilogon/Oa4mpClient#58), which added five
# (519 -> 524) in Test/Case/Controller/NamedConfigEditPageTest.php: opening
# the edit page creating no claim from an LDAP search attribute, the
# conversion skipped while a Named configuration is applied and still run
# without one, an edit save writing no per-client DynamoDB configuration, and
# opening the edit page deleting leftovers while keeping the access token row.
#
# Raised from 499 to 516 on 2026-11-05 for confirming before a Named
# configuration is applied or removed (cilogon/Oa4mpClient#58,
# cilogon/Oa4mpClient#59), which added seventeen (502 -> 519) in
# Test/Case/Controller/NamedConfigApplyRemoveTest.php: the apply confirmation
# with its JWT and authorization-gate sentences, the confirmation on a switch
# and on removal, the confirmation page posting the choice back with Cancel as
# a link, the confirmed apply sending no replaced section and deleting those
# rows, a refused apply changing nothing, the confirmed removal resetting an
# older client to new-client defaults, a removal not sent while leftovers
# remain, nothing sent for the state the client is already in, re-saving with
# an access token row confirming and deleting it, a public client and another
# admin client's Named configuration refused, and failed writes after the
# server accepted reported as errors. The Named-configuration cases of
# ValidateBeforeServerWriteTest.php now post the confirmation flag, and
# NamedConfigClientActionsTest.php holds the Named Configuration page to the
# pruning helper too.
#
# Raised from 489 to 499 on 2026-11-05 for refusing the pages a Named
# configuration replaces and deleting its leftovers on touch
# (cilogon/Oa4mpClient#58), which added ten (492 -> 502): eight in
# Test/Case/Controller/NamedConfigClientActionsTest.php (the Access Tokens
# page and save refused, the ordinary-client control, Callbacks and Refresh
# Tokens saves still sent, opening Callbacks deleting a leftover claim and
# keeping the access token row, a failed delete reported without blocking the
# page, and every client action loading through the pruning helper); one in
# Test/Case/Controller/ScopesNamedConfigGuardTest.php (a public client with a
# Named configuration sent to its edit page); and one in
# Test/Case/Controller/ClaimsWritePathTest.php (the claim edit form refused).
# The read-only page tests of those files and
# AuthorizationsNamedConfigGuardTest.php were rewritten to assert refusal.
#
# Raised from 481 to 489 on 2026-11-05 for client tab visibility while a Named
# configuration is applied (cilogon/Oa4mpClient#58), which added eight
# (484 -> 492) in Test/Case/View/ClientTabVisibilityTest.php: the tab bar
# rendered for a Named-configuration client, a public client, an ordinary
# client and with neither variable set; the bar testing both variables only
# through empty(); every action that draws the bar calling the helper; and the
# callbacks index setting the Named-configuration and public-client variables.
#
# Raised from 472 to 481 on 2026-11-05 for the new-client defaults and the
# Named configuration leftover prune (cilogon/Oa4mpClient#58), which added nine
# (475 -> 484) in Test/Case/Model/NamedConfigLeftoverPruneTest.php: the prune
# deleting every replaced row but the access token, leaving a client with no
# Named configuration, a public client, another client and the Named
# configuration's own scopes alone, and reporting a failed claim delete after
# the LDAP rows are gone; and the new-client defaults for a confidential and a
# public client, and newClientData() taking the openid scope from them.
#
# Raised from 462 to 472 on 2026-11-05 for OIDC client add and the callbacks
# tab acting only on the client in the URL (cilogon/Oa4mpClient#65), which
# added ten (465 -> 475): Test/Case/Controller/CallbackStoredIdsTest.php
# (callback add with a forged callback id or client id adding to the URL's
# client; edit with forged ids editing only the route's callback; editing
# another client's callback refused on GET and POST and deleting one refused,
# each before the server is called; and a control delete), and three in
# Test/Case/Controller/ValidateBeforeServerWriteTest.php (an add posting
# another client's id or contact email row id leaving that row alone, and an
# add posting a Named configuration id neither sending nor saving it).
#
# Raised from 460 to 462 on 2026-11-05 for source checks of the read-only
# Scopes and Claims views on Named-configuration clients
# (cilogon/Oa4mpClient#57), which added two (463 -> 465):
# Test/Case/Controller/ScopesNamedConfigGuardTest.php checks every scope
# checkbox but openid is disabled and the save button left out, and
# Test/Case/Controller/ClaimsWritePathTest.php checks the claims index leaves
# out Add, Edit and Delete and shows the claim name as plain text.
#
# Raised from 452 to 460 on 2026-11-05 for claim add, edit and delete acting
# only on the client in the URL and its own claims (cilogon/Oa4mpClient#56),
# which added eight (455 -> 463) in
# Test/Case/Controller/ClaimsWritePathTest.php: deleting another client's claim,
# and opening and posting an edit of one, each refused before the server is
# called; an add whose posted claim id, client id, or constraint id names
# another row adding a new claim to the URL's client and leaving that row
# alone; an edit whose posted constraint id names another claim's constraint
# leaving it alone; and an edit whose posted claim and client ids name another
# client's claim editing only the claim in the route.
#
# Raised from 445 to 452 on 2026-11-05 for the Access Control tab accepting
# only the groups it lists (cilogon/Oa4mpClient#54), which added seven
# (448 -> 455) in Test/Case/Controller/ValidateBeforeServerWriteTest.php: a
# group of another CO, a suspended group, and a group id that names no group
# each refused before the server is called; an editor who is not an
# administrator refused a group they are not in and allowed one they are in;
# and a post naming another client's access control row leaving that row
# alone, both when the client has its own row and when one is created.
#
# Raised from 440 to 445 on 2026-11-04 for edits of an OIDC client saving only
# that client (cilogon/Oa4mpClient#53), which added five (443 -> 448) in
# Test/Case/Controller/ValidateBeforeServerWriteTest.php: a post carrying
# another client's id and OA4MP identifier editing only the client in the URL,
# another client's id alone no longer failing on the unique identifier, a
# posted contact email row of another client left where and as it was, posted
# public_client and named_config_id not saved, and a rejected post with a
# forged id shown again with the stored id.
#
# Raised from 426 to 440 on 2026-11-04 for read-only Access Token and
# Authorization tabs on public clients (cilogon/Oa4mpClient#47), which added
# fourteen (429 -> 443): six in Test/Case/Model/SyncOptionalSectionsTest.php
# (a public client's JWT Format and its Require Active Status with a group
# reading in sync against a server copy without them, the same settings still
# reading out of sync on a confidential client, and a public client whose
# client type or callbacks differ still reading out of sync); and eight in
# Test/Case/Controller/PublicClientTabGuardTest.php (each tab refusing a
# public client's save before any server call, rendering its page with the
# public flag set, leaving the flag clear for a confidential client, and
# tying its form controls and submit to the read-only flag in source).
#
# Raised from 411 to 426 on 2026-11-04 for converting isMemberOf to a JSON
# array claim (cilogon/Oa4mpClient#40), which added fifteen (414 -> 429):
# seven in Test/Case/Model/ClaimMigrationPersistenceTest.php (a list-valued and
# a non-list isMemberOf both migrating to json_array with no delimiter, an
# old-format isMemberOf orphan rewired and upgraded rather than duplicated, a
# hand-changed one not rewired, and the edit-page repair upgrading an
# old-format row while leaving a hand-changed row and an unlinked row alone);
# six in Test/Case/Model/SyncVerificationTest.php (a row converted before the
# change reading in sync against a QDLv2 cfg, both row shapes reading in sync
# against a deprecated cfg, a differing source field on the legacy path still
# reading out of sync, a differing serialization on a QDLv3 cfg still reading
# out of sync, and the comparison reporting legacy_cfg for a QDLv2 cfg and not
# for a QDLv3 one); and two in
# Test/Case/Controller/PreJsonArrayIsMemberOfRepairTest.php (the edit page
# repairing the old-format row against a legacy cfg and leaving it against a
# QDLv3 cfg).
#
# The 289 is 257 plus the 32 methods added when the client-read began asking the
# server for its newest representation: 15 in
# Test/Case/Model/ServerRequestQueryTest.php, covering which query each request
# kind carries and which kind each of the four senders asks for; 12 in
# Test/Case/Model/NewerRepresentationRoundTripTest.php, covering what the newer
# representation captures, hands back, and drops, and that the drift verdict
# does not move; and 5 in Test/Case/Model/CapturedKeySetLockTest.php, locking
# exactly which keys reach the extras blob.
#
# The 257 is where the suite stood immediately before that work. The paragraphs
# below derive an older chain and are kept because each still explains the
# methods it names; they no longer sum forward to the current total, which is
# why the count above is stated against 257 directly rather than threaded
# through them.
#
# The 238 of that older chain is 234 plus four methods added when the cfg
# capability contract gained readers for the last two groups nothing was
# reading.
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
# Raised from 248 to 253 for the response-encoding decode fix, which added five
# in Test/Case/Model/ServerResponseDecodingTest.php (252 -> 257): the Latin-1
# regression, the ASCII control that explains why the defect hid, and the
# UTF-8 / no-Content-Type / undecodable-body paths.
#
# Raised from 240 to 248 for the pre-flight internal-error verdict work, which
# added nine tests (245 -> 252): seven in
# Test/Case/Controller/PreflightVerdictTest.php, which covers both verdict
# branches at the two harness-drivable guards and locks the whole set of
# thirteen by source scan, and two in UnmarshallFailureDiagnosticsTest for the
# bare verify form's third state. The floor sits a few below the real count on
# purpose, per the slack rule below.
#
# Raised from 407 to 411 for the read-only Authorization tab on
# Named-configuration clients, which added four (410 -> 414) in
# Test/Case/Controller/AuthorizationsNamedConfigGuardTest.php: the save
# refused before any server call, the page still rendering with the saved
# setting and the configuration's name, the control that an ordinary
# client's save reaches the server, and a source check that every form
# control is disabled, the submit block left out and the clearing script
# skipped while a Named configuration is applied.
#
# Raised from 340 to 343 for the read-only Scopes tab on Named-configuration
# clients, which added three (343 -> 346) in
#
# Raised from 404 to 407 for the read-only Scopes tab on Named-configuration
# clients, which added three (407 -> 410) in
# Test/Case/Controller/ScopesNamedConfigGuardTest.php: the save refused
# before any server call, the page still rendering with the configuration's
# name, and the control that an ordinary client's save reaches the server.
#
# Raised from 333 to 340 for the read-only Claims tab on Named-configuration
# clients, which added seven (336 -> 343): six in
#
# Raised from 397 to 404 for the read-only Claims tab on Named-configuration
# clients, which added seven (400 -> 407): six in
# Test/Case/Controller/ClaimsWritePathTest.php (add, edit and delete refused
# before any server call, the add form refused by direct URL, the control
# that an ordinary client's add still reaches the server, and the index
# handing the view the configuration's name) and one in
# NamedConfigAuthorizationCfgTest (the label falling back to the id).
#
# Raised from 328 to 333 for the Named-configuration authorization args,
# which added five (331 -> 336): Test/Case/Model/
#
# Raised from 392 to 397 for the Named-configuration authorization args,
# which added five (395 -> 400): Test/Case/Model/
# NamedConfigAuthorizationCfgTest.php (a Named-configuration client sends no
# authorization args, the configuration's own args reach the cfg unchanged,
# removing it restores them, the predicate reads named_config_id rather than
# the association, and a retained authorization row still reports in sync).
#
# Raised from 345 to 392 on 2026-11-03 for validating before the server write
# on the refresh token, access token, access control, scopes, named
# configuration, OIDC client edit and OIDC client add pages, which added
# forty-seven in Test/Case/Controller/ValidateBeforeServerWriteTest.php
# (348 -> 395): each page rejecting invalid input before the server is called
# and keeping it in the form, sending and saving valid input as before,
# reporting a save that fails after the server accepted the change, and
# saving nothing when the server refuses a valid change; plus a rejected
# post on an out-of-sync client still redirecting. The named
# configuration page rejects a selection that is neither none nor one of the
# admin client's own named configurations. The OIDC client edit page also
# keeps a rejected contact email's error where the form reads it, and keeps a
# rejected post's errors when the page rewrites the client's server extras
# before rendering, and reports a refreshed DynamoDB configuration that
# fails to save after the server accepted the edit. The OIDC client add page checks the whole new client,
# including the configuration copied from the admin client's defaults, shows
# a rejected form set up for the posted admin client, still creates public
# and confidential clients as before, and on a save that fails after the
# create logs the new client ID without the secret and leaves for the index;
# when the server creates nothing it saves nothing.
#
# Raised from 334 to 345 for the URL whitespace fix (cilogon/Oa4mpClient#48),
# which added eleven in Test/Case/Controller/UrlWhitespaceWritePathTest.php
# (337 -> 348): the callback and authorization models trimming their URLs,
# callbacks add and edit sending and saving the trimmed URL, both rejecting an
# invalid URL before the server is called, the authorization manage action
# doing the same for its redirect URLs and re-rendering a rejected post's
# Require Active Status as posted, and the OIDC client add and edit actions
# trimming the home URL before building what the server is sent.
#
# Raised from 330 to 334 for the duplicate schema tables fix, which added four
# in Test/Case/Config/SchemaDeclarationTest.php (333 -> 337): each table is
# declared once, each referenced plugin table is declared before its referrer,
# and a positive control for each of those two checks.
#
# Raised from 328 to 330 for the Registry image following a GHCR tag, which
# added two in Test/Case/CiWorkflowTest.php (331 -> 333):
# testRunScriptsPullThenReportTheRegistryImage, which checks that both run
# scripts pull before starting and report the image that ran, and
# testBothTiersTakeTheImageOverrideAsAVariable, which checks that both
# workflows take the image override from a repository variable.
#
# Raised from 327 to 328 for the authorization group id type fix, which added
# one (330 -> 331): CfgMarshallingTest::
# testAuthorizationGroupIdIsSentAsAnInteger, which checks that a group id
# posted as a string leaves in the cfg as an integer.
#
# Raised from 318 to 327 for the no-record redirect URL, which added nine
# (321 -> 330): six in Test/Case/Model/AuthorizationRedirectRoundTripTest.php
# (all three redirect URLs round-tripping in sync, a server copy missing the
# no-record URL, a client without it, an empty value, a row predating the
# column, and a public client sending no cfg) and three in
# Test/Case/View/AuthorizationNoRecordFieldTest.php (the field rendered, never
# hidden or cleared by the script, and its help text).
#
# Raised from 317 to 318 for the callbacks index link fix, which added one
# (320 -> 321): Test/Case/View/ClientScopedLinksTest.php, which checks that
# every link a client-scoped index view builds to its own controller carries
# the clientid named parameter.
#
# Raised from 297 to 317 for the Require Active Status default on new
# confidential clients, which added eighteen (302 -> 320): three in
# Test/Case/Model/AuthorizationCreateSaveTest.php (a new client saves with its
# authorization row, saves without one, and an update still requires
# client_id); nine in Test/Case/Model/CreateTimeCfgTest.php (confidential
# and public create data, the admin DynamoDB default and QDL script in the
# create cfg, no marshalling warnings, create cfg equal to the edit cfg, the
# read-back sync verdict, the no-row edit case, an unbuildable cfg stopping
# the create before it is sent, and add() sending and saving the builder
# output); and six in Test/Case/Model/RequireActivePredicateTest.php (no row,
# saved off, saved NULL, saved on, the posted strings, and the Authorization
# template's binding to the saved value).
#
# Raised from 292 to 297 for the toClaim() duplicate-insert fix, which added
# four in Test/Case/Model/ClaimMigrationPersistenceTest.php (297 -> 301): two
# migrations writing one configuration row, a migration updating an existing
# row in place, the guard that the admin client default is never written
# through, and a legacy-duplicate client where the row written and the row read
# back must be the same one.
#
# Raised from 286 to 292 for the stale per-client DynamoDB configuration fix,
# which added eight in Test/Case/Model/DynamoDefaultRotationTest.php
# (289 -> 297): the rotated credential reaching the cfg and its unrefreshed
# control, the in-place save, the three cases the refresh declines, the wiring
# scan over the OIDC clients controller, and teardown completeness.
#
# These numbers are hand-maintained and have drifted once already: the floor
# was raised from 143 to 155 while this comment went on citing the 146 tests it
# was originally derived from. ClaimsControllerHarnessTest::
# testRunShRequiresAPlausibleTestCount now counts the tree independently and
# reddens when the floor falls materially behind, so a stale floor is caught
# even when a stale comment is not. Update both together.
min_tests_run=538
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
