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
| **Searchable user profile fields** | `ID number, Email address, Department, Institution` | Selects which standard (`ID number`, `Email address`, `Username`, `Phone`, `Mobile phone`, `Department`, `Institution`, `City/town`, `Country`) and custom user profile fields are indexed and shown as badges on user search results for authorized staff. |

> **Privacy Note:** Profile fields are only searchable and visible to staff whose Moodle role grants permission to view user identity fields (`moodle/site:viewuseridentity` or `moodle/user:viewalldetails`).

### Video Time Integration

When the **Video Time (`mod_videotime`)** activity plugin is installed on your Moodle site, two additional settings appear automatically:

| Setting | Default | Description |
| :--- | :--- | :--- |
| **Show Video Time filter button** | Enabled | Displays a dedicated **Video Time** filter button on the search page and inside the InstantSearch dropdown. |
| **Video Time button label** | `Video Time` | Customizes the button label (for example, `Video Library` or `Lecture Videos`). |
