#!/bin/bash
# Single entry point for team scripts in dev/scripts/.
#   ./run.sh               list commands
#   ./run.sh <cmd> [args]  run dev/scripts/<cmd>.sh
#   ./run.sh cw            alias for claude-workflow
cd "$(dirname "$0")" || exit 1
[[ -f yay-automation.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

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

if [[ -z $1 ]]; then
  echo "Usage: ./run.sh <command> [args]"
  for f in dev/scripts/*.sh; do
    printf "  %-16s %s\n" "$(basename "$f" .sh)" "$(sed -n '2s/^# //p' "$f")"
  done
  echo "Aliases: cw = claude-workflow"
  exit 0
fi

# Short aliases: alias=command
case $1 in
  cw) set -- claude-workflow "${@:2}" ;;
esac

script="dev/scripts/$1.sh"
[[ -f $script ]] || { echo "Unknown command: $1. Run ./run.sh to list commands."; exit 1; }
shift
exec "$script" "$@"
