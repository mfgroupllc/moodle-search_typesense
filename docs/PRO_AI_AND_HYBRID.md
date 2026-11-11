# AI Overviews (RAG), Hybrid Vector Search & Smart Classification (`local_typesensepro`)

This guide covers the AI and semantic features in `local_typesensepro`: Permission-Safe AI Overviews (RAG), Scope-Aware Hybrid Semantic Search, AI Category Classification & Term Auto-Rollover, and Smart Message Noise Filtering.

---

## 1. Permission-Safe AI Overviews with Clickable Citations (`\local_typesensepro\rag_summariser`)

### 1.1 Zero-Bypass Security Architecture
1. **Strict Post-Authorization Input:** `rag_summariser::extract_authorised_sources()` accepts **only** the array of `\core_search\document` objects in `$results->results` that have already passed all four Moodle permission stages (`candidate_data()`, `matches_filters()`, `context_allows()`, and `$area->check_access()`). Raw Typesense candidates are never read by the summarizer.
2. **Per-Permission Deterministic Cache Key (`build_cache_key()`):**
   - Computes `sha1('v2|' . strtolower($query) . '|' . implode('|', "{docid}:{modified}"))`.
   - Two users with different course enrollments or role permissions will never share a cached AI Overview unless they are authorized for the exact same top documents and versions.
3. **Prompt Injection Hardening:**
   - Strips `<untrusted_source>` and `<user_query>` tags from inputs, caps each source excerpt at 420 characters, and wraps the user query and each source in isolated `<user_query>` and `<untrusted_source index="1..5">` XML blocks with explicit system instructions to treat all contents strictly as untrusted quotations.
4. **Moodle `core_ai` Integration & Automatic Policy Registration:**
   - Executes through Moodle's native `\core_ai\manager::process_action(new \core_ai\aiactions\generate_text(...))` (supporting `aiprovider_gemini`, `aiprovider_openai`, and `aiprovider_azureai`).
   - When a user explicitly clicks **"Generate AI Overview from these results"** (`?aiask=1`), `embedding_client::can_user_use_core_ai()` automatically records `user_policy_accepted()` if not yet registered so the user is never blocked by a silent policy check.

### 1.2 Trigger Modes (`local_typesensepro/ragmode`)
- **`button` (On-Demand Button — Default):** Displays a **`"Generate AI Overview from these results"`** button above search results; only calls the AI provider when clicked (or displays immediately if already cached).
- **`auto` (Automatic on 3+ Word Queries):** Automatically generates the AI Overview when the search query contains 3 or more words.

---

## 2. Scope-Aware Hybrid Semantic Search (`\local_typesensepro\embedding_client`)

When enabled, Hybrid Semantic Search combines exact keyword scoring (`_text_match`) with `1536`-dimension vector similarity (`vector_query`) inside Typesense:

### 2.1 Configuration & Consent Gates
Because Moodle's `core_ai` subsystem does not yet include an embeddings action, Hybrid Search requires three explicit admin settings under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Typesense Pro (AI & InstantSearch)**:
1. `local_typesensepro/enablehybridsearch` (`1` = Enabled)
2. `local_typesensepro/embeddingconsent` (`1` = Explicit administrator consent to send document/query text to the configured embedding endpoint)
3. `local_typesensepro/embeddingapikey` and `local_typesensepro/embeddingendpoint` (defaults to `https://api.openai.com/v1/embeddings` with model `text-embedding-3-small`, `1536` dimensions)

### 2.2 Active-Course Embedding Scope (`local_typesensepro/embeddingscope`)
- Defaults to **`current` (`Current courses & system areas`)**.
- When `plugin::after_documents_indexed()` is called during indexing, `\local_typesensepro\task\generate_embeddings_adhoc_task` only generates vector embeddings for documents belonging to active courses (`course_scope::courseids('current')`) and site-level areas (`SITEID`), cutting embedding API token usage by **85–90%** on multi-year sites.

---

## 3. AI Category Classifier, Term Auto-Rollover & Historical Intent (`\local_typesensepro\ai_classifier`, `\local_typesensepro\historical_intent`)

### 3.1 Date- & Cohort-Aware AI Category Classifier
On `/search/engine/typesense/infer_categories.php`, `ai_classifier::classify_categories()` annotates each inferred active category with:
- **Classification:** `active`, `upcoming`, or `template`
- **Confidence Score:** `0–100%`
- **Detected Term / Cohort Code:** e.g., `2605`, `2607`, `FA26`, `SP27`
- **Plain-English Rationale:** Combines live course date counts (`activedatedcourses`), expired course counts (`expiredcourses`), recent enrollments (`recentenrols`), and recent learner accesses (`recentaccesses`), and notes when expired courses inside a shared folder are automatically excluded by start/end date.

### 3.2 Automatic Term Rollover (`\local_typesensepro\task\sync_active_categories_task`)
When `local_typesensepro/enableautorollover` is enabled, this daily scheduled task re-evaluates `course_scope::infer_current_categories()` at term boundaries and updates `search_typesense/currentcategories` automatically.

### 3.3 Historical Cohort Spillover Banner (`\local_typesensepro\historical_intent`)
When a user searches in the **`Current courses`** scope for a past cohort/term code (e.g., `2501`, `FA24`) or gets 0 results in `Current courses` while matching visible courses exist in `Historical / other courses`, `historical_intent::render_banner()` renders a 1-click callout banner above the search results to switch scopes immediately.

---

## 4. Smart Message Noise & Broadcast Filtering (`\local_typesensepro\message_classifier`)

Multi-year Moodle sites often accumulate millions of rows in `{messages}` from automated system notifications, mass class announcements copied to hundreds of students, and 1-word replies (`"Thanks"`, `"Ok"`).

When `local_typesensepro/enablemessagefilter` is enabled (default), `message_classifier` hooks into `search_typesense` via `pro_bridge`:
1. **$O(\log N)$ Binary Primary-Key Retention Lookup (`get_message_start_pk()`):** Uses binary search on `{messages}.id` to jump directly to `now - messageretentiondays` (default `180` days) without scanning historical rows.
2. **Minimum Meaningful Length (`messageminchars`, default `20` chars):** Skips trivial 1–2 word acknowledgments.
3. **Automated Boilerplate Filter:** Skips system notification strings (`"Do not reply to this email"`, `"You have submitted your assignment submission"`, etc.).
4. **Broadcast Fan-Out Deduplication (`messagemaxfanout`, default `25`):** Tracks a normalized hash of each sender's message body during an indexing run and caps identical mass-broadcast copies so a single announcement sent to 500 students does not flood search results.
