# Live Navbar InstantSearch & Guided Discovery (`Typesense Pro`)

**Typesense Pro (`local_typesensepro`)** upgrades Moodle's top navigation search bar and Global Search page with a live, type-as-you-go dropdown and interactive discovery prompts—without requiring any AI API keys or exposing your Typesense server to the public internet.

---

## 1. Live Type-As-You-Go Navbar InstantSearch

As soon as a user clicks the search icon in Moodle's top navbar and begins typing (2 or more characters), a live results dropdown appears in milliseconds:

- **100% Moodle Permission Safe:** Every item in the live dropdown is verified against the current user's Moodle course enrollments, activity visibility, group memberships, and role permissions before it is displayed.
- **No Public Port Required:** Browsers communicate only with your Moodle web server over standard HTTPS; your Typesense server remains safely inside your private network.
- **Rich Result Cards:** Shows highlighted matching terms, activity type badges (*Course*, *Book - chapters*, *Video Time*, *Users*, etc.), parent course names, and—for authorized staff—user profile badges (*ID number*, *Email address*, *Phone*).
- **Full Keyboard Navigation:** Use `↑` and `↓` arrow keys to move through results, `Enter` to open the selected item, or `Esc` to close the dropdown.

---

## 2. Scope Pills & Automatic Fallback

At the top of the InstantSearch dropdown, interactive scope pills let users filter live results without leaving their current page:

1. **Search everything *(Default First Pill)*:** Searches across all accessible courses, activities, uploaded files, and—for authorized staff—student ID numbers and user profiles right from the first keystroke.
2. **Current courses:** Restricts live results to active courses in the current academic term(s).
3. **Historical / other courses:** Restricts live results to past/archived courses.
4. **Video Time *(when installed)*:** Filters live results exclusively to Video Time video activities and caption transcripts.

### Automatic Zero-Hit Fallback
If a user selects a restricted scope pill (such as **Current courses**) and types a query—like a student ID number (`9999`) or an archived course code—that has `0` matches in that scope, InstantSearch automatically checks **Search everything**, switches the active pill to **Search everything**, and displays the matching results immediately.

---

## 3. Priority Course & Student ID Lookup

InstantSearch automatically surfaces the most direct matches to the top of the dropdown:
- **Courses & Course Templates:** Typing a course code or shortname (such as `PB101`, `PA105-O`, or `_points`) places the matching course shell at the top of the list ahead of individual forum posts or assignments.
- **Student ID Numbers & Names (Staff Only):** When authorized staff type a student ID prefix (such as `9999` for `9999999998`), email, or student/instructor name, matching user profiles appear immediately at the top of the dropdown.

---

## 4. Guided Discovery Cards

When users open the full Global Search page before entering a query, **Guided Discovery** displays helpful example search cards showing what they can search across your Moodle site:
- **Inside uploaded files**
- **Inside Video Time activities** (shown only when the Video Time plugin is installed and its search tab is enabled)
- **Inside activities**
- **Ask a question** (shown only when AI Overviews are enabled)

Clicking any example chip runs the search immediately so learners and faculty can see how Global Search works.

---

## 5. Administrator Settings for InstantSearch & Discovery

Configure these options under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Typesense Pro (AI & InstantSearch)**:

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Enable Typesense Pro features** | Enabled | Master toggle for all Typesense Pro features. |
| **Enable live navbar InstantSearch** | Enabled | Turns Moodle's top navbar search input and Global Search box into a live type-as-you-go dropdown. |
| **InstantSearch results limit** | `6` | Maximum number of authorized results shown in the live dropdown (`1`–`10`). |
| **Show AI Overview button in InstantSearch** | Enabled | Displays a 1-click **✨ AI Overview** link in the dropdown footer when AI Overviews are enabled. |
| **Show search latency badge** | Disabled | Optional millisecond timing badge (`e.g., 28 ms`) in the dropdown header for administrators testing performance. |
| **Show Guided Discovery cards on empty search page** | Enabled | Displays clickable example search cards when a user opens the Global Search page before typing a query. |
