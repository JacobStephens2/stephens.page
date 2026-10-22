# stephens.page

Glossary for the stephens.page site source.

## Terms

**Theme controller** - the one site-wide module (`theme.js`) that owns the light/dark/system preference, its storage under `localStorage.theme`, the `theme-color` meta, and the theme toggle. Every page that offers a theme toggle loads it; no page carries its own copy.

**Theme toggle slot** - an element a page marks with `data-theme-toggle` to say "put the theme toggle here". When a page declares slots, the theme controller places exactly one toggle in each slot and nowhere else; pages without slots fall back to the theme controller's layout heuristics.

**Resume source** - the hand-edited resume: `resume/index.html` and its stylesheet `resume/files/styles.css`, which holds every print rule. The only place the resume's content or print layout changes.

**Resume projection** - a form of the resume computed from the resume source on request, never edited by hand or committed: the Markdown at `/resume/jacob-stephens-resume.md` and the PDF at `/resume/Jacob-Stephens-Resume.pdf`.

**Page budget** - the most pages the PDF projection may take: two. The resume check fails a resume source whose PDF exceeds it.

**Live try** - one Inkvoke image generation requested from the public form.

**Live-try admission** - the decision to record a live try, five an hour per connection and three a day per email, recorded only after the caller proves the try, under the hit-file lock. The contact form is not a live try and does not use this module.
