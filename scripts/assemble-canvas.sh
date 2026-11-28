#!/usr/bin/env bash
##
# Post-assemble hook: add Canvas to a Drupal 11 build, for its tests.
#
# vale checks the text in Canvas pages when Canvas is installed. Canvas needs
# Drupal 11.3 or later, so it cannot be a development requirement in
# composer.dev.json: a Drupal 10 build would not resolve. This adds it only to
# a build on Drupal 11.3 or later. Canvas does not declare Drupal 12 yet, so
# composer.dev.json lists it for the lenient plugin. On Drupal 10 the Canvas
# tests skip, and PHPStan leaves out the Canvas code. The current working directory is the project root.

set -eu

version=$(sed -n "s/.*const VERSION = '\([0-9.]*\).*/\1/p" build/web/core/lib/Drupal.php)
[ -n "$version" ] || exit 0

if ! php -r 'exit(version_compare($argv[1], "11.3", ">=") ? 0 : 1);' "$version"; then
  # Without Canvas, PHPStan cannot load the classes that the Canvas code
  # uses. That code runs only with Canvas, so this build leaves it out of
  # the analysis. Builds on Drupal 11 and 12 check it.
  if ! grep -q 'phpstan-canvas.neon' build/phpstan.neon; then
    cat > build/phpstan-canvas.neon <<'NEON'
parameters:
  excludePaths:
    - web/modules/custom/vale/src/Canvas
    - web/modules/custom/vale/tests/src/Kernel/CanvasPageTest.php
NEON
    printf 'includes:\n  - phpstan-canvas.neon\n\n%s\n' "$(cat build/phpstan.neon)" > build/phpstan.neon
  fi
  echo "[canvas] Drupal $version cannot run Canvas, so it is not added."
  exit 0
fi

if [ -d build/web/modules/contrib/canvas ]; then
  exit 0
fi

composer --working-dir=build require --dev --no-interaction --no-progress 'drupal/canvas:^1.13'
echo "[canvas] Added Canvas to the Drupal $version build."
