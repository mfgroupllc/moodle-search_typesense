# Changelog — `local_typesensepro`

All notable changes to **Typesense Pro (`local_typesensepro`)** for Moodle are documented in this file.

---

## [1.1.7] - 2026-11-10 (`2026111004`)

### Added & Fixed
- **Priority User Profile & Student ID (`idnumber`) Lookup in InstantSearch (`\local_typesensepro\instant_engine::search_instant`):**
  - Integrated `prepend_priority_users()` into `instant_engine::search_instant()` and raised default candidate prefix expansion (`max_candidates => 200`).
  - Typing a numeric student ID prefix (such as `9999` for `idnumber = 9999999998`) or a student/staff name (`"Sam Hutchens"` $\rightarrow$ `"Sam Hutchins"`) in the live navbar InstantSearch dropdown immediately surfaces the matching user profile at the top of results.
- **Standardized on American (US) English (`lang/en/local_typesensepro.php`, `classes/*`, `README.md`, `CHANGELOG.md`):**
  - Updated all user-facing language strings, AI rationale strings, comments, docblocks, and documentation from UK/AU spellings (`authorised`, `analyses`, `analyser`, `enrolment`, `normalised`, `Synthesised`, `Synchronise`, `Customise`, `Serialised`, `acknowledgements`) to American (US) English (`authorized`, `analyzes`, `analyzer`, `enrollment`, `normalized`, `Synthesized`, `Synchronize`, `Customize`, `Serialized`, `acknowledgments`) and updated `instant_noresults` to `"No matching results found in this scope."`.

---

## [1.1.6] - 2026-11-10 (`2026111003`)

### Added & Improved
- **InstantSearch Defaults to `"Search everything"` with Automatic 0-Hit Scope Fallback (`\local_typesensepro\instant_widget`, `ajax_instant.php`):**
  - Placed **Search everything** (`all`) as the first and default scope pill in the live InstantSearch dropdown so users typing a student number, user name, or historical course code immediately get results without having to switch scopes first.
  - Added automatic 0-hit fallback in `ajax_instant.php`: if a user selects a restricted scope (`Current courses`, `Historical / other courses`, or `Video Time`) and their query returns 0 hits in that scope, InstantSearch automatically retries against `Search everything` and updates the active scope pill in the UI.
  - Added `\core\session\manager::write_close()` in `ajax_instant.php` so live keystroke requests never contend on the user's PHP session lock.
- **Date- & Cohort-Aware AI Category Classifier (`\local_typesensepro\ai_classifier`):**
  - Updated `ai_classifier::classify_categories()` and `classify_category_heuristic()` to pass active-dated course counts (`activedatedcourses`), expired course counts (`expiredcourses`), and cohort term codes into both the heuristic scorer and the `core_ai` prompt, noting when expired courses inside a shared folder are automatically excluded by start/end date.

---

## [1.1.5] - 2026-11-10 (`2026111002`)

### Fixed & Improved
- **Automatic `core_ai` Policy Registration on Explicit User Trigger (`\local_typesensepro\embedding_client::can_user_use_core_ai`):**
  - Fixed an issue where clicking **"Generate AI Overview from these results"** returned empty for users who had not previously opened a TinyMCE AI modal to accept Moodle's AI policy; clicking the explicit AI Overview trigger now automatically records `user_policy_accepted()` in `{ai_policy_register}` so `aiprovider_gemini` / `core_ai` executes immediately.
- **Nested Admin Settings Category Integration (`settings.php`):**
  - Attached the **Typesense Pro (AI & InstantSearch)** settings page to `search_typesense_category` under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense**.

---

## [1.1.4] - 2026-11-10 (`2026111001`)

### Added & Refined
- **Smart Message Noise & Broadcast Inference Engine (`\local_typesensepro\message_classifier`):**
  - Automatically infers meaningful instructor-student messages while filtering out archived historical terms (`messageretentiondays`, default 180 days using $O(\log N)$ binary PK lookup), trivial 1–2 word acknowledgments (`messageminchars`, default 20 chars), automated Moodle system notifications, and redundant copies of mass class broadcasts (`messagemaxfanout`, default 25 copies per sender).
- **Refined AI Overview Button & Source Attribution (`lang/en/local_typesensepro.php`, `\local_typesensepro\rag_summariser`):**
  - Simplified the on-demand trigger button label to **`"Generate AI Overview from these results"`** and updated the AI Overview card footer to explain that the overview is synthesized from the top most relevant authorized results on the page.
- **Guided Discovery Example Chips (`\local_typesensepro\message_classifier::render_discovery_guide`):**
  - Displays clickable example search chips (*Inside Uploaded PDFs & Recipes*, *Inside VideoTime Lectures*, *Inside Rubrics & Syllabi*, and *Ask AI Overview*) when users open Global Search (`enablediscoveryguide`).
- **Strict `AND` Multi-Term Narrowing in InstantSearch (`\local_typesensepro\instant_engine`):**
  - Enabled `drop_tokens_threshold => 0` and `prioritize_token_position => 'true'` in live typeahead queries.

---

## [1.1.3] - 2026-11-10 (`2026111000`)

### Changed
- **Dedicated Typesense Pro Settings Sub-Page (`settings.php`, `\local_typesensepro\plugin::populate_pro_settings_page`):**
  - Moved Typesense Pro settings onto their own dedicated administration sub-page (`/admin/settings.php?section=local_typesensepro_settings`) under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense Pro (AI & InstantSearch)**, integrated with `\search_typesense\admin_nav` tabs across all Typesense administration pages and `/local/typesensepro/analytics.php`.

---

## [1.1.2] - 2026-11-09 (`2026110902`)

### Added
- **Dedicated Embedding Consent & API Settings (`\local_typesensepro\plugin::extend_settings`):**
  - Registered `local_typesensepro/embeddingconsent` (`admin_setting_configcheckbox`), `local_typesensepro/embeddingapikey` (`admin_setting_configpasswordunmask`), and `local_typesensepro/embeddingendpoint` (`admin_setting_configtext` with `PARAM_URL`).

### Changed & Fixed (Moodle Marketplace Review `MMRT-273`)
- **Language Strings (`lang/en/local_typesensepro.php`):** Added required `cachedef_rag_summaries`, `cachedef_query_embeddings`, and `cachedef_historical_intent` strings for `db/caches.php`, plus localized CSV export headers (`analytics_csv_col_*`).
- **Strict `core_ai` Enforcement & Dedicated Embedding Credentials (`\local_typesensepro\embedding_client`, `\local_typesensepro\rag_summariser`, `\local_typesensepro\ai_classifier`):**
  - Removed `embedding_client::chat_completion()` and all cross-plugin credential lookups (`aiprovider_openai`, `aiprovider_azureai`, `aiprovider_gemini`).
  - AI Overviews and AI Category Classification now execute exclusively through Moodle's `core_ai` subsystem (`\core_ai\aiactions\generate_text`), enforcing per-context action checks and user AI policy acceptance (`get_user_policy_status()`).
  - Vector embeddings (`embed_texts()`, `embed_query()`) require `enablehybridsearch`, explicit admin consent (`embeddingconsent`), and `local_typesensepro`'s own dedicated `embeddingapikey`.
- **Prompt Injection Hardening (`\local_typesensepro\rag_summariser::generate_summary`):**
  - Wrapped the user query in `<user_query>` and each source excerpt in `<untrusted_source index="...">` blocks with explicit system instructions to treat them strictly as untrusted quotations.
- **Privacy API Completeness (`\local_typesensepro\privacy\provider`):**
  - Added `queryhash`, `limited`, and `historicalhits` to `provider::get_metadata()` and `provider::export_user_data()`.
  - Updated `privacy:metadata:ai_provider` and `README.md` to explicitly disclose background document embedding payloads (`title`, `description1`, and first `2,000` characters of `content` within the configured `embeddingscope`).
- **CSV Formula Injection Protection (`analytics.php`):**
  - Localized CSV headers via `get_string()` and prefixed exported query strings starting with `=`, `+`, `-`, `@`, `\t`, or `\r` with a single quote `'`.

---

## [1.1.1] - 2026-11-09 (`2026110901`)

### Added
- **InstantSearch Course & Template Priority Surfacing (`\local_typesensepro\instant_engine`):**
  - Automatically surfaces up to 3 accessible matching courses and course templates (`core_course-course`) to the top of the InstantSearch dropdown across categories.
  - Normalizes leading/embedded underscore query tokens (`_points`, `PA105_Primary`) during live typeahead.
  - Enforces `search_typesense/restrictusersearch` so students cannot query or view `core_user-user` results in InstantSearch when restricted.

---

## [1.1.0] - 2026-11-09 (`2026110900`)

### Added
- **Live Type-As-You-Go Navbar InstantSearch (`\local_typesensepro\instant_engine`, `\local_typesensepro\instant_widget`, `ajax_instant.php`):**
  - Turns Moodle's top navbar search bar and global search input into a live ~30–50ms typeahead dropdown with 100% Moodle permission enforcement (`check_access()`, group, and role checks).
  - **Interactive Scope Pills Inside Dropdown:** Switch between `Search everything`, `Current courses`, `Historical / other courses`, and `Video Time` directly inside the live dropdown without leaving the current page.
  - **Native Moodle Theme Styling:** Automatically inherits the active Moodle theme's Bootstrap 5 button classes (`.btn-primary`, `.btn-outline-secondary`), CSS variables, and primary brand palette.
  - **Rich Result Cards & Keyboard Navigation:** Displays `<mark>` highlighted titles and snippets, course and activity area badges, role-checked user identity badges, `↑`/`↓`/`Enter`/`Esc` keyboard navigation, and a 1-click **✨ AI Overview** jump button.
  - **Typesense `SITEID` Split-Query Optimization:** Separates site-level (`courseid: 1`) candidate queries from active course ID filters to avoid large bitmap union overhead on sites with 40,000+ user records.
  - **Admin Settings (`enableinstantsearch`, `instantshowai`, `instantshowlatency`):** Toggle InstantSearch, the AI Overview jump button, and the optional latency badge.

---

## [1.0.0] - 2026-11-08 (`2026110802`)

### Added
- **Permission-Safe AI Overviews with Clickable Citations (`\local_typesensepro\rag_summariser`, `ajax_overview.php`):**
  - Synthesizes top permission-verified search results into a 2–4 sentence summary with clickable `[1] [2]` source citations in ~700–800ms.
  - Connects directly to Moodle's built-in AI subsystem (`core_ai`) supporting Google Gemini (`aiprovider_gemini`, including free tier), OpenAI (`aiprovider_openai`), and Azure AI (`aiprovider_azureai`).
- **Scope-Aware Hybrid Semantic Search (`\local_typesensepro\embedding_client`, `generate_embeddings_adhoc_task`):**
  - Combines keyword scoring (`text_match`) with 1536-dim semantic vector search (`vector_query`) while restricting background embeddings to active courses by default to cut AI token costs by 85–90%.
- **AI Category Classifier, Term Auto-Rollover & Historical Intent Banner (`\local_typesensepro\ai_classifier`, `\local_typesensepro\historical_intent`):**
  - Analyzes category hierarchies and academic term codes (`FA26`, `SP27`, `2605`), adds confidence badges (`AI: 96% Active`) and rationales to the *Infer Active Categories* tool, and rolls over active categories via scheduled task (`sync_active_categories_task`).
  - Displays a 1-click spillover banner when a user searches for a past term code while in the `Current courses` scope.
- **Search Analytics & Zero-Result Insights Dashboard (`analytics.php`, `\local_typesensepro\analytics_logger`):**
  - Admin dashboard tracking total searches, p50 / p95 search latency, zero-result rate, top zero-result queries in current courses, and historical spillover queries, with CSV export and GDPR-compliant automatic log pruning (`cleanup_analytics_task`).
