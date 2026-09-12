---
name: note-finding
description: Append a one-line finding to .claude/findings.md — something noticed mid-task that isn't this task's job (a bug, a risk, tech debt, an open question). Plan-agnostic, not tied to any specific plan file. Use when the user says "note this finding", "log this", "add to findings", or when you notice something worth flagging but out of scope for the current change. Also use to recall open findings before starting related work or during triage.
---

# Note Finding

`.claude/findings.md` is a flat, plan-agnostic backlog of things noticed in
passing while doing other work — the "fine, no X existed before either (bug,
unrelated, out of scope)" kind of remark that's worth keeping but not worth
derailing the current task for. Not checked against `.ai/rules/` (durable
codebase facts) or a Plan (task tracking) — this is triage input, closer to
a personal GitHub issue queue than either.

## Recording a finding

The input to this skill is a raw observation, not a pre-formatted line —
treat write-up as your job, not the caller's.

1. Read the observation and, if it names code just looked at in this
   session, resolve it to `path:line`. Don't go hunting for it if it wasn't
   already in context — a finding without a location is still valid.
2. Infer the tag from the observation's content, not from any word the
   caller happened to use for it. Exactly one of:
   - `bug` — genuinely wrong, unrelated to the task at hand
   - `out-of-scope` — correct behavior, just not this task's job
   - `tech-debt` — works, but shape/quality issue worth cleanup later
   - `question` — unclear intent, needs a human/team decision before acting
   - `risk` — not broken yet, but fragile under some future condition
   - `todo` — known follow-up, no ambiguity, just deferred
   - `duplicate` — already tracked elsewhere; note the pointer instead of
     re-describing it, e.g. `— duplicate — see .ai/rules/tenant-caching.md`
   `bug`, `risk`, and `tech-debt` name what the thing *is*; `out-of-scope`,
   `todo`, and `question` name why it's not being handled now — those are
   different axes, not competing choices. When both apply (a real bug that
   also happens to be out of scope for the current task), tag with what it
   is, and put the scoping reason in the trailing "why not now" clause
   instead of letting it override the tag. Reach for `out-of-scope`/`todo`
   as the tag only when there's no defect underneath — the thing is correct
   or fine, just not this task's job.
3. Compress to one line, cutting hedging and restating the concrete claim:
   ```
   - YYYY-MM-DD: <finding, with path:line if resolved> — <tag> — <why not now>
   ```
4. Show the caller the exact line before writing it, and wait for
   confirmation or a correction (usually to the tag) before appending. Skip
   this step only if the caller already supplied the tag and phrasing
   themselves — then there's nothing to confirm.
5. Append to `.claude/findings.md`, creating it with a one-line header if it
   doesn't exist yet.
6. Don't dedupe automatically — a human triage pass (see below) is what
   collapses repeats. Do skip logging something you can see is already in
   the file verbatim.

## Recalling findings

Read `.claude/findings.md` before starting related work if the task touches
an area with open findings. Treat every entry as a live claim, not settled
truth — verify against current code before acting on an old one.

## Triage

Findings aren't durable rules and aren't meant to accumulate forever. When
the user wants to clear the backlog: read each line, and for each either
open a GitHub issue for it (`gh issue create`) and delete the line, fold it
into a rule via the `codebase-learnings` skill if it's a durable fact, or
delete it if it no longer applies. This skill only appends; it never
triages unprompted.
