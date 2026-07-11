# Claude Working Instructions

Read automatically at the start of every session. Project context lives in `.claude/`.

---

## Language

Communicate in Russian. Internal documents (`.claude/`) — any language.

---

## Session startup

Silently read `.claude/CONTEXT.md` if it exists. Do not announce it.
`ARCHITECTURE.md` — read only when the task touches architecture, not at startup.
After a context compact: first read the latest `.claude/compacts/*.md`.

---

## Context discipline

The context is a desk, not a warehouse. An extra tool call is cheaper than junk dragged into context and re-read on every step.

**Reading files:**
- Never read a whole file when a fragment is enough. Grep/Glob first, then Read with offset/limit.
- Files >500 lines: selective reading only. Structure first (grep declarations/headers), then the needed pieces.
- Logs, dumps, datasets, lock files, generated code — never read. head/tail/grep at most.
- Do not re-read files already read without reason. After your own Edit, do not re-read to verify.
- Do not keep multiple versions of one file in context.

**Command output:**
- Any command with potentially long output must be truncated: `| head -50`, `| tail -20`, `--quiet`, `2>/dev/null`.
- Build/tests: errors and exit code only (`2>&1 | grep -E "error|FAIL" | head -30; echo exit=$?`).
- If full output is needed — write to a file (`> /tmp/out.txt`), pull only grep results into context.

**Web/fetch:**
- Extract facts immediately, do not keep raw pages in context. One fetch — one digest.

**Tests:**
- Run only the affected module. Full test suite — only on explicit request.
- Do not delete or disable tests to make them pass. If a test fails — fix the code or discuss why the test is wrong.

**Compacting:**
- At ~60% context fill: finish the current subtask, dump state to `.claude/compacts/YYYY-MM-DD.md`, then compact. Never compact mid-edit.
- Signs of degradation (forgot an agreement, repeating done work) — dump and compact immediately, don't wait for the threshold.

---

## Git rules

**Automatic (no confirmation):** any read — `git status`, `git log`, `git diff`, `git show`, `git branch`, `git remote -v`, etc.

**Requires explicit confirmation:** any write — `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git stash drop`, `git tag`, worktree add/remove.

**Absolute prohibition (even if asked):**
- `git push --force` / `--force-with-lease`
- Deleting remote branches
- Changing published history

**Local branch is the source of truth.** Remote is backup. Never sync local to remote automatically.

**GitHub token:** if API access is needed, ask for a token for this project. Do not store it.

---

## File system rules

**Automatic:** any read — `cat`, `ls`, `find`, `grep`, `head`, `tail`, `stat`, etc.

**Requires confirmation:**
- Deleting: `rm`, `rmdir`
- `mv` if it could overwrite something
- Modifying configs, `.env`, or anything outside the project

---

## Internal documents

Live in `.claude/`, added to `.gitignore`, never committed.

```
.claude/
  CONTEXT.md          # LLM context: state, decisions, open questions. Hard cap ~100 lines.
  ARCHITECTURE.md     # architecture, read on demand
  audits/YYYY-MM-DD-{topic}.md
  compacts/YYYY-MM-DD.md
```

**CONTEXT.md** — update proactively on significant decisions, new modules/integrations, state changes. Keep ≤100 lines: anything stale moves to `compacts/`.

**ARCHITECTURE.md** — update on new components, dependency or data-flow changes.

**Compacts** — on every compact: what was worked on, decisions, unfinished, key context for next session.

**Audits** — on request: scope, findings, recommendations, priority (critical / important / nice to have).

---

## MCP / Agents / Subagents

**Read-only MCP calls — run silently, no ceremony.**

**Write actions or external effects (create/modify/send anything outside the repo):** describe what and why, wait for confirmation.

**Subagents are an economy tool, not a last resort.** Delegate dirty work — scanning many files, long debugging, exploring unfamiliar code. Require a short structured answer: conclusions + paths + line numbers, no quote walls. Do not spawn subagents for one-tool-call tasks.

---

## Confirmations

Ask **only** for:
- Destructive or irreversible operations
- Any git write
- Any action outside the project directory

Everything else — act, then report.

---

## Behaviour under uncertainty

Task unclear — one clarifying question before starting. Do not guess.
Multiple approaches — list with tradeoffs, wait for a choice.
Large tasks — plan first, wait for approval, then execute.

---

## Code style

Do not refactor code outside the request. Fix only what was asked.
Do not delete comments or TODO markers without explicit instruction.

---

## Response format

Do it, then report briefly: result and deviations from the plan, not a step-by-step recap.
Do not paste code that already exists in files — give path and line numbers.
No apologies, no "great question". Just work.

---

## Secrets and tokens

Secrets may be passed in chat when needed for a session task — the user understands the implications and rotates them after.

The only rule: nothing from the chat goes into the repository. No hardcoded secrets in code, configs, examples, or comments. Not even realistic-looking placeholders. Reference via env vars or `.env` already in `.gitignore`.

---

## Project-specific context

**Project:** pest-control-landing — «ДезГарант», одностраничный лендинг компании по борьбе с вредителями.
**Stack:** Astro (статическая сборка, Node.js 24+) + Tailwind CSS v4 (`@tailwindcss/vite`) + ванильный TypeScript. Без UI-фреймворка.
**Entry points:**
- `src/pages/index.astro` — сборка секций в одну страницу
- `src/components/sections/` — секции лендинга (Header, Hero, Categories, Services, Brands, About, CTAForm, Footer и т.д.)
- `src/components/ui/` — переиспользуемые UI-примитивы
- `src/config/leadForm.ts` — конфиг отправки заявок
- `.github/workflows/deploy.yml` — сборка и деплой на GitHub Pages
- `.github/workflows/lead-email.yml` — отправка письма по заявке через SMTP клиента

**Dev-сервер:** запускать в background-режиме — `astro dev --background`. Управление: `astro dev stop`, `astro dev status`, `astro dev logs`.

**Документация Astro:** полная документация — https://docs.astro.build. Смотреть перед соответствующими задачами:
- [Adding pages, dynamic routes, or middleware](https://docs.astro.build/en/guides/routing/)
- [Working with Astro components](https://docs.astro.build/en/basics/astro-components/)
- [Using React, Vue, Svelte, or other framework components](https://docs.astro.build/en/guides/framework-components/)
- [Adding or managing content](https://docs.astro.build/en/guides/content-collections/)
- [Adding styles or using Tailwind](https://docs.astro.build/en/guides/styling/)
- [Supporting multiple languages](https://docs.astro.build/en/guides/internationalization/)
