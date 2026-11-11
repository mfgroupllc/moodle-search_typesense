# InstantSearch & Guided Discovery (`local_typesensepro`)

This guide explains the architecture, scope behavior, priority ranking, and configuration of **Live Type-As-You-Go Navbar InstantSearch** and **Guided Discovery** in `local_typesensepro`.

---

## 1. How Live Navbar InstantSearch Works

Unlike client-side search widgets that expose a Typesense API key directly to the browser (which would bypass Moodle's per-course, per-group, and per-activity access checks), `local_typesensepro` keeps your Typesense server strictly inside your private network:

1. **Hook Injection (`\local_typesensepro\hook_callbacks::before_footer_html_generation`):**
   - Renders `\local_typesensepro\instant_widget::render()` once per page for authenticated users who hold `moodle/search:query`.
   - Attaches a lightweight Vanilla ES6 controller (`120 ms` keystroke debounce + `AbortController` request cancellation) to Moodle's top navbar search input (`input[name="q"]`) and the Global Search page input.
2. **Session-Free AJAX Endpoint (`/local/typesensepro/ajax_instant.php`):**
   - Validates the logged-in user and immediately releases the PHP session lock (`\core\session\manager::write_close()`) so rapid typing never blocks concurrent page navigation.
3. **Permission-Safe Candidate Engine (`\local_typesensepro\instant_engine`):**
   - Extends `\search_typesense\candidate_engine` with low-latency parameters (`BATCH_SIZE = 15`, `MAX_CANDIDATES = 45`, `TIME_BUDGET = 2` seconds, `max_candidates = 200`).
   - Every returned item passes `candidate_data()`, `matches_filters()`, `context_allows()`, and `$area->check_access()`, plus role-aware `user_profile_fields` capability checks.

---

## 2. Scope Pills & Automatic 0-Hit Fallback

Inside the live InstantSearch dropdown, users can switch scopes with a single click:
1. **`Search everything` (`all` — Default First Pill):** Searches across all courses, activities, books, resources, and—for authorized staff—user profiles (`core_user-user`).
2. **`Current courses` (`current`):** Restricts results to courses inside active categories (`search_typesense/currentcategories`, excluding completed cohorts and expired courses).
3. **`Historical / other courses` (`historical`):** Restricts results to past/archived courses.
4. **`Video Time` (`videotime`):** Appears automatically when `mod_videotime` is installed and enabled, filtering strictly to Video Time activities and transcripts.

### Automatic 0-Hit Scope Fallback
If a user selects a restricted scope pill (`Current courses`, `Historical / other courses`, or `Video Time`) and types a query (such as a student ID number `9999` or an archived course code) that returns **0 authorized hits** in that restricted scope:
- `ajax_instant.php` automatically re-runs `search_instant($q, 'all', $limit)`.
- If matches exist in `Search everything`, the response returns `fallbackscope = 'all'`, automatically switches the active scope pill in the dropdown to **`Search everything`**, and displays the matching results immediately.

---

## 3. Priority Course & Student ID (`idnumber`) Top-Surfacing

Before running the general multi-area candidate loop, `instant_engine::search_instant()` executes two targeted priority passes (up to `min(3, $limit)` items each):

1. **`prepend_priority_courses()`:** Surfaces matching courses and course templates (`core_course-course`) to the top of the dropdown so typing `"PB101"` or `"PA105-O"` always shows the course shell first.
2. **`prepend_priority_users()`:**
   - Queries `areaid:[core_user-user]` with `max_candidates = 500` and `num_typos = 0` first, placing direct prefix matches on `idnumber` (student number) or `title` (full name) into `tier0`.
   - Falls back to typo-tolerant matching so misspelled names (e.g., `"Sam Hutchens"` $\rightarrow$ `"Sam Hutchins"`) still resolve at the top of the dropdown.

---

## 4. `SITEID` Split-Query Optimization

On sites with 40,000+ user profiles (`courseid = SITEID = 1`) and 500+ active courses in `Current courses`, sending a single Typesense query with `courseid:[1, ...500 course IDs...]` forces Typesense to union the 40,000-document `courseid:1` bitmap with 500 course bitmaps on every keystroke.

`instant_engine::search_instant()` eliminates this overhead by:
- Running `prepend_priority_users()` directly against `areaid:[core_user-user]`.
- Stripping `SITEID` from the course list in the main course-activity query (`$coursesonlyscope`) and only querying `courseid:[SITEID]` separately if the course-activity pass returns fewer than `$target` hits.

---

## 5. Guided Discovery Chips (`\local_typesensepro\message_classifier::render_discovery_guide`)

When `local_typesensepro/enablediscoveryguide` is enabled (default), opening the Global Search page (`/search/engine/typesense/query.php`) before entering a query displays interactive **What you can search** cards with 1-click example chips:
- **Inside Uploaded PDFs & Recipes:** Searches text extracted from attached `.pdf`, `.docx`, `.pptx`, and `.xlsx` files.
- **Inside VideoTime Lectures:** Searches spoken video captions (`.vtt` / `.srt`) and video descriptions.
- **Inside Rubrics & Syllabi:** Searches course books, assignments, and grading guides.
- **Ask AI Overview:** Demonstrates multi-term natural-language queries with cited AI Overviews.
