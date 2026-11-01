#!/usr/bin/env bash
##
# Post-assemble hook: let the test-only modules install on a newer core.
#
# The lenient Composer plugin installs the packages that composer.dev.json
# lists under extra.drupal-lenient, even when they do not declare the core
# that the build uses. Drupal still refuses to enable a module whose
# core_version_requirement leaves that core out. This adds the build's core
# major to the requirement of each of those modules, as Drupal.org CI does for
# its next major jobs. It changes nothing when the module already allows that
# major. The current working directory is the project root.

set -eu

major=$(sed -n "s/.*const VERSION = '\([0-9]*\)\..*/\1/p" build/web/core/lib/Drupal.php)
[ -n "$major" ] || exit 0

packages=$(php -r '$json = json_decode(file_get_contents("composer.dev.json"), TRUE); echo implode(" ", $json["extra"]["drupal-lenient"]["allowed-list"] ?? []);')

for package in $packages; do
  directory="build/web/modules/contrib/${package#drupal/}"
  [ -d "$directory" ] || continue
  find "$directory" -name '*.info.yml' -print0 | while IFS= read -r -d '' info; do
    if grep -q '^core_version_requirement:' "$info" && ! grep -q "^core_version_requirement:.*\^$major" "$info"; then
      # Keep any quotes around the value.
      sed -i -E "s/^core_version_requirement: *(['\"]?)(.*[^'\"])(['\"]?) *$/core_version_requirement: \1\2 || ^$major\3/" "$info"
      echo "[lenient-core] Allowed Drupal $major in $info"
    fi
  done
done
