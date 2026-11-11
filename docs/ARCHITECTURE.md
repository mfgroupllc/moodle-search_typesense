# How Search & Permissions Work

Typesense for Moodle is designed for universities, colleges, K-12 districts, and training organizations that need fast, typo-tolerant search across years of courses without compromising Moodle's permission model.

---

## 1. Strict Moodle Permission Enforcement

Every search result—whether viewed on the full Global Search page, in the Live Navbar InstantSearch dropdown, or inside an AI Overview—is verified against the logged-in user's exact Moodle permissions before it is shown:

- **Course & Enrollment Access:** Users only see content from courses they are enrolled in or permitted to access based on their Moodle roles.
- **Activity & Section Visibility:** Hidden activities, restricted course sections, and unreleased items are never shown to students.
- **Group Isolation:** Activities configured for **Separate groups** only return content belonging to the user's own group(s).
- **Private User Items:** Personal messages and private user records are restricted strictly to their owner.
- **No Unverified Result Counts:** Result totals only reflect items the current user is authorized to view.

---

## 2. What You Can Search

### Courses & Course Templates
- Searching a course title, course code, or shortname (such as `PB101`, `PA105-O`, `_points`, or `Template_V2_Points`) automatically surfaces matching courses and course templates at the top of the results page.
- Hyphens, underscores, slashes, and periods in course codes are tokenized automatically so users can search with or without punctuation.

### Student ID Numbers & User Profiles (Staff Only by Default)
- Authorized staff and instructors who hold Moodle user-identity permissions can look up students and colleagues by **Name**, **Student ID / ID number**, **Email address**, **Phone number**, **Department**, **Institution**, or custom profile fields.
- Typing a student ID prefix (for example, `9999` for student number `9999999998`) or a slightly misspelled name immediately surfaces the matching user profile at the top of the results.
- By default, **Restrict user profile search to staff** is enabled so students cannot search user profiles or see the **Users** filter tab.

### Course Activities & Learning Content
- Searches across all enabled Moodle search areas, including **Books**, **Pages**, **Folders**, **Files/Resources**, **Assignments**, **Quizzes**, **Forums**, **Glossaries**, **Lessons**, **Wikis**, **Video Time** videos, and more.
- **Strict Multi-Term Narrowing:** Entering multiple words (such as `PB101 grading rubric` or `poaching pears recipe`) narrows results to items containing all of your search terms.
- **Typo & Accent Tolerance:** Automatically handles common spelling typos and matches accented characters (`resume` ↔ `résumé`, `naive` ↔ `naïve`, `San Jose` ↔ `San José`).

### Attached Documents & Video Captions
- When **Index attached files & documents** is enabled, Moodle extracts and indexes text inside uploaded files attached to course resources, folders, books, forums, and assignments:
  - **PDF Documents:** `.pdf`
  - **Microsoft Office & OpenDocument:** `.docx`, `.pptx` (including slide text and speaker notes), `.xlsx`, `.odt`, `.odp`, `.ods`
  - **Video Captions & Subtitles:** `.vtt`, `.srt` (with cue timestamps stripped automatically so spoken lecture transcripts are searchable)
  - **Text & Data Files:** `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.json`
- All document text extraction runs locally on your Moodle server—files are never sent to an external conversion service.

---

## 3. Search Scopes

To prevent years of archived courses from cluttering daily searches, the search interface provides dedicated scope tabs:

| Scope | What It Searches |
| :--- | :--- |
| **Current courses** | Searches active courses in your current academic term(s) and active templates, excluding completed cohorts and expired courses. |
| **Historical / other courses** | Searches past cohorts and archived courses when faculty or staff need to reference prior terms. |
| **Search everything** | Searches across all accessible courses (both current and historical) as well as site-wide areas and user profiles. |
| **Video Time** *(when installed)* | Automatically appears when the `mod_videotime` plugin is installed, filtering results exclusively to Video Time activities and video transcripts. |
