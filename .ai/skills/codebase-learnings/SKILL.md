---
name: codebase-learnings
description: Record non-obvious, important things learned about this codebase (gotchas, invariants, cross-file traps, "why it's built this way") into .claude/rules/, organized by topic, each paired with a suggested better approach rather than just the gotcha. Use when the user says "remember this about the codebase", "note this for later", "record this learning", "add this to the rules", or after a debugging/review session surfaces a non-obvious fact worth not re-discovering. Also proactively offer to record one when you notice a hard-won fact mid-task, even without a trigger phrase. Also use to recall what's already known before starting related work.
---

# Codebase Learnings

`.claude/rules/` holds a project-versioned, per-topic record of things learned
about *this* codebase while working on it — the kind of fact that took real
investigation to find and would otherwise get re-discovered (or re-broken)
every time someone touches that area. It is checked into git, so it travels
with the repo for every contributor and every agent session, unlike personal
memory.

`CLAUDE.md` points here so these rules get read automatically at the start of
every session — see the pointer under "Codebase Rules" in `CLAUDE.md`.

## What belongs in `.claude/rules/`

- Race conditions / concurrency traps between specific files or code paths.
- Invariants that aren't visible from reading a single file (e.g. "these two
  actions must both stay idempotent because a third file calls them twice").
- The *why* behind a non-obvious design decision, when it isn't in a commit
  message or code comment.
- Gotchas that already caused a bug once and could recur.

## What does NOT belong here

- Anything obvious from reading the code once (normal architecture, naming,
  standard framework behavior).
- Git history / who-changed-what — `git log`/`git blame` are authoritative.
- Ephemeral task state, TODOs, or in-progress work — that's for the
  conversation or a Plan, not a durable rule.
- User preferences about how to collaborate — those go in the user's personal
  memory system (`~/.claude/projects/.../memory/`), not this repo-versioned one.

If in doubt: would a new contributor need this explained to them before
touching this area safely? If yes, it's a rule. If it's just "what the code
does," skip it — the code already says that.

## Recording a learning

1. Pick (or create) a topic file: `.claude/rules/<topic>.md`, kebab-case,
   scoped to a feature/subsystem (e.g. `tenant-provisioning.md`,
   `billing-webhooks.md`) — not one giant file, not one file per tiny fact.
2. Give new files a frontmatter header:
   ```markdown
   ---
   topic: <topic-slug>
   updated: <YYYY-MM-DD>
   ---
   ```
3. Write the learning as a short bullet: the fact/rule, concrete file paths
   involved, and *why* it matters (what breaks if ignored). Bump `updated`
   when editing an existing file.
4. **Propose a better approach.** A learning is usually a symptom of a
   design that's fighting itself (scattered exception-code sniffing instead
   of one lock, duplicated logic instead of a shared source of truth, a
   convention that only half the codebase follows). Don't just log the
   gotcha — think about what the code is actually trying to accomplish and
   whether there's a structurally simpler way to get there. Write this as a
   `## Suggested better approach` subsection under the relevant bullet:
   what to change, why it removes the whole class of problem (not just this
   instance), and the trade-off if there is one. Do not implement it
   unprompted — this is a recommendation for the user to accept, defer, or
   reject, not an invitation to refactor on the spot.
5. Update `.claude/rules/INDEX.md`: one line per file between the
   `<!-- topic-index:start -->` / `<!-- topic-index:end -->` markers,
   `- [file.md](file.md) — one-line hook`, under ~150 chars.
6. Never duplicate a rule — check the index first; extend the existing
   bullet/file instead of writing a near-duplicate one.

## Prompting the user to record

Don't wait only for explicit trigger phrases. Proactively offer to record a
learning (a short one-line offer, not a full write-up) whenever, in the
course of other work, you notice:

- A bug or design flaw that took real digging to find (not obvious from a
  single file read) — especially anything found during a review or debug
  session that you're about to explain to the user anyway.
- A concurrency/ordering assumption one file makes about another.
- The user correcting a misunderstanding you had about how a subsystem
  works, or explaining *why* something is built an unobvious way.
- You're about to give the same explanation you feel you've given before in
  this repo (a sign it should have been written down already).

Skip the offer for things that are obvious from the code, one-off/ephemeral
to this conversation, or already covered in an existing rules file. When in
doubt, offer — a declined offer costs nothing; a silently lost learning
costs a re-investigation later.

## Recalling learnings

Before starting non-trivial work in an area, skim `.claude/rules/INDEX.md`
and open any file whose topic overlaps. Treat it as historical: a rule that
names a specific function or file may be stale if that code has since moved —
verify with a quick grep before relying on it for anything the user will act
on, same as any other memory.

## Keeping it healthy

- If a rule turns out to be wrong or the code it describes changed
  incompatibly, fix or delete it in the same edit — don't leave stale rules
  next to correct ones.
- Prefer editing an existing topic file over creating a new one for a
  closely related fact.
