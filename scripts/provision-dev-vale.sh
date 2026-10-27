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

settings="$PWD/build/web/sites/default/settings.php"
[ -f "$settings" ] || exit 0
ini="$PWD/build/.vale.ini"
binary="$PWD/build/bin/vale"

# The binary is downloaded when it is not there yet.
if [ ! -x "$binary" ]; then
  echo "[dev-vale] Installing the Vale binary"
  if ! make drush CMD='vale:install'; then
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

# The styles the configuration asks for; the Microsoft package is the sign
# the sync completed rather than left a partial directory.
if [ ! -d "$PWD/build/styles/Microsoft" ]; then
  echo "[dev-vale] Syncing the styles"
  if ! (cd "$PWD/build" && ./bin/vale sync); then
    echo "[dev-vale] The styles could not be synced; skipping."
    exit 0
  fi
fi

# Point the site at both. settings.php is read-only on a provisioned site,
# so it is made writable, and read-only again after. Either setting may
# already be there; each is only appended when missing.
if ! grep -q "vale_binary" "$settings" || ! grep -q "vale_config" "$settings"; then
  echo "[dev-vale] Wiring \$settings in settings.php"
  readonly=0
  [ -w "$settings" ] || { readonly=1; chmod u+w "$settings"; }
  if ! grep -q "vale_binary" "$settings"; then
    cat >> "$settings" <<'EOF'

$settings['vale_binary'] = $app_root . '/../bin/vale';
EOF
  fi
  if ! grep -q "vale_config" "$settings"; then
    cat >> "$settings" <<'EOF'
$settings['vale_config'] = $app_root . '/../.vale.ini';
EOF
  fi
  [ "$readonly" = 0 ] || chmod a-w "$settings"
fi

make drush CMD='cr' >/dev/null
echo "[dev-vale] Vale ready. Run 'drush vale:status' to see it."
