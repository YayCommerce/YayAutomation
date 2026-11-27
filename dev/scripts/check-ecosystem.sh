#!/bin/bash
# Run the email stack's cells (docs/extensions-roadmap.md) on a real site, an installed product in place of its scaffold (wp-cli).
#   ./run.sh check-ecosystem [--wp-env|--wp-env-min]
# The scaffolds load in dev mode: on the local site wp-config.php needs YAYMATIK_IS_DEVELOPMENT. No email is sent.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1
# wp-env's wp-config has no YAYMATIK_IS_DEVELOPMENT: each wp-cli process defines it, so the scaffolds load there too.
[[ $1 == --wp-env* ]] && WP+=(--exec="define( 'YAYMATIK_IS_DEVELOPMENT', true );")

products=(yaymail yaymagic yaysmtp)
# A product installed as a plugin replaces its scaffold, whose class names its build declares too.
installed=()
was_active=()
for p in "${products[@]}"; do
  if "${WP[@]}" plugin is-installed "$p" 2>/dev/null; then
    installed+=("$p")
    "${WP[@]}" plugin is-active "$p" 2>/dev/null && was_active+=("$p")
  fi
done
# The installed products as they were, also after a failed cell.
restore() {
  local p
  for p in "${installed[@]}"; do
    if [[ " ${was_active[*]} " == *" $p "* ]]; then "${WP[@]}" plugin activate "$p" >/dev/null; else "${WP[@]}" plugin deactivate "$p" >/dev/null; fi
  done
}
trap restore EXIT

# Puts the site in a cell's state: the listed products on, the others off. Prints the --exec that leaves scaffolds out.
site() {
  local on=" $* " off=() p
  for p in "${products[@]}"; do
    if [[ " ${installed[*]} " == *" $p "* ]]; then
      off+=("$p")
      if [[ $on == *" $p "* ]]; then "${WP[@]}" plugin activate "$p" >/dev/null; else "${WP[@]}" plugin deactivate "$p" >/dev/null; fi
    elif [[ $on != *" $p "* ]]; then
      off+=("$p")
    fi
  done
  local IFS=,
  echo "define( 'YAYMATIK_DEV_ECOSYSTEM_OFF', '${off[*]}' );"
}
phase() {
  local exec=$1
  shift
  "${WP[@]}" --exec="$exec" eval-file dev/scripts/check-ecosystem.php "$@"
}

results=()
step() {
  local label=$1
  shift
  if "$@"; then results+=("PASS $label"); else results+=("FAIL $label"); fi
}

step "setup" phase "$(site "${products[@]}")" setup
for source in core yaymail yaymagic; do
  others=()
  for p in yaymail yaymagic; do [[ $p != "$source" ]] && others+=("$p"); done
  for smtp in on off; do
    for other in on off; do
      removed_cases=(no)
      [[ $source != core ]] && removed_cases+=(yes)
      for removed in "${removed_cases[@]}"; do
        on=()
        [[ $source != core && $removed == no ]] && on+=("$source")
        [[ $other == on ]] && on+=("${others[@]}")
        [[ $smtp == on ]] && on+=(yaysmtp)
        label="$source, smtp $smtp, $(IFS=+; echo "${others[*]}") $other$([[ $removed == yes ]] && echo ', removed')"
        step "$label" phase "$(site "${on[@]}")" cell "$source" "$(IFS=,; echo "${on[*]}")"
      done
    done
  done
done
step "cleanup" phase "$(site "${products[@]}")" cleanup

echo
printf '%s\n' "${results[@]}"
for p in "${products[@]}"; do
  [[ " ${installed[*]} " == *" $p "* ]] && echo "$p: installed" || echo "$p: scaffold"
done
[[ " ${results[*]} " != *" FAIL "* ]]
