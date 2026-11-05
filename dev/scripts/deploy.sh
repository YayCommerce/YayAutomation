#!/bin/bash
# Build a Pro rc zip of this commit and install it on staging (STAGING_SSH, STAGING_URL in dev/.env.local).
set -eo pipefail
cd "$(dirname "$0")/../.."
[[ -n $STAGING_SSH && -n $STAGING_URL ]] || { echo "Set STAGING_SSH (user@host[:port]/wp/path) and STAGING_URL in dev/.env.local"; exit 1; }
[[ -z $(git status --porcelain) ]] || { echo "Refused: uncommitted changes. Commit first."; exit 1; }

wpr() { wp --ssh="$STAGING_SSH" "$@"; }

home=$(wpr option get home)
[[ ${home%/} == "${STAGING_URL%/}" ]] || { echo "Refused: $STAGING_SSH is $home, not $STAGING_URL"; exit 1; }

# Active workflows fire on everyone's orders: none of that mail may leave staging.
wpr eval 'if ( ! has_filter( "pre_wp_mail", "yay_mail_log_capture" ) ) { WP_CLI::error( "Mail log mu-plugin is not active." ); }
  if ( has_filter( "woocommerce_mail_callback" ) || "pluggable.php" !== basename( ( new ReflectionFunction( "wp_mail" ) )->getFileName() ) ) { WP_CLI::error( "Another plugin replaced wp_mail." ); }'

# A repeated rc keeps the same ?ver=, so browsers would keep the old main.js.
rc="$(sed -n "s/.*'YAYMATIK_VERSION', '\([^']*\)'.*/\1/p" yaymatik.php)-rc$(git rev-list --count HEAD)"
[[ $(wpr plugin get yaymatik --field=version 2>/dev/null || true) != "$rc" ]] || { echo "Refused: staging already has $rc. Make a new commit."; exit 1; }

./run.sh release pro --rc

host=${STAGING_SSH%%/*}
port=22
[[ $host == *:* ]] && { port=${host##*:}; host=${host%:*}; }
remote_zip=/tmp/yaymatik-deploy-$$/yaymatik-pro.zip
trap 'ssh -p "$port" "$host" rm -rf "$(dirname "$remote_zip")" || true' EXIT
ssh -p "$port" "$host" mkdir -p "$(dirname "$remote_zip")"
scp -q -P "$port" release/yaymatik-pro.zip "$host:$remote_zip"
wpr plugin install "$remote_zip" --force --activate

echo "Deployed $(wpr plugin get yaymatik --field=version) ($(git rev-parse --short HEAD)) to $home"
