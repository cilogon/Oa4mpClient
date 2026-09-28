# CLAUDE.md

The working guidance for this repository lives in [AGENTS.md](AGENTS.md). Read
it and follow it — project overview, structure, coding style, do's and don'ts,
and the Git and pushing policy.

## Pushing (see AGENTS.md "Git, Remotes, and Pushing")

This repository is set up for the machine account `skoranda-agent`; the global
"Machine account" rules apply.

- `bot` -> `https://github.com/skoranda-agent/Oa4mpClient.git` (Claude pushes
  feature branches here).
- `upstream` -> `https://github.com/cilogon/Oa4mpClient.git` (canonical;
  pull requests target it).
- `origin` -> `https://github.com/skoranda/Oa4mpClient.git` (the developer's
  personal fork; Claude does not push here).

Shipping flow: push the feature branch to `bot`, then open a ready-for-review
pull request with
`gh pr create --repo cilogon/Oa4mpClient --base main --head skoranda-agent:<branch>`.
Before any push, confirm `gh api user --jq .login` prints `skoranda-agent` and
confirm the `bot` URL with `git remote -v`.

Never push to `upstream` or `origin`, never push `main` anywhere, and never
approve or merge a pull request.
