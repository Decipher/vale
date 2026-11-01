#!/usr/bin/env bash
##
# Post-provision hook: give the development site a field to lint.
#
# Applies recipes/dev_content, which creates a Page content type whose body
# is checked with Vale. The standard profile no longer creates content types
# (they arrive as recipes), so without this a fresh `make provision` has no
# opted-in field to test against. No-ops where this cannot apply: no core
# Recipe API, a standard profile that still ships the Page type itself
# (where the recipe would not add its field), no text_with_summary field type
# in core (Drupal 12), a Drush without recipe:apply, or DEV_CONTENT set to
# 0/false/no/off. The current working directory is the
# project root.

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

# Drupal 12 removes the text_with_summary field type from core, and the
# recipe's body field uses it.
if [ ! -d build/web/core/modules/text_with_summary ] && [ ! -f build/web/core/modules/text/src/Plugin/Field/FieldType/TextWithSummaryItem.php ]; then
  echo "[dev-content] Core has no text_with_summary field type; skipping."
  exit 0
fi

if ! make drush CMD='help recipe:apply' >/dev/null 2>&1; then
  echo "[dev-content] Drush does not provide recipe:apply; skipping."
  exit 0
fi

# Drupal 11.5 splits the text_with_summary field type out of text, and the
# recipe's body field needs that module's config schema to validate.
# Earlier cores provide the field type in text itself. Install it only once
# the recipe will be applied, so a skipped recipe leaves the site unchanged.
if [ -d build/web/core/modules/text_with_summary ]; then
  make drush CMD='pm:install text_with_summary' >/dev/null
fi

echo "[dev-content] Applying $recipe"
make drush CMD="recipe:apply '$recipe'"

# The recipe creates the field only when it is missing, so an existing body
# field carries no Vale settings. That is worth saying, not failing on.
if ! make drush CMD='config:get field.field.node.page.body third_party_settings.vale.enabled' 2>/dev/null | grep -q ': true$'; then
  echo "[dev-content] node.page.body is not enabled for Vale; an existing field was left alone."
fi
