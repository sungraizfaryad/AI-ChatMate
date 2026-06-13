# Conciera — Plugin Working Notes

Read this BEFORE touching code. It captures what the codebase looks like, why
specific decisions were made, and the hard-won gotchas that aren't visible
from a `grep`. If something here disagrees with the current source, trust the
source and update this file.

## What this plugin is

WP.org submission name: **Conciera - AI Site Search & Content Finder**
(folder, slug, text-domain, and `aicm_` PHP prefix are intentionally still
`ai-chatmate` — keeping them locks our WP.org reservation and avoids breaking
existing API keys / settings). GitHub:
`https://github.com/sungraizfaryad/AI-ChatMate`.

A WordPress plugin that adds an opt-in, RAG-powered chat assistant to any
site. It indexes posts/CPTs into a `wp_aicm_chunks` table, retrieves relevant
chunks at chat time, and lets the model call `search_posts` / `capture_lead`
/ `suggest_choices` AI functions. The site owner gets a tabbed admin UI,
manual indexing controls, file-based chat logs, and email lead capture.

## Repo layout

- This folder (`media-usage-inspector/.../plugins/ai-chatmate/`) IS the canonical
  git repo. No separate canonical-vs-test split (unlike UNMAM).
- FLP install (`~/Local Sites/flp/app/public/wp-content/plugins/ai-chatmate/`) is
  test-only; rsync to it after every change. Real 4,160-property dataset.
- Build zip lives at `~/Desktop/conciera-2.0.0.zip` (Plugin Check 0 errors).

## Don't trip these mines

- **Never run `sanitize_text_field` on text that may contain `<`**. WordPress
  treats `<=` as an unclosed HTML tag and strips it to `=`. This was the
  "no properties in Spain under 2M" bug. See `class-aicm-query-builder.php`
  `normalize_numeric()` and the compare-operator whitelist.
- **Never use `GLOB_BRACE`**. Undefined on musl-libc PHP (Alpine images) → fatal
  mid-uninstall. Use `glob('*')` + explicit checks.
- **Never derive the log-dir name from `wp_salt()`**. Salt rotation orphans
  the directory. We store a random key in option `aicm_log_dir_key`.
- **Never trust the model to write a choice list as text**. gpt-4o-mini ignores
  the "use suggest_choices" instruction maybe 30% of the time. The fix is
  belt-and-braces: forced `tool_choice` on zero-result round 2 + deterministic
  bullet→chips fallback in `extract_text_choices()`.
- **Enqueue assets with `filemtime()`, not `AICM_VERSION`**. Visitors aggressively
  cache `aicm-widget.js`; releasing a new feature without filemtime versioning
  silently dropped chips/history for returning visitors.
- **Round 2 of a function call passes ONLY `suggest_choices`** to prevent the
  model chaining another search after seeing the result.

## How to develop here (Local by Flywheel)

```sh
# Sungraiz's recipe for WP-CLI through Local's bundled PHP:
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
WP="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"

# Run-IDs are dynamic — find by grepping the Local run config:
RUN="/Users/sungraizfaryad/Library/Application Support/Local/run"
SITE_RID() { grep -rl "$1" "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | sort -u | head -1; }
# media-usage-inspector at time of writing: H5cgHPi5B
# flp:                                       8-CWukao6

SOCK="$RUN/$(SITE_RID flp)/mysql/mysqld.sock"
$PHP -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
    $WP --path="/Users/sungraizfaryad/Local Sites/flp/app/public" plugin list
```

FLP auto-login (admin, no password — only over plain HTTP, https drops the param):
`http://flp.local/?localwp_auto_login=36`. wps-hide-login + WP Rocket installed there.

When testing leads or other email flows on FLP, drop in this mu-plugin first —
wp-mail-smtp is configured and WILL send real mail otherwise:

```php
// /wp-content/mu-plugins/aicm-test-mail-intercept.php (DELETE WHEN DONE)
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    file_put_contents( '/tmp/aicm-intercepted-mail.json',
        wp_json_encode( $atts, JSON_PRETTY_PRINT ) . "\n---\n", FILE_APPEND );
    return true;
}, 10, 2 );
```

## Architecture map

- `includes/class-aicm-conversation-handler.php` — orchestrator. Builds system
  prompt, calls provider, handles function-call branches, owns prompt rules
  for brevity / chips / lead capture. `extract_text_choices()` is the safety
  net for the chips system.
- `includes/class-aicm-query-builder.php` — translates `search_posts` args into
  `WP_Query`. Owns the operator whitelist, numeric normaliser, NUMERIC-type
  inference, underscore-meta rejection, and `zero_results_help()`.
- `includes/class-aicm-index-manager.php` — manual + background indexing. Has
  `enqueue_full_reindex(bool $only_new)`, `ensure_cron()` self-repair,
  loopback chain via admin-ajax + hash_equals.
- `includes/class-aicm-leads.php` — opt-in callback capture. PHP owns
  `is_email`, session lock, daily cap, mail headers.
- `includes/class-aicm-chat-log.php` — JSONL daily logs in a protected uploads
  subdir, admin-only download.
- `includes/class-aicm-rest-api.php` — `/chat`, `/index/*`, `/settings`.
  `options` key in response carries quick-reply chips. Dual nonce
  (`X-WP-Nonce` + `X-AICM-Nonce`).
- `public/js/aicm-widget.js` — vanilla. localStorage `aicm_chats_v1`
  (10 chats × 80 msgs), per-chat server `session_id`, chips render via
  `renderChips()`, init runs on `DOMContentLoaded` because markup prints at
  `wp_footer` priority 100.
- `admin/views/settings.php` — six tabs, submenu is registered LAST.
- `uninstall.php` — multisite-safe; calls `AICM_Chat_Log::delete_all()` inside
  the per-site function.

## Tests

```sh
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
$PHP vendor/bin/phpunit --no-coverage
```

Brain Monkey + PHPUnit 11. `tests/bootstrap.php` stubs WP constants
(`MINUTE_IN_SECONDS`, `DAY_IN_SECONDS`, `ABSPATH`, `AICM_PLUGIN_DIR`). When
stubbing `sanitize_text_field` for a new test, use
`static fn( $v ) => trim( strip_tags( (string) $v ) )` — the pass-through stub
HID the operator bug.

48 tests / 126 assertions as of last commit (261a7f1). Plugin Check on the
zipped build must report **0 errors**.

## Build & ship

```sh
SRC="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"
BUILD=/tmp/conciera-build
ZIP="$HOME/Desktop/conciera-2.0.0.zip"
rm -rf "$BUILD" && mkdir -p "$BUILD/ai-chatmate"
cp -R "$SRC"/. "$BUILD/ai-chatmate"/
( cd "$BUILD/ai-chatmate" && rm -rf .git .github .gitignore .distignore \
    composer.json composer.lock phpunit.xml.dist phpcs.xml phpcs.xml.dist \
    vendor tests node_modules .phpunit.result.cache docs )
find "$BUILD" \( -name '.DS_Store' -o -name '.playwright-mcp' \) -exec rm -rf {} +
( cd "$BUILD" && zip -rqX "$ZIP" ai-chatmate )
```

Verify with `wp plugin check` on the unzipped copy.

## When in doubt

- See `progress.md` (this folder) for current status.
- See cloud memory at `~/.claude/projects/-Users-sungraizfaryad-Local-Sites-media-usage-inspector/memory/`
  — entries prefixed `project_conciera_*` / `reference_ai_chatmate_*` belong to
  this plugin. Entries prefixed with other plugin names (unmam, curator_ai,
  simple_wp_slider, remove_taxonomy_url) are siblings — don't touch them.
- `wp-admin/` and `wp-includes/` are WordPress core. Never edit.
