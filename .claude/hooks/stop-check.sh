#!/usr/bin/env bash
# Stop hook: build, lint, tests and PHP lint for this branch's changes (commits since develop + uncommitted).
# Skips a state that already passed. Prints only on failure; exit 2 blocks the stop with stderr as the reason.
root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
cd "$root" || exit 0
cache="$(git rev-parse --git-dir)/claude-stop-check.hash"

# Commits made during the turn count too, not only the working tree.
base=$(git merge-base HEAD origin/develop 2>/dev/null || git merge-base HEAD develop 2>/dev/null || git rev-parse HEAD)
untracked=$(git ls-files --others --exclude-standard)
changed=$({ git diff --name-only "$base"; printf '%s\n' "$untracked"; } | grep -v '^$' | sort -u)
[ -z "$changed" ] && exit 0

hash=$({
  git rev-parse HEAD
  git diff HEAD
  [ -n "$untracked" ] && printf '%s\n' "$untracked" | tr '\n' '\0' | xargs -0 cat 2>/dev/null
} | shasum | cut -d' ' -f1)
[ -f "$cache" ] && [ "$(cat "$cache")" = "$hash" ] && exit 0

fail=""
if echo "$changed" | grep -qE '^(apps|packages)/|^(package\.json|pnpm-lock\.yaml|pnpm-workspace\.yaml)$'; then
  if ! out=$(pnpm build 2>&1); then
    # Vite's message spans the lines after "error during build"; tsc prints one "error TS" line per problem.
    errs=$(echo "$out" | grep -A8 'error during build' | grep -vE '^[^ ]+ build: +at ' | head -25)
    [ -z "$errs" ] && errs=$(echo "$out" | grep -E 'error|Error' | head -25)
    fail+="Build failed:"$'\n'"${errs:-$(echo "$out" | tail -25)}"$'\n'
  fi
  # Same command as CI: eslint + prettier --check in every package.
  if ! out=$(pnpm lint 2>&1); then
    # Drop pnpm's "<pkg> lint:" prefix; keep ESLint errors under their file and Prettier's [warn] lines.
    errs=$(echo "$out" | sed -E 's/^[^ ]+ lint: //' | awk '
      /^\// { file = $0; next }
      / error / { if (file != shown) { print file; shown = file } print; next }
      /✖ .*\(0 errors/ { next }
      /^\[warn\]|✖|ERR_/ { print }' | head -25)
    fail+="Lint failed. Try: pnpm lint:fix"$'\n'"${errs:-$(echo "$out" | tail -25)}"$'\n'
  fi
  if ! out=$(pnpm test 2>&1); then
    errs=$(echo "$out" | grep -E 'FAIL|AssertionError|Error|×' | head -25)
    fail+="Tests failed:"$'\n'"${errs:-$(echo "$out" | tail -25)}"$'\n'
  fi
fi

php_files=$(echo "$changed" | grep -E '\.php$' | while read -r f; do [ -f "$f" ] && echo "$f"; done)
if [ -n "$php_files" ]; then
  php_out=$(echo "$php_files" | while read -r f; do php -l "$f" 2>&1 | grep -v '^No syntax errors'; done)
  [ -n "$php_out" ] && fail+="PHP syntax failed:"$'\n'"$php_out"$'\n'

  # Unit tests whenever PHP changes. Skipped until `./run.sh setup` installs the tools.
  phpunit=dev/tools/phpunit/vendor/bin/phpunit
  if [ -z "$php_out" ] && [ -x "$phpunit" ] && ! out=$($phpunit 2>&1); then
    fail+="PHP tests failed:"$'\n'"$(echo "$out" | grep -vE '^\s*$' | tail -25)"$'\n'
  fi

  # Coding standard, changed files only. Skipped until `./run.sh setup` installs the tools.
  # scoper.inc.php is skipped: yaycommerce-prerelease rewrites it in its own format on every release.
  phpcs=dev/tools/lintphp/vendor/bin/phpcs
  cs_files=$(echo "$php_files" | grep -vx 'scoper.inc.php')
  if [ -z "$php_out" ] && [ -x "$phpcs" ] && [ -n "$cs_files" ]; then
    # shellcheck disable=SC2086
    if ! cs_out=$($phpcs -q --report=emacs $cs_files 2>&1); then
      fail+="PHP lint (phpcs) failed. Try: pnpm lint:php:fix"$'\n'"$(echo "$cs_out" | head -25)"$'\n'
    fi
  fi
fi

if [ -n "$fail" ]; then
  printf '%s' "$fail" >&2
  exit 2
fi
echo "$hash" > "$cache"
