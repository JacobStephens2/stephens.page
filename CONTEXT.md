# stephens.page

Glossary for the stephens.page site source.

## Terms

**Theme controller** - the one site-wide module (`theme.js`) that owns the light/dark/system preference, its storage under `localStorage.theme`, the `theme-color` meta, and the theme toggle. Every page that offers a theme toggle loads it; no page carries its own copy.

**Theme toggle slot** - an element a page marks with `data-theme-toggle` to say "put the theme toggle here". When a page declares slots, the theme controller places exactly one toggle in each slot and nowhere else; pages without slots fall back to the theme controller's layout heuristics.
