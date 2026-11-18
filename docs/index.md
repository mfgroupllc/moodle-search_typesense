---
layout: home

hero:
  name: "Typesense for Moodle"
  text: "Sub-Second Global Search, InstantSearch & AI Overviews"
  tagline: "Administrator and user documentation for Typesense Search (v1.1.16) and Typesense Pro (v1.1.12) on Moodle 4.5 LTS and Moodle 5.x."
  image:
    src: /typesense-icon.png
    alt: Typesense for Moodle
  actions:
    - theme: brand
      text: Getting Started Guide →
      link: /GETTING_STARTED.html
    - theme: alt
      text: Administrator Guide
      link: /ADMIN_GUIDE.html
    - theme: alt
      text: Typesense Pro Features
      link: /PRO_INSTANTSEARCH.html

features:
  - title: "100% Moodle Permission Enforcement"
    details: "Every search result respects course enrollments, activity visibility, separate groups, and role capabilities—eliminating slow page timeouts on large multi-year sites."
    link: /ARCHITECTURE.html
  - title: "Instant Course & Student ID Lookup"
    details: "Courses, course templates, and—for authorized staff—student ID numbers and user profiles surface immediately at the top of search results."
    link: /ARCHITECTURE.html#_2-what-you-can-search
  - title: "Active vs. Historical Course Scopes"
    details: "Keep daily search focused on current academic terms while keeping archived cohorts one click away under Historical / other courses."
    link: /INDEXING_AND_SCOPES.html
  - title: "Inside Uploaded Files & Video Captions"
    details: "Searches inside uploaded PDFs, Word documents, PowerPoint slides, Excel spreadsheets, and Video Time caption transcripts locally on your Moodle server."
    link: /ADMIN_GUIDE.html#_2-content-users-integrations
  - title: "Live Server Health Diagnostics"
    details: "Monitor Typesense server version, cluster/Raft state, host memory/CPU/disk pressure, HTTP latency, and collection document counts directly inside Moodle Site administration."
    link: /ADMIN_GUIDE.html#_3-server-health
  - title: "Live Type-As-You-Go Navbar InstantSearch (Pro)"
    details: "Interactive typeahead dropdown right in Moodle's top navigation bar with scope pills, highlighted snippets, and automatic fallback when a restricted scope has no hits."
    link: /PRO_INSTANTSEARCH.html
  - title: "Cited AI Overviews & Search Analytics (Pro)"
    details: "Generate concise 2–3 sentence AI Overviews with clickable [1] [2] source links from authorized results, plus zero-result and latency analytics."
    link: /PRO_AI_AND_HYBRID.html
  - title: "Search Synonyms from Zero-Result Searches (Pro)"
    details: "Turn misspellings and different word forms that found nothing into reviewed synonym rules, suggested through Moodle's AI subsystem or added by hand, applied by Typesense on every search."
    link: /PRO_AI_AND_HYBRID.html#_5-search-synonyms-from-zero-result-searches
---

## Plugin Editions

| Edition | Latest Release | Moodle Support | Availability |
| :--- | :--- | :--- | :--- |
| **Typesense Global Search (`search_typesense`)** | `v1.1.16` | Moodle 4.5 LTS, 5.x | Free & Open-Source ([GitHub](https://github.com/mfgroupllc/moodle-search_typesense)) |
| **Typesense Pro (`local_typesensepro`)** | `v1.1.12` | Moodle 4.5 LTS, 5.x | Commercial Subscription ([Moodle Marketplace](https://marketplace.moodle.com/plugins/local_typesensepro)) |
