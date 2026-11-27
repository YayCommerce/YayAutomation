#!/usr/bin/env bash
# PreToolUse(Bash), and the Desk's lanes.gate: a merge with nothing for the user to review runs
# unprompted (a plan PR into a lane or review branch, a PR into develop that changes no plugin code).
# Every other merge asks. Settings rules see only the command text, not the PR's base or files, hence a hook.
raw=$(jq -r '.tool_input.command // empty')
[[ "$raw" == *"gh pr merge"* ]] || exit 0

decide() {
  jq -n --arg d "$1" --arg r "$2" \
    '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:$d,permissionDecisionReason:$r}}'
  exit 0
}

# Quoted text is an argument (a Builder's prompt names the merge command), unless a shell runs it.
cmd=$(sed -E "s/'[^']*'//g; s/\"([^\"\\\\]|\\\\.)*\"//g" <<<"$raw")
if [[ "$cmd" != *"gh pr merge"* ]]; then
  [[ "$cmd" =~ (^|[^a-z])((sh|bash|zsh)[[:space:]]+-[a-z]*c|eval|xargs)([^a-z]|$) ]] && decide ask "Merge run through a shell: confirm."
  exit 0
fi

# One merge per command, nothing chained, so the base check covers everything that runs.
# `2>&1` neither chains nor backgrounds; one leading plain `cd <dir>;` runs nothing, so it is dropped.
cmd=$(sed -E 's/^[[:space:]]*cd[[:space:]]+[^;&|`$()<>]*(;|&&)[[:space:]]*//' <<<"$cmd")
chain=$(sed -E 's/[0-9]*>&[0-9]+//g' <<<"$cmd")
[[ $(grep -o 'gh pr merge' <<<"$cmd" | wc -l) -eq 1 && "$chain" != *[\;\|\&\`]* && "$chain" != *'$('* ]] ||
  decide ask "Merge in a compound command: confirm."

read -ra words <<<"${cmd#*gh pr merge}"
pr=""
for ((i = 0; i < ${#words[@]}; i++)); do
  case "${words[i]}" in
    -t | --subject | -b | --body | -F | --body-file | -A | --author-email | --match-head-commit) ((i++)) ;;
    -*) ;;
    *) pr="${words[i]}"; break ;;
  esac
done
[[ -n "$pr" ]] || decide ask "Merge with no PR named: confirm."
[[ " ${words[*]} " == *" --merge "* || " ${words[*]} " == *" -m "* ]] ||
  decide ask "Not a merge commit (plans merge with --merge): confirm."

info=$(gh pr view "$pr" --json baseRefName,headRefName,changedFiles,files 2>/dev/null)
base=$(jq -r '.baseRefName // empty' <<<"$info")
head=$(jq -r '.headRefName // empty' <<<"$info")
# Hooks, settings and workflows change what runs unprompted, so they always ask.
paths=$(jq -r '.files[]?.path' <<<"$info")
grep -qE '^\.claude/(hooks|settings)|^\.github/' <<<"$paths" && decide ask "Changes hooks, settings or workflows: confirm."
[[ "$base" == feature/lane-* || "$base" == review/* ]] && decide allow "Plan PR into $base."
[[ "$base" == develop ]] || decide ask "Merge into ${base:-unknown base}: confirm."
[[ "$head" == review/* ]] && decide ask "Review PR into develop: the user's merge."

# Nothing to review: every file is a test, doc, plan or skill. `files` stops at 100, hence the count check.
listed=$(jq '.files | length' <<<"$info")
[[ -n "$listed" && "$listed" -gt 0 && "$listed" == "$(jq '.changedFiles' <<<"$info")" ]] ||
  decide ask "Merge into develop: confirm."
other=$(grep -vE '^tests/|^dev/e2e/|^\.claude/(docs|plans|skills)/|\.md$' <<<"$paths")
[[ -z "$other" ]] && decide allow "PR into develop changes no plugin code."
decide ask "Merge into develop: confirm."
