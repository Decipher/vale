#!/usr/bin/env bash
##
# Post-provision hook: install Vale on the development site.
#
# Runs the README's install steps: downloads the Vale binary with
# drush vale:install, writes the .vale.ini beside the web root, syncs the
# styles it asks for, and points settings.php at both, so the stack lints
# as soon as it is up. No-ops when DEV_VALE is 0/false/no/off, and degrades
# to a notice when the binary or the styles cannot be downloaded, such as
# in an offline build, so provisioning never fails on the network. The
# current working directory is the project root.

set -eu

case "${DEV_VALE:-1}" in
  0 | false | no | off) exit 0 ;;
esac

web="$PWD/build/web"
settings="$web/sites/default/settings.php"
[ -f "$settings" ] || exit 0
ini="$PWD/build/.vale.ini"
binary="$PWD/build/bin/vale"
drush="$PWD/build/vendor/bin/drush"

# The binary is downloaded when it is not there yet.
if [ ! -x "$binary" ]; then
  echo "[dev-vale] Installing the Vale binary"
  if ! "$drush" -r "$web" vale:install; then
    echo "[dev-vale] Vale could not be downloaded; skipping."
    exit 0
  fi
fi

# The README's starting configuration.
if [ ! -f "$ini" ]; then
  echo "[dev-vale] Writing $ini"
  cat > "$ini" <<'EOF'
StylesPath = styles
Packages = Microsoft

[*]
BasedOnStyles = Vale, Microsoft
EOF
fi

# The styles the configuration asks for.
if [ ! -d "$PWD/build/styles" ]; then
  echo "[dev-vale] Syncing the styles"
  if ! (cd "$PWD/build" && ./bin/vale sync); then
    echo "[dev-vale] The styles could not be synced; skipping."
    exit 0
  fi
fi

# Point the site at both. settings.php is read-only on a provisioned site,
# so it is made writable, and read-only again after.
if ! grep -q "vale_binary" "$settings"; then
  echo "[dev-vale] Wiring \$settings in settings.php"
  readonly=0
  [ -w "$settings" ] || { readonly=1; chmod u+w "$settings"; }
  cat >> "$settings" <<'EOF'

$settings['vale_binary'] = $app_root . '/../bin/vale';
$settings['vale_config'] = $app_root . '/../.vale.ini';
EOF
  [ "$readonly" = 0 ] || chmod a-w "$settings"
fi

"$drush" -r "$web" cr >/dev/null
echo "[dev-vale] Vale ready. Run 'drush vale:status' to see it."
