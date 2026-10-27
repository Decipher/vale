#!/usr/bin/env bash
##
# Post-provision hook: give the development site content to lint.
#
# Applies recipes/dev_content, which creates a Page content type whose body
# is checked with Vale. The standard profile no longer creates content types
# (they arrive as recipes), so without this a fresh `make provision` has no
# opted-in field to test against. No-ops where this cannot apply: no core
# Recipe API, a standard profile that still ships the Page type itself
# (where the recipe's non-strict field import would not land), a Drush
# without recipe:apply, or DEV_CONTENT set to 0/false/no/off. The current
# working directory is the project root.

set -eu

case "${DEV_CONTENT:-1}" in
  0 | false | no | off) exit 0 ;;
esac

recipe="$PWD/recipes/dev_content"
[ -d "$recipe" ] || exit 0

if [ ! -f build/web/core/lib/Drupal/Core/Recipe/Recipe.php ]; then
  echo "[dev-content] Core recipes are not available; skipping."
  exit 0
fi

if [ -f build/web/core/profiles/standard/config/install/node.type.page.yml ]; then
  echo "[dev-content] The standard profile already ships a Page type; skipping."
  exit 0
fi

if ! build/vendor/bin/drush -r build/web help recipe:apply >/dev/null 2>&1; then
  echo "[dev-content] Drush does not provide recipe:apply; skipping."
  exit 0
fi

echo "[dev-content] Applying $recipe"
build/vendor/bin/drush -r build/web recipe:apply "$recipe"
