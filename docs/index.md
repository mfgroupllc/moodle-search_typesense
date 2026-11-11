---
layout: home

hero:
  name: "Typesense for Moodle"
  text: "Sub-Second Global Search, InstantSearch & Permission-Safe AI Overviews"
  tagline: "Complete administrator and developer documentation for search_typesense (Free Core Engine v1.1.9) and local_typesensepro (Typesense Pro v1.1.7) on Moodle 4.1 LTS, 4.5 LTS, and Moodle 5.x."
  actions:
    - theme: brand
      text: Getting Started Guide →
      link: /GETTING_STARTED
    - theme: alt
      text: Architecture & Security
      link: /ARCHITECTURE
    - theme: alt
      text: Typesense Pro (InstantSearch & AI)
      link: /PRO_INSTANTSEARCH

features:
  - title: "Candidate-First Pagination & 100% Moodle Permissions"
    details: "Eliminates 10+ second context pre-calculation timeouts on large multi-year Moodle instances while enforcing candidate_data(), matches_filters(), context_allows(), and $area->check_access() on every result."
    link: /ARCHITECTURE
  - title: "Priority Course, Template & Student ID (idnumber) Lookup"
    details: "Surfaces matching courses, templates, and student ID prefixes (e.g., typing 9999 for 9999999998) directly to the top of results via dedicated exact-prefix and typo-tolerant priority passes."
    link: /ARCHITECTURE#_4-priority-top-surfacing-passes
  - title: "Primary-Key Chunked Fast NDJSON Indexer"
    details: "Streams activities, active-course forum posts, and messages in pre-joined primary-key chunks with zero get_fast_modinfo() cache thrashing, live AJAX progress tracking, and 100% local PDF/Office file extraction."
    link: /INDEXING_AND_SCOPES
  - title: "Active vs. Historical Course Scopes"
    details: "Infers active categories from course start/end dates, enrollment velocity, and cohort naming patterns (2605, 2607, FA26), automatically excluding completed cohorts and expired courses."
    link: /INDEXING_AND_SCOPES#_3-active-course-category-inference-date-filtering-search-typesense-course-scope
  - title: "Live Type-As-You-Go Navbar InstantSearch (Pro)"
    details: "Sub-50ms permission-safe typeahead in Moodle's top navbar defaulting to Search everything with automatic 0-hit scope fallback, scope pills, keyboard navigation, and zero public Typesense port exposure."
    link: /PRO_INSTANTSEARCH
  - title: "Permission-Safe AI Overviews & Hybrid Vector Search (Pro)"
    details: "Synthesizes already-authorized search results into cited 2–3 sentence overviews via Moodle core_ai with prompt-injection hardening, plus 1536-dim Hybrid Semantic Search and Search Analytics."
    link: /PRO_AI_AND_HYBRID
---

## Ecosystem Overview

| Plugin | Component | Latest Release | License | Distribution |
| :--- | :--- | :--- | :--- | :--- |
| **Typesense Global Search Engine** | `search_typesense` | `v1.1.9` (`2026111006`) | GNU GPL v3+ | [GitHub (`mfgroupllc/moodle-search_typesense`)](https://github.com/mfgroupllc/moodle-search_typesense) |
| **Typesense Pro (AI & InstantSearch)** | `local_typesensepro` | `v1.1.7` (`2026111004`) | GNU GPL v3+ | [Moodle Marketplace (`local_typesensepro`)](https://marketplace.moodle.com/plugins/local_typesensepro) |
