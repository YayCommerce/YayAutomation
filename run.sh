#!/bin/bash
# Single entry point for team scripts in dev/scripts/.
#   ./run.sh                 list commands
#   ./run.sh <cmd> [args]    run dev/scripts/<cmd>.sh
#   ./run.sh <alias> [args]  short name, see ALIASES below
cd "$(dirname "$0")" || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

# Personal settings: created once from the example, then yours to edit.
if [[ ! -f dev/.env.local ]]; then
  cp dev/.env.example dev/.env.local
  echo "Created dev/.env.local — edit to customize."
fi
# Load KEY=value lines; a variable already set in the shell wins.
while IFS='=' read -r key val; do
  [[ $key =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
  val=${val%%#*}
  val=${val%"${val##*[![:space:]]}"}
  [[ -z ${!key+x} ]] && export "$key=$val"
done < dev/.env.local

# Short aliases: alias=command
ALIASES=(cw=claude-workflow)

if [[ -z $1 ]]; then
  echo "Usage: ./run.sh <command> [args]"
  for f in dev/scripts/*.sh; do
    name=$(basename "$f" .sh)
    for a in "${ALIASES[@]}"; do
      [[ ${a#*=} == "$name" ]] && name+=" (${a%%=*})"
    done
    printf "  %-22s %s\n" "$name" "$(sed -n '2s/^# //p' "$f")"
  done
  exit 0
fi

for a in "${ALIASES[@]}"; do
  [[ $1 == "${a%%=*}" ]] && set -- "${a#*=}" "${@:2}"
done

script="dev/scripts/$1.sh"
[[ -f $script ]] || { echo "Unknown command: $1. Run ./run.sh to list commands."; exit 1; }
shift
exec "$script" "$@"
