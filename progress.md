# Conciera — Progress

_Last updated: 2026-06-12 (Fable 5 session)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (v2.0.0, on GitHub `main`)

- WP.org rejection fixes: rename → Conciera, exclusivity claim removed, inline scripts enqueued.
- Search correctness: `<=` operator bug, number normaliser ("2 million" → 2000000, European commas), underscore-meta rejection, zero-result enrichment.
- Indexing: manual runs, background loopback chain, 45s stall watchdog, cron self-repair, post-type activity feed, incremental vs full scope.
- Widget gated until first index completes. Asset URLs versioned by `filemtime`.
- Chat history: localStorage (10 chats × 80 msgs), server `session_id` per chat, New / Previous UI.
- Quick-reply chips: `suggest_choices` AI fn, forced `tool_choice` on zero-results, deterministic bullet→chips fallback.
- Lead capture: opt-in, `is_email`, 1/session + 20/day caps, Reply-To = visitor.
- File chat logs: protected uploads dir (random key, salt-rotation-proof), 30-day rotation, admin download.
- Settings: 6 tabs, submenu last, site-context textarea (also in wizard).
- 48 tests / 126 assertions. Plugin Check 0 errors. Build at `~/Desktop/conciera-2.0.0.zip` (158K).
- Pushed 7 logical commits to `github.com/sungraizfaryad/AI-ChatMate`.

## Decisions

- Folder / slug / text-domain / `aicm_` prefix stay despite the display rename — preserves WP.org reservation and stored API keys.
- Chat history lives in localStorage, not on the server (Sungraiz's call, correct one).
- File chat logs live in `wp-content/uploads/conciera-logs-<random>/`, NOT in the plugin dir (plugin dir is wiped on update + publicly readable).
- Round 2 after a search passes only `suggest_choices` to the model so it cannot chain another search.

## Next steps

1. Sungraiz currently testing on FLP — wait for feedback.
2. Update `readme.txt`: document new features + privacy disclosure for lead capture and file logging before WP.org resubmission.
3. Send the drafted reviewer reply email after readme update.
4. (Deferred) `aicm_` / `ai_` → `conciera_` PHP prefix rename. Owner explicitly postponed.

## Key files

- `includes/class-aicm-conversation-handler.php` — prompt + function-call orchestrator.
- `includes/class-aicm-query-builder.php` — operator whitelist, numeric normaliser, zero-result help.
- `includes/class-aicm-index-manager.php` — manual + background indexing + self-repair.
- `includes/class-aicm-leads.php` — callback capture.
- `public/js/aicm-widget.js` — localStorage history, chips, dual-nonce REST.
- `tests/unit/` — QueryBuilderTest, LeadsTest, ChoicesTest, FrontendReadyTest.
