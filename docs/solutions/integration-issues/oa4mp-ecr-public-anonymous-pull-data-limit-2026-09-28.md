---
title: Anonymous pulls of the Registry image from ECR Public failed the hermetic gate with "Data limit exceeded", fixed by pinning the same digest on GHCR
date: 2026-09-28
category: integration-issues
module: Oa4mpClient plugin (CI test environment)
problem_type: integration_issue
component: tooling
related_components:
  - "Test/docker/docker-compose.yml"
  - "Test/Case/CiWorkflowTest.php"
  - "Test/README.md"
  - ".github/workflows/hermetic-tests.yml"
  - ".github/workflows/live-server-tests.yml"
severity: medium
symptoms:
  - "Hermetic tests run #47 on main failed in its first seconds, before any test ran, on a merge commit that changed only Markdown"
  - "Log: comanage-registry Error toomanyrequests: Data limit exceeded, then Error response from daemon: toomanyrequests: Data limit exceeded"
  - "Scheduled Live-server tests run #39 failed the same way the same morning"
  - "Pull request run #46 on the identical commit pulled the same image and passed three minutes before #47 failed"
root_cause: config_error
resolution_type: config_change
tags: [ci, github-actions, docker, ecr-public, ghcr, rate-limit, image-pinning, flaky-ci, oa4mp]
---

# Anonymous ECR Public pulls fail the gate intermittently; pull the same digest from GHCR

## Problem

The hermetic test environment pulled the COmanage Registry image
anonymously from `public.ecr.aws/cilogon/comanage-registry`, pinned by digest.
On 2026-09-28 two runs failed before any test ran because the image pull
was refused:

```
comanage-registry Error toomanyrequests: Data limit exceeded
Error response from daemon: toomanyrequests: Data limit exceeded
```

- Live-server tests run #39 (scheduled, 07:30Z).
- Hermetic tests run #47 (push to `main`, 14:55Z), on the merge of
  `cilogon/Oa4mpClient#29`, a change to Markdown only.

Pull request run #46 on the same commit had pulled the same image and passed
at 14:52Z. The failure depended on which runner picked up the job, not on the
code.

## Root cause

ECR Public limits how much data anonymous clients can pull. GitHub-hosted
runners share IP addresses with many other workloads, so a job that lands on
a runner whose quota is already used up is refused. The workflow cannot log
in to raise the quota: the hermetic gate uses no secrets by design, so it can
gate fork pull requests, and `CiWorkflowTest::testHermeticGateUsesNoSecrets`
enforces that. A `docker login` step would have helped only on `main`, never
on fork pull requests.

## Solution

Copy the exact image to a public GHCR package under `cilogon` and change
only the registry in the compose default, keeping the digest:

```yaml
# Before
image: ${OA4MP_TEST_REGISTRY_IMAGE:-public.ecr.aws/cilogon/comanage-registry@sha256:e41c82a9...79d9}
# After
image: ${OA4MP_TEST_REGISTRY_IMAGE:-ghcr.io/cilogon/comanage-registry@sha256:e41c82a9...79d9}
```

The copy was made with:

```bash
docker buildx imagetools create \
  --tag ghcr.io/cilogon/comanage-registry:oa4mp-test-e41c82a \
  public.ecr.aws/cilogon/comanage-registry@sha256:e41c82a9148757bd310351ffdac8f3bdd10a6a37c8865f9c98ceb7bc319279d9
```

and the package's visibility was set to public so runners can pull it with
no credentials. Both tiers use the same compose file, so both are fixed.

## What was surprising

`docker buildx imagetools create` does not copy a single-platform manifest
as-is. The source is a plain `manifest.v2`; the tag on GHCR points at a new
`manifest.list.v2` (digest `sha256:45d342c0...`) whose only entry is the
original `linux/amd64` manifest `sha256:e41c82a9...`. The original manifest is
kept unchanged, so pulling by its digest still works and returns the same
bytes. That was checked anonymously against the GHCR registry API: the
manifest fetched by `e41c82a9...` hashed back to `e41c82a9...`, and a layer
blob returned 200.

Pin the original digest, not the list's. Then the diff shows only the
registry changing and the content provably unchanged. `crane copy` or
`skopeo copy` copy the manifest as-is if the wrapper is unwanted.

## Verification

- `CiWorkflowTest::testRegistryImageIsPulledFromGhcrByDigest` failed against
  the pre-fix compose file (302 tests run, 1 failed) and passes after it
  (302 tests run, 0 failed), with the image pulled from `ghcr.io`.
- The pull request's own hermetic run pulls the image anonymously from GHCR
  with no secrets, the same way a fork pull request will.

## Prevention

- The test above keeps the default Registry image on GHCR, pinned by digest,
  and rejects any default pulled from `public.ecr.aws`.
- Bumping the pin is now two steps: copy the new digest to GHCR, then change
  the default. `Test/README.md`, "Bumping the Registry image", has the
  commands.
- A pull failure of this kind stops the run in its first seconds, before any
  test runs. Read the log before treating a red run on a docs-only change as
  a regression.

## Related

- `docs/solutions/integration-issues/oa4mp-gitleaks-secret-scan-usedefault-trap-2026-08-22.md`,
  the other learning about this gate's wiring.
