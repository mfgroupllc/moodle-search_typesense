# Release Notes — Typesense Pro (`local_typesensepro`)

All notable updates to **Typesense Pro (`local_typesensepro`)** for Moodle are documented below.

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
