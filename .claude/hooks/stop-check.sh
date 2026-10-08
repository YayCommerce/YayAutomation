#!/usr/bin/env bash
# Stop hook: build, tests and PHP lint for uncommitted changes. Skips a change set that already passed. Prints only on failure.
root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
cd "$root" || exit 0
cache="$(git rev-parse --git-dir)/claude-stop-check.hash"

changed=$({ git diff --name-only HEAD; git ls-files --others --exclude-standard; } | sort -u)
[ -z "$changed" ] && exit 0

hash=$({ git rev-parse HEAD; git diff HEAD; git ls-files -o --exclude-standard -z | xargs -0 cat 2>/dev/null; } | shasum | cut -d' ' -f1)
[ -f "$cache" ] && [ "$(cat "$cache")" = "$hash" ] && exit 0

fail=""
if echo "$changed" | grep -qE '^(apps|packages)/|^(package\.json|pnpm-lock\.yaml|pnpm-workspace\.yaml)$'; then
  if ! out=$(pnpm build 2>&1); then
    errs=$(echo "$out" | grep -E 'error|Error' | head -25)
    fail+="Build failed:"$'\n'"${errs:-$(echo "$out" | tail -25)}"$'\n'
  fi
  if ! out=$(pnpm test 2>&1); then
    errs=$(echo "$out" | grep -E 'FAIL|AssertionError|Error|✗|×' | head -25)
    fail+="Tests failed:"$'\n'"${errs:-$(echo "$out" | tail -25)}"$'\n'
  fi
fi

php_files=$(echo "$changed" | grep -E '\.php$' | while read -r f; do [ -f "$f" ] && echo "$f"; done)
if [ -n "$php_files" ]; then
  php_out=$(echo "$php_files" | while read -r f; do php -l "$f" 2>&1 | grep -v '^No syntax errors'; done)
  [ -n "$php_out" ] && fail+="PHP syntax failed:"$'\n'"$php_out"$'\n'

  # Coding standard, changed files only. Skipped until `./run.sh setup` installs the tools.
  phpcs=dev/tools/lintphp/vendor/bin/phpcs
  if [ -z "$php_out" ] && [ -x "$phpcs" ]; then
    # shellcheck disable=SC2086
    if ! cs_out=$($phpcs -q --report=emacs $php_files 2>&1); then
      fail+="PHP lint (phpcs) failed. Try: pnpm lint:php:fix"$'\n'"$(echo "$cs_out" | head -25)"$'\n'
    fi
  fi
fi

if [ -n "$fail" ]; then
  printf '%s' "$fail" | php -r 'echo json_encode(["decision" => "block", "reason" => stream_get_contents(STDIN)]);'
else
  echo "$hash" > "$cache"
fi
