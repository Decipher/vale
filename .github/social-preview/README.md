# Social preview

`social-preview.png` is the image GitHub shows when someone shares a link to
the repository. `index.html` is its source, drawn on a 1280x640 canvas.

## Brand

The card follows the Vale brand page at <https://vale.sh/brand>, and copies
the layout of the vale.sh social card (`static/brand/vale-social.png`, built
by `script/brand/main.py` in the vale-cli/vale.sh repository).

- The logo is `static/brand/vale-logo-dark.svg` from
  <https://github.com/vale-cli/vale.sh/tree/svelte/static/brand>, inlined as
  drawn. The repository has no licence file. The brand page lets anyone use
  the mark and wordmark to refer to the project, as long as the files are used
  as drawn and nothing claims to be Vale or endorsed by it.
- The colours are the brand tokens: Graphite `#0E100C` for the background,
  Grass light `#90D454` for "for Drupal", and the muted `#AFB6A5` from
  `script/brand/main.py` for the smaller lines.
- The text is Inter, the face vale.sh uses for everything but the wordmark.
  It loads from Google Fonts (<https://fonts.google.com/specimen/Inter>) under
  the SIL Open Font License. The address uses the platform monospace face, as
  the brand page asks for anything that reads as code.

## Regenerate

Render the page with a headless browser from this folder. The browser needs
network access to load Inter.

```bash
chromium --headless --no-sandbox --hide-scrollbars \
  --force-device-scale-factor=1 --window-size=1280,640 \
  --virtual-time-budget=5000 \
  --screenshot=social-preview.png "file://$PWD/index.html"
```

Keep the image under 1 MB. GitHub has no API for the social preview, so upload
it by hand in the repository's Settings > General > Social preview.
