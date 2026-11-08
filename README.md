# search_typesense — Typesense global search engine for Moodle

A Moodle **global search** engine plugin that indexes your site into
[Typesense](https://typesense.org) and serves fast, typo-tolerant,
accent-insensitive search.

- **Open source:** GPL v3 (free core).
- **Lightweight:** Typesense is a single binary — no JVM, low RAM.
- **Multilingual:** accent/diacritic-insensitive search (e.g. `matematica` → `matemática`).
- **Freemium ready:** a separate commercial **Pro** extension adds semantic/AI search
  (see "Pro / roadmap" below).

> This is the free core (`search_typesense`). The Pro tier (`search_typesense_pro`)
> is a separate, commercially-licensed plugin.

## Requirements

- Moodle 4.1 or later.
- A reachable Typesense server (self-hosted or Typesense Cloud).

## Installation

1. Drop this folder into `search/engine/typesense/` of your Moodle install
   (so the path is `admin/tool/...` no — it is `search/engine/typesense/`).
2. Visit **Site administration → Notifications** to complete installation.
3. Go to **Site administration → Plugins → Search → Manage global search**:
   - Set **Search engine** to **Typesense**.
   - Configure host, port, protocol and API key under
     **Site administration → Plugins → Search → Typesense**.
4. Run the global search indexing (Scheduled task
   `Search: Index all` or the "Index" button).

## Settings

| Setting | Meaning |
|---|---|
| Host | Typesense hostname/IP |
| Port | Typesense port (default `8108`) |
| Protocol | `http` or `https` |
| API key | Admin or search-only key with write access for indexing |
| Collection | Typesense collection name (default `moodle`) |
| Text locale | Locale for text fields, e.g. `es`, `fr`, `de`. Controls accent handling & typo tolerance. Leave empty for Typesense defaults. |

### Accent handling

Set the **Text locale** to your primary language to enable locale-aware
diacritic folding. With a locale set, an exact-accent match (e.g. searching
`matemática`) is ranked slightly above the accent-insensitive match, while
accent-insensitive recall (`matematica` → `matemática`) still works.

## Local development

You need a Typesense server reachable from Moodle. Run it however you like
(binary, your own host, etc.) and point the plugin settings at its host/port.
For quick local testing, the official Typesense binary is a single executable:

```bash
./typesense-server --data-dir=/tmp/ts-data --api-key=localdevkey --enable-cors
```

Then configure the plugin for `localhost:8108` (http) with API key `localdevkey`.

## Architecture

The plugin is a standard Moodle `search` engine (`\search_typesense\engine`
extends `\core_search\engine`). Documents are mapped to a single Typesense
collection; queries use `filter_by` for `areaid`, `contextid` (the user's
accessible contexts), `courseid` and date range, with `_text_match` relevance
and `modified` recency as the sort.

A thin HTTP client (`classes/client.php`) talks to the Typesense REST API
using Moodle's built-in `\curl` — **no composer or vendored libraries required**,
which keeps the plugin cleanly GPL.

## Pro / roadmap

The planned commercial extension (`search_typesense_pro`, Moodle 4.5+) layers
AI features on top through Moodle's **AI subsystem**:

- Hybrid semantic search (embeddings generated via the site's configured AI provider).
- AI result summaries, "ask about results" (RAG), and query rewriting.

It is distributed separately and does not alter the GPL core.

## License

GNU GPL v3 or later. See `LICENSE`.

## Optional search page and current-course scopes

The **Search matching courses first** setting routes browser global searches to
this plugin's search page on Moodle 4.5. The page queries Typesense in batches,
then uses Moodle's current course and activity permissions for candidate courses.
This avoids expanding activity visibility for every course a manager can access.
It does not replace mobile or web-service search. The setting is off by default.

Administrators can select **Current course categories** manually or use the
**Detect / Re-check active categories** button in plugin settings. On initial
install or upgrade, if no categories are selected yet, the plugin infers active
categories automatically using upcoming or recently modified course shells
(including zero-enrolment courses under development), active course start/end
dates, recent student enrolments, and recent course access, while excluding
archive and test-only folders. Selected categories and their active descendants
define the current scope. The search page then defaults to current courses and
offers links to historical/other courses and everything. With no categories
selected, it defaults to everything. Classification uses live Moodle category
membership, so moving a course does not require reindexing.

“Historical / other” means outside the selected active trees; it does not imply
a course is closed, and neither scope grants access beyond Moodle's permissions.

Results and counts contain only verified matches. The page requests more
candidates when access checks reject a match. Very broad searches have a
processing limit and display a message when additional matches may remain.
Disabling the setting restores the standard Moodle browser search path.

Before enabling, validate manager/student access, hidden activities, separate
groups, explicit course/context filters, current/historical scope, and paging.
The `tests/` directory contains Moodle PHPUnit tests; run them in an isolated
Moodle test installation, never against a live Moodle database.
