# Brand fonts

`docs/app_spec.md` ("Design Notes > Fonts") calls for two licensed typefaces:

| Face | Used for | Expected file |
|---|---|---|
| **BD Megalona Extra Light** | titles, and any text asking the user to do something | `BDMegalona-ExtraLight.woff2` |
| BD Megalona Extra Light *Italic* | the bold-italic emphasis inside instruction text (`.prompt-em`) | `BDMegalona-ExtraLightItalic.woff2` |
| **BB Noname Pro Regular** | normal body text | `BBNonamePro-Regular.woff2` |

These are **licensed fonts and are deliberately not committed to the
repository.** Drop the `.woff2` files into this directory using exactly the
filenames above and the `@font-face` rules at the top of `www/styles.css`
pick them up — no code change needed.

Until the files are present the fallback stacks render instead:

- display → Didot / Playfair Display / Georgia / serif
- body → system UI sans

so the app looks reasonable everywhere, just not yet on-brand.

If you only have OTF/TTF, convert to woff2 first (much smaller over the
wire) — e.g. with `woff2_compress`, or any web font converter.
