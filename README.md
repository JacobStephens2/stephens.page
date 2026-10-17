# stephens.page

Source for [stephens.page](https://stephens.page), my professional website:
resume, portfolio, and interactive technical blog, with links to the
[architecture decision collection](https://adr.stephens.page/).

Formerly two repos (`portfolio` and `resume`); both now live here so one
document tree matches the live site.

The site is static-first: most pages are hand-authored HTML, CSS, and JavaScript
served directly by Apache. Individual articles can bring the real tools needed
to prove their argument - including Mermaid, Graphviz, React Flow, XState,
Pyodide, Rust/WebAssembly, native Python and Rust services, and generated
simulation artifacts. The main site stays small; the technical posts are allowed
to be executable.

## What lives here

- **Professional profile** - background, experience, technical focus, and ways
  to connect.
- **Resume** - print-oriented HTML resume, Markdown source, PDF download, and
  job-specific variants under `/resume/`.
- **Portfolio and applications** - selected software, experiments, screenshots,
  and project writeups.
- **Technical blog** - teaching-first engineering posts with live figures,
  runnable models, visualizations, sources, and quizzes.
- **Decision records** - published at [adr.stephens.page](https://adr.stephens.page/)
  from the [infrastructure-patterns](https://github.com/JacobStephens2/infrastructure-patterns)
  repository, with search and topic filters.
- **Agent-oriented artifacts** - canonical blog posts include an `agents.md`
  companion with operational rules, schemas, verified numbers, checklists, and
  self-tests that do not require a browser.

## Selected interactive posts

### [The Diagram Is Not the Model](https://stephens.page/blog/the-diagram-is-not-the-model/)

One canonical process model projected through real Mermaid, D2, Graphviz,
React Flow, and XState renderers, followed by a discrete-event simulation and a
quiz.

### [The Animation Is a Replay](https://stephens.page/blog/the-animation-is-a-replay/)

One software-factory model executed through TypeScript, Rust in WebAssembly and
native x86-64, SimScript, and SimPy on native CPython and Pyodide. Every engine
emits one event-log schema; one renderer replays them all. The post also runs
salabim and Pillow in the browser to generate a genuine animation.

## Repository map

| Path | Purpose |
|---|---|
| [`index.html`](https://stephens.page/) | Main professional landing page |
| [`about.html`](https://stephens.page/about) | Background, experience, and technical profile |
| [`portfolio.html`](https://stephens.page/portfolio) | Selected projects and case studies |
| [`resume/`](https://stephens.page/resume/) | Resume HTML, Markdown, PDF, and tailored variants |
| [`apps.html`](https://stephens.page/apps) | Software and application index |
| [`blog/`](https://stephens.page/blog/) | Canonical human posts and their agent-oriented companions |
| [Architecture decisions](https://adr.stephens.page/) | Current collection, maintained in infrastructure-patterns |
| `screenshots/` | Portfolio and project imagery |
| [`theme.css`](https://stephens.page/theme.css), [`theme.js`](https://stephens.page/theme.js) | Shared light/dark theme and persistent theme control |
| [`contact.html`](https://stephens.page/contact), `contact-submit.php` | Contact page and server-side form handler |
| `private/render_resume_pdf.sh` | Headless Chrome PDF render used by `resume/download-pdf.php` |
| `private/check_theme_toggle.js` | Headless Playwright check of theme toggle placement, the theme cycle, and resume PDF download requests |
| `.htaccess` | Apache routing, redirects, and content-type configuration |

`resume.stephens.page` should redirect to `https://stephens.page/resume/` so old
links keep working after the resume was folded into this tree.

The old `decisions/` HTML mirror has been removed. Permanent redirects on the
server preserve `/decisions/` and individual record URLs; their configuration
lives in [infrastructure-patterns/ops/apache/legacy-decisions.conf](https://github.com/JacobStephens2/infrastructure-patterns/blob/main/ops/apache/legacy-decisions.conf).

## Architecture

- Apache serves the static site and extensionless routes.
- The shared theme follows the system preference until a visitor explicitly
  chooses light or dark; the choice is stored under `localStorage.theme`.
- Heavy browser runtimes are loaded only after a reader opts in.
- Interactive figures keep a useful fallback when a CDN or rendering service is
  unavailable.
- A small number of post-specific APIs run behind Apache reverse proxies. Their
  source and deployment files live beside the post they support.
- The deployed document tree is intentionally close to the repository tree:
  inspect the page source and you are looking at the implementation.

## Local preview

For the static pages:

```bash
python3 -m http.server 8000
```

Then open [http://localhost:8000](http://localhost:8000).

This preview does not reproduce Apache rewrites, PHP form handling, or
post-specific native API services. Service setup instructions live with the
relevant post, for example
[`blog/the-animation-is-a-replay/server/`](blog/the-animation-is-a-replay/server/).

## Content conventions

- A human blog post lives at `blog/<slug>/index.html`.
- Its required agent-oriented version lives at `blog/<slug>/agents.md` and is
  served as `text/markdown`.
- Figures run the genuine renderer or library whenever practical and state
  their execution path in the caption.
- Published posts include sources, provenance, graceful fallbacks, and
  headless-browser verification.
- Shared behavior belongs in the root theme files; article-specific behavior
  stays with the article.

## License

[MIT](LICENSE)
