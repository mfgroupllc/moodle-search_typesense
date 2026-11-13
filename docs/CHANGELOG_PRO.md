# Release Notes — Typesense Pro (`local_typesensepro`)

All notable updates to **Typesense Pro (`local_typesensepro`)** for Moodle are documented below.

---

## [1.1.10] - 2026-11-13

### Changed & Fixed (Moodle Marketplace Review Follow-Up)
- **AI Usage Policy Prompt (`\local_typesensepro\rag_summariser`, `templates/ai_policy.mustache`, `amd/src/policy.js`):**
  - When a user who has not accepted the site AI usage policy asks for an AI Overview, the policy is now displayed with **Accept and continue** and **Decline** buttons. Acceptance is recorded by Moodle's own `core_ai` policy service only when the user presses accept, and nothing is sent to the AI provider before then.
  - AI Overview requests now carry the user's session key, and the AI Overview button is hidden for guests and when no Moodle AI provider is available.
- **Generic Guided Discovery Examples (`\local_typesensepro\message_classifier`, `lang/en/local_typesensepro.php`):**
  - Example chips and queries are now generic for any kind of site. The Video Time chip is only shown when Video Time search is available, and the question chip only when AI Overviews are enabled.
- **Localized Category Rationales and Labels (`\local_typesensepro\ai_classifier`, `analytics.php`, `amd/src/instant.js`):**
  - Category classification rationales, unit labels, and the InstantSearch accessible name now come from language strings.
- **InstantSearch Template (`templates/instant_popover.mustache`, `amd/src/instant.js`, `styles.css`):**
  - The dropdown is rendered from a Mustache template with strings loaded through `core/str`, and the remaining inline styles moved to `styles.css`.
- **Cross-Database SQL (`\local_typesensepro\message_classifier`):**
  - Replaced a raw `LIMIT` clause with Moodle's row-limit arguments.
- **Privacy API (`\local_typesensepro\privacy\provider`):**
  - Declared the link to Moodle's `core_ai` subsystem.
- **Language Strings (`lang/en/local_typesensepro.php`):**
  - Switched titles and labels to sentence case and removed two unused strings.
- **Dependency (`version.php`):**
  - Now requires `search_typesense` 1.1.13 (`2026111300`) or later.

### Fixed
- **Embedding Updates with Self-Signed Typesense Certificates (`\local_typesensepro\task\generate_embeddings_adhoc_task`):**
  - The task now honors the **Verify TLS certificate** setting when writing vectors back to Typesense.

### Removed
- **Legacy Footer Callback (`lib.php`):**
  - Removed the unused `before_footer` callback; the widget is loaded through the `before_footer_html_generation` hook.

---

## [1.1.9] - 2026-11-12

### Changed & Fixed (Moodle Marketplace Review Follow-Up)
- **Strict User AI Policy Enforcement (`\local_typesensepro\embedding_client::can_user_use_core_ai`, `\local_typesensepro\rag_summariser`):**
  - Removed automatic calls to `$manager->user_policy_accepted()` so AI policy consent is never recorded on behalf of the user, and updated `can_user_use_core_ai()` to fail closed (`return false`) when the policy has not been accepted, when the policy API is unavailable, or when an exception occurs.
- **External Service & AMD Module for InstantSearch (`\local_typesensepro\external\instant_search`, `db/services.php`, `amd/src/instant.js`):**
  - Replaced `ajax_instant.php` and inline `<script>` injection in `instant_widget::render()` with the `local_typesensepro_instant_search` External Service (`core/ajax`) and the `local_typesensepro/instant` AMD module loaded via `$PAGE->requires->js_call_amd()`.
- **External Stylesheet (`styles.css`):**
  - Moved all InstantSearch popover CSS rules out of `instant_widget::render()` into `styles.css`.
- **Localized Discovery Guide Example Queries (`\local_typesensepro\message_classifier`, `lang/en/local_typesensepro.php`):**
  - Replaced hard-coded example search strings in `render_discovery_guide()` with generic, translatable language strings (`guide_query_files`, `guide_query_videos`, `guide_query_assignments`, `guide_query_ai`).
- **Included `docs/` in Release Archive (`.gitattributes`):**
  - Included the `docs/` directory in plugin release `.zip` packages so all documentation links in `README.md` resolve inside the downloaded archive.

---

## [1.1.8] - 2026-11-11

### Changed
- **Moodle Plugin CI (`moodle-plugin-ci`) Compliance & Workflow:**
  - Resolved all `moodle-plugin-ci codechecker` (`phpcs --standard=moodle`) and `moodle-plugin-ci phpdoc` (`local_moodlecheck`) checks with 0 errors and 0 warnings, and added `.github/workflows/moodle-ci.yml`.

---

## [1.1.7] - 2026-11-10

### Added & Improved
- **Priority Student ID (`idnumber`) & User Profile Lookup in InstantSearch:**
  - Typing a numeric student ID prefix (such as `9999` for `9999999998`) or a student/staff name in the live navbar InstantSearch dropdown now immediately surfaces the matching user profile at the top of the dropdown.
- **Standardized American (US) English:**
  - Updated all user-facing language strings, AI category rationales, and documentation to American (US) English.

---

## [1.1.6] - 2026-11-10

### Added & Improved
- **InstantSearch Defaults to `"Search everything"` with Automatic 0-Hit Fallback:**
  - Placed **Search everything** as the first and default scope pill in the live InstantSearch dropdown so users typing a student ID number, user name, or course code get results immediately.
  - If a user switches to a restricted scope pill (**Current courses**, **Historical / other courses**, or **Video Time**) and a search returns 0 matches in that scope, InstantSearch automatically falls back to **Search everything** and highlights the pill.
- **Date- & Cohort-Aware AI Category Rationales:**
  - Enhanced AI category classifications on the **Active Course Categories** page to include active-dated course counts, cohort term codes, and notes when expired courses inside a shared folder are automatically excluded by start/end date.

---

## [1.1.5] - 2026-11-10

### Fixed & Improved
- **Seamless AI Overview Generation on First Click:**
  - Clicking **"Generate AI Overview from these results"** now automatically registers the user's explicit AI policy acceptance in Moodle so the overview generates immediately without requiring the user to open a separate text editor modal first.
- **Unified Admin Settings Navigation:**
  - Integrated **Typesense Pro (AI & InstantSearch)** into the main **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense** category.

---

## [1.1.4] - 2026-11-10

### Added & Improved
- **Smart Message Noise & Broadcast Filtering:**
  - Automatically keeps message search clean by filtering out old archived messages, trivial 1–2 word acknowledgments, automated system notifications, and duplicate copies of mass class announcements.
- **Guided Discovery Example Cards:**
  - Displays clickable example search chips when opening Global Search before entering a query.
- **Strict Multi-Term Narrowing in InstantSearch:**
  - Entering multiple words in the live navbar dropdown narrows results to items containing all entered terms.

---

## [1.1.3] - 2026-11-10

### Changed
- **Dedicated Typesense Pro Administration Tab:**
  - Moved all Typesense Pro settings onto a dedicated **Typesense Pro (AI & InstantSearch)** tab alongside the core Typesense administration pages and **Search Analytics** dashboard.

---

## [1.1.2] - 2026-11-09

### Added & Hardened
- **Dedicated Hybrid Embedding Consent & Credentials:**
  - Added explicit administrator consent and dedicated API endpoint/key settings for optional Hybrid Semantic Search, while routing AI Overviews and AI Category Classification through Moodle's native `core_ai` subsystem.
- **Prompt Injection Hardening & CSV Export Protection:**
  - Added XML source isolation for AI Overviews and spreadsheet formula sanitization for CSV analytics exports.

---

## [1.1.1] - 2026-11-09

### Added
- **Priority Course & Template Surfacing in InstantSearch:**
  - Surfaces up to 3 matching courses and course templates at the top of the live InstantSearch dropdown and enforces student user-search restrictions.

---

## [1.1.0] - 2026-11-09

### Added
- **Live Type-As-You-Go Navbar InstantSearch:**
  - Added a live, permission-safe typeahead dropdown to Moodle's top navbar search bar and Global Search input with interactive scope pills, highlighted snippets, keyboard navigation, and a 1-click **✨ AI Overview** jump button.

---

## [1.0.0] - 2026-11-08

### Added
- **Initial Release:**
  - Permission-safe **AI Overviews** with clickable `[1] [2]` source citations, **Scope-Aware Hybrid Semantic Search**, **AI Category Classification & Term Auto-Rollover**, **Historical Cohort Spillover Banner**, and the **Search Analytics & Zero-Result Insights Dashboard**.
