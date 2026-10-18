# Prompt 00 — Establish the local baseline

You are continuing `trianity/laravel-mautic`, a public MIT Laravel package.
Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md, docs/ARCHITECTURE.md and .ai/ROADMAP.md.
Inspect the actual code, git status and CI before changing anything. Preserve local work.

Use PHP 8.5/Laravel 13 by preference, retain PHP 8.4/Laravel 12 support. Install stable
dependencies with Composer. Run composer validate --strict, composer test,
composer format:check and composer analyse (or composer check). Inspect the exact
resolved SDK before assuming endpoint methods, response shapes or exceptions.
Fix genuine foundation failures with focused regression tests; do not relax checks.
Review the GitHub compatibility matrix. Record actual versions and checks in STATE.

No stable release, production calls, upstream edits or Packagist submission. Do not
implement the remaining roadmap in this task. Report a concise baseline and the next
prompt. If a live test is impossible, state that explicitly instead of claiming it ran.
