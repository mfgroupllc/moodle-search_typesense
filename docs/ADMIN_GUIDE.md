# Administrator Settings Guide

All Typesense settings and administration tools are grouped in one place under:
**Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense**

A shared tab bar at the top of every page lets administrators switch between:
1. **Connection & Scopes**
2. **Content, Users & Integrations**
3. **Fast Area Re-index (NDJSON)**
4. **Active Course Categories**
5. **Typesense Pro (AI & InstantSearch)** *(when Typesense Pro is installed)*
6. **Search Analytics** *(when Typesense Pro is installed)*

---

## 1. Connection & Scopes

### Server Connection

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Typesense URL** | `http://127.0.0.1` | The URL of your Typesense server (for example, `https://10.0.12.236` on a private AWS VPC subnet, or `https://xxx.a1.typesense.net` on Typesense Cloud). |
| **Port** | `8108` | TCP port used by Typesense (`8108` for self-hosted deployments, `443` for Typesense Cloud). |
| **API key** | *(empty)* | Your Typesense API key. |
| **Collection name** | `moodle` | Name of the Typesense collection where Moodle search documents are stored. |
| **Verify SSL certificate** | Unchecked | Enable when using a public CA certificate (such as Typesense Cloud). Leave unchecked when connecting over a private VPC subnet to a self-signed certificate. |
| **Request timeout (seconds)** | `30` | Maximum timeout for bulk indexing and search operations. Connection establishment always uses a fast 2-second timeout so Moodle pages never hang if the search server is offline. |

### Active vs. Historical Course Scopes

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Current course categories** | *(empty)* | Selects which course categories represent active/current academic terms. Subcategories beneath selected categories are included automatically (with completed cohort folders and expired courses filtered out automatically). You can populate this automatically using the **Active Course Categories** tool. |
| **Default search scope** | `Current courses` | Sets the default scope tab selected when opening the full Global Search page: **Current courses**, **Historical / other courses**, or **Search everything**. |

---

### Searching Only Courses a User Can Open
On large sites most matches for a common word sit in courses a given student cannot open, so Moodle would spend its time discarding them. With **Search only courses the user can open** enabled (the default), users without site-wide course access only have content requested for:
- courses they are enrolled in, and
- visible courses open through the methods selected in **Enrollment methods that open a course without enrolling** (Guest access by default).

Matching course titles are still looked up across the whole site, the Courses tab is not narrowed, Moodle's access checks still run on every result, and administrators, staff with site-wide course access, and users with a role in a course category are never narrowed. **Reuse a user's course list for** controls how long the list is cached (5 minutes by default); a new enrollment can take that long to appear in search. If content in an open course is missing for non-enrolled users, add that course's enrollment method to the list.

## 2. Content, Users & Integrations

### Attached Document & File Content Indexing

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Index attached files & documents** | Enabled | Extracts and indexes text from uploaded files (`.pdf`, `.docx`, `.pptx`, `.xlsx`, `.odt`, `.odp`, `.ods`, `.txt`, `.md`, `.csv`, `.tsv`, `.html`, `.xml`, `.vtt`, `.srt`, `.json`) attached to course resources, folders, forums, books, and assignments (up to 60,000 characters per item, for files up to 20 MB). |
| **Path to `pdftotext`** | `/usr/bin/pdftotext` | Optional path to the `pdftotext` utility (`poppler-utils`) for fast local PDF text extraction. If not installed, the plugin automatically uses Moodle's configured Ghostscript path or its built-in PDF text parser. |

### User Profile Search & Student Privacy

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Restrict user profile search to staff** | Enabled | When enabled, only staff and instructors who hold Moodle user-identity permissions can search user profiles or see the **Users** filter tab. Students cannot search user profiles. |
| **Hide non-enrollable courses from student search results** | Enabled | When enabled, students only see course results (`core_course-course`) for courses they are enrolled in, can already access (such as open guest or auto-enroll courses), or can actively self-enroll in. Historical and cohort-locked courses they cannot join are hidden from students while remaining searchable for staff, faculty, and managers. |
| **Searchable user profile fields** | `ID number, Email address, Department, Institution` | Selects which standard (`ID number`, `Email address`, `Username`, `Phone`, `Mobile phone`, `Department`, `Institution`, `City/town`, `Country`) and custom user profile fields are indexed and shown as badges on user search results for authorized staff. |

> **Privacy Note:** Profile fields are only searchable and visible to staff whose Moodle role grants permission to view user identity fields (`moodle/site:viewuseridentity` or `moodle/user:viewalldetails`).

### Video Time Integration

When the **Video Time (`mod_videotime`)** activity plugin is installed on your Moodle site, two additional settings appear automatically:

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Show Video Time filter button** | Enabled | Displays a dedicated **Video Time** filter button on the search page and inside the InstantSearch dropdown. |
| **Video Time button label** | `Video Time` | Customizes the button label (for example, `Video Library` or `Lecture Videos`). |
- **Show matching Video Time activities near the top:** When enabled (the default), Video Time activities whose title matches the search appear after matching courses and before other content on the first page of results and in the Typesense Pro dropdown.
