# CLAUDE.md

The working guidance for this repository lives in [AGENTS.md](AGENTS.md). Read
it and follow it — project overview, structure, coding style, do's and don'ts,
and the Git and pushing policy.

## Pushing (see AGENTS.md "Git, Remotes, and Pushing")

This is a fork-based repository: `origin` is the developer's fork, `upstream` is
the canonical `cilogon/Oa4mpClient`.

- Push to the fork (`origin`) is allowed **only when `GH_TOKEN` is defined**;
  otherwise commit locally and let the developer push.
- **Never push to `upstream`** — not by remote name and not by URL
  (`https://github.com/cilogon/Oa4mpClient`), regardless of `GH_TOKEN` or any
  request.

Confirm the target by URL with `git remote -v` before any push, not by remote
name alone.
