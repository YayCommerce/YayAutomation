#!/bin/zsh
# Claude work menu: tmux + Remote Control sessions (macOS)
# One tmux session per repo; every Claude session runs in a tmux window
# with Remote Control on, so it is reachable from claude.ai/code and mobile.

# Settings come from dev/.env.local via ./run.sh (see dev/README.md).
DEVICE=${CW_DEVICE:-work}            # shown in the app: <repo>-<device>
MODE=${CW_MODE:-default}             # permission mode for sessions

for c in git tmux claude; do
  command -v $c >/dev/null || { echo "Missing: $c"; exit 1; }
done

ROOT=$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null) || { echo "Not in a git repo."; exit 1; }
ROOT=${ROOT%/.git}
REPO=$(basename "$ROOT")
TS=$(echo "$REPO" | tr 'A-Z.' 'a-z-')   # tmux session name
SERVER="$TS-$DEVICE"

ensure_tmux() { tmux has-session -t "$TS" 2>/dev/null || tmux new-session -d -s "$TS" -n home -c "$ROOT"; }
has_window() { tmux list-windows -t "$TS" -F '#W' 2>/dev/null | grep -qx "$1"; }
# Each terminal gets its own session in a group with $TS: same windows,
# independent current window. Auto-removed when the terminal detaches.
attach() {
  local -a sel; [[ -n $1 ]] && sel=(';' select-window -t "$1")
  tmux new-session -t "$TS" -s "$TS-$$" ';' set-option destroy-unattached on $sel
}
slug() { echo "$1" | sed -E 's#^(feature|fix|dev)/##; s#[^A-Za-z0-9]+#-#g' | tr 'A-Z' 'a-z'; }

open_window() {   # name dir command
  ensure_tmux
  if has_window "$1"; then echo "Window '$1' already exists."; else
    tmux new-window -d -t "$TS" -n "$1" -c "$2" "$3; exec zsh"
    echo "Started '$1' in $2"
  fi
  printf "Attach now? [Y/n] "; read -r a
  [[ $a == [nN]* ]] || attach "$1"
}

pick_worktree() {
  local -a paths
  paths=(${(f)"$(git -C "$ROOT" worktree list --porcelain | sed -n 's/^worktree //p')"})
  PS3="Worktree #: "
  select WT in $paths; do [[ -n $WT ]] && break; done
}

start_server() {
  ensure_tmux
  command -v caffeinate >/dev/null && { has_window awake || tmux new-window -d -t "$TS" -n awake 'caffeinate -i'; }
  local cmd="claude remote-control --name $SERVER --spawn worktree --permission-mode $MODE; exec zsh"
  if ! has_window server; then
    tmux new-window -d -t "$TS" -n server -c "$ROOT" "$cmd"
  elif [[ $(window_state "$(tmux display -p -t "${TS}:server" '#{pane_pid}')") == done ]]; then
    tmux respawn-window -k -t "${TS}:server" -c "$ROOT" "$cmd"   # claude exited: restart it
  fi
  sleep 3
  if [[ $(window_state "$(tmux display -p -t "${TS}:server" '#{pane_pid}')") == done ]]; then
    echo "Server failed to start:"; tmux capture-pane -p -t "${TS}:server" | grep -v '^$' | tail -5
    return 1
  fi
  echo "Server '$SERVER' running (tmux: $TS, window: server). Mac kept awake."
}

new_session() {
  printf "Task name (e.g. fix-stripe): "; read -r name; name=$(slug "$name"); [[ -n $name ]] || return
  open_window "$name" "$ROOT" "claude -w $name --remote-control $name --permission-mode $MODE"
}

branch_session() {
  printf "Branch (e.g. feature/site-import-export): "; read -r br; [[ -n $br ]] || return
  git -C "$ROOT" fetch -q origin
  local dir=$(git -C "$ROOT" worktree list --porcelain | awk -v b="refs/heads/$br" '/^worktree /{p=$2} $0=="branch "b{print p}')
  if [[ -z $dir ]]; then
    dir="$ROOT/.claude/worktrees/$(slug "$br")"   # inside the plugin, so WP doesn't list it as a plugin
    git -C "$ROOT" worktree add "$dir" "$br" || return
  else
    echo "Using existing worktree: $dir"
  fi
  local name=$(slug "$br")
  open_window "$name" "$dir" "claude --remote-control $name --permission-mode $MODE"
}

here_session() {
  local br=$(git branch --show-current)
  local name="here-$(slug "${br:-detached}")"
  open_window "$name" "$PWD" "claude --remote-control $name --permission-mode $MODE"
}

resume_session() {
  echo "Close the session in the app first, so it doesn't run twice."
  pick_worktree
  local name="resume-$(basename "$WT")"
  open_window "$name" "$WT" "claude --resume --remote-control --permission-mode $MODE"
}

# Window is "done" when its shell has no child left (claude exited).
window_state() { pgrep -P "$1" >/dev/null && echo running || echo done; }

status() {
  echo "== tmux windows ($TS)"
  tmux list-windows -t "$TS" -F '#W #{pane_pid} #{pane_current_path}' 2>/dev/null |
    while read -r w pid dir; do printf "  %-8s %-34s %s\n" "$([[ $w == (home|awake|server) ]] && echo - || window_state $pid)" "$w" "$dir"; done
  tmux has-session -t "$TS" 2>/dev/null || echo "  (not running)"
  echo "== worktrees";          git -C "$ROOT" worktree list | sed 's/^/  /'
  echo "== claude processes";   pgrep -fl "claude" | grep -v pgrep | sed 's/^/  /'
}

cleanup() {
  local -a finished
  local w pid
  tmux list-windows -t "$TS" -F '#W #{pane_pid}' 2>/dev/null | while read -r w pid; do
    [[ $w == (home|awake|server) ]] && continue
    [[ $(window_state $pid) == done ]] && finished+=($w)
  done
  if (( $#finished )); then
    echo "Finished windows:"; printf "  %s\n" $finished
    printf "Close them? [Y/n] "; read -r a
    [[ $a == [nN]* ]] || for w in $finished; do tmux kill-window -t "$TS:$w"; done
  else
    echo "No finished windows."
  fi
  git -C "$ROOT" worktree prune -v
}

PS3="Choose #: "
select c in \
  "Start server (mobile can spawn worktree sessions)" \
  "Session here (current folder and branch, no worktree)" \
  "New task session in a new worktree" \
  "Session on a branch (creates worktree if missing)" \
  "Resume a session in a worktree (e.g. started on mobile)" \
  "Status" \
  "Attach to tmux" \
  "Clean up finished sessions" \
  "Stop everything for this repo" \
  "Quit"; do
  case $REPLY in
    1) start_server ;;
    2) here_session ;;
    3) new_session ;;
    4) branch_session ;;
    5) resume_session ;;
    6) status ;;
    7) ensure_tmux; attach ;;
    8) cleanup ;;
    9) tmux list-sessions -F '#S' 2>/dev/null | grep -E "^$TS(-[0-9]+)?\$" | xargs -n1 tmux kill-session -t && echo "Stopped." ;;
    *) ;;
  esac
  break
done
