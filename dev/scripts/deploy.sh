#!/bin/bash
# Build a Pro rc zip of this commit and install it on staging (STAGING_SSH, STAGING_URL in dev/.env.local).
set -eo pipefail
cd "$(dirname "$0")/../.."
[[ -z $(git status --porcelain) ]] || { echo "Refused: uncommitted changes. Commit first."; exit 1; }

. dev/scripts/lib/wp-target.sh --staging
wpr() { "${WP[@]}" "$@"; }
installed() { wpr plugin get yaymatik --field=version 2>/dev/null || true; }

# Active workflows fire on everyone's orders: none of that mail may leave staging.
wpr eval 'if ( false === has_filter( "pre_wp_mail", "yay_mail_log_capture" ) ) { WP_CLI::error( "Mail log mu-plugin is not active." ); }
  if ( has_filter( "woocommerce_mail_callback" ) || realpath( ABSPATH . WPINC . "/pluggable.php" ) !== realpath( ( new ReflectionFunction( "wp_mail" ) )->getFileName() ) ) { WP_CLI::error( "Another plugin replaced wp_mail." ); }'

host=${STAGING_SSH%%/*}
# Only a port named in STAGING_SSH: otherwise ~/.ssh/config decides, as it does for wp --ssh.
ssh_port=()
scp_port=()
[[ $host == *:* ]] && { ssh_port=(-p "${host##*:}"); scp_port=(-P "${host##*:}"); host=${host%:*}; }
remote_dir=/tmp/yaymatik-deploy-$$

# A clean worktree: no ignored local files reach the zip, and this checkout keeps its dev autoloader. admin-shell still floats to its latest release.
tmp=$(mktemp -d)
trap 'git worktree remove --force "$tmp/build" || true; rm -rf "$tmp"; ssh "${ssh_port[@]}" "$host" rm -rf "$remote_dir" || true' EXIT
git worktree add -q --detach "$tmp/build" HEAD
"$tmp/build/run.sh" release pro --rc
zip=$tmp/build/release/yaymatik-pro.zip

# A repeated rc keeps the same ?ver=, so browsers would keep the old main.js.
rc=$(unzip -p "$zip" yaymatik/yaymatik.php | sed -n "s/.*'YAYMATIK_VERSION', '\([^']*\)'.*/\1/p")
[[ $(installed) != "$rc" ]] || { echo "Refused: staging already has $rc. Make a new commit."; exit 1; }

ssh "${ssh_port[@]}" "$host" mkdir -p "$remote_dir"
scp -q "${scp_port[@]}" "$zip" "$host:$remote_dir/"
wpr plugin install "$remote_dir/yaymatik-pro.zip" --force --activate

[[ $(installed) == "$rc" ]] || { echo "Install failed: staging reports '$(installed)', expected $rc."; exit 1; }
echo "Deployed $rc ($(git rev-parse --short HEAD)) to $home"
