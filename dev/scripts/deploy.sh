#!/bin/bash
# Build a Pro rc zip of this commit and install it on staging (STAGING_SSH, STAGING_URL in dev/.env.local).
set -eo pipefail
cd "$(dirname "$0")/../.."
[[ -n $STAGING_SSH && -n $STAGING_URL ]] || { echo "Set STAGING_SSH (user@host[:port]/wp/path) and STAGING_URL in dev/.env.local"; exit 1; }
[[ -z $(git status --porcelain) ]] || { echo "Refused: uncommitted changes. Commit first."; exit 1; }

wpr() { wp --ssh="$STAGING_SSH" "$@"; }
installed() { wpr plugin get yaymatik --field=version 2>/dev/null || true; }

home=$(wpr option get home)
[[ ${home%/} == "${STAGING_URL%/}" ]] || { echo "Refused: $STAGING_SSH is $home, not $STAGING_URL"; exit 1; }

# Active workflows fire on everyone's orders: none of that mail may leave staging.
wpr eval 'if ( false === has_filter( "pre_wp_mail", "yay_mail_log_capture" ) ) { WP_CLI::error( "Mail log mu-plugin is not active." ); }
  if ( has_filter( "woocommerce_mail_callback" ) || realpath( ABSPATH . WPINC . "/pluggable.php" ) !== realpath( ( new ReflectionFunction( "wp_mail" ) )->getFileName() ) ) { WP_CLI::error( "Another plugin replaced wp_mail." ); }'

host=${STAGING_SSH%%/*}
port=22
[[ $host == *:* ]] && { port=${host##*:}; host=${host%:*}; }
remote_dir=/tmp/yaymatik-deploy-$$

# A clean worktree: the build ships only this commit, and it leaves this checkout's dev autoloader alone.
tmp=$(mktemp -d)
trap 'git worktree remove --force "$tmp/build" || true; rm -rf "$tmp"; ssh -p "$port" "$host" rm -rf "$remote_dir" || true' EXIT
git worktree add -q --detach "$tmp/build" HEAD
"$tmp/build/run.sh" release pro --rc
zip=$tmp/build/release/yaymatik-pro.zip

# A repeated rc keeps the same ?ver=, so browsers would keep the old main.js.
rc=$(unzip -p "$zip" yaymatik/yaymatik.php | sed -n "s/.*'YAYMATIK_VERSION', '\([^']*\)'.*/\1/p")
[[ $(installed) != "$rc" ]] || { echo "Refused: staging already has $rc. Make a new commit."; exit 1; }

ssh -p "$port" "$host" mkdir -p "$remote_dir"
scp -q -P "$port" "$zip" "$host:$remote_dir/"
wpr plugin install "$remote_dir/yaymatik-pro.zip" --force --activate

[[ $(installed) == "$rc" ]] || { echo "Install failed: staging reports '$(installed)', expected $rc."; exit 1; }
echo "Deployed $rc ($(git rev-parse --short HEAD)) to $home"
