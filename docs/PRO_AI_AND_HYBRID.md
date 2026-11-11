# AI Overviews, Hybrid Search & Smart Classification (`Typesense Pro`)

**Typesense Pro (`local_typesensepro`)** integrates with Moodle's native AI subsystem (`Site administration -> General -> AI`) to provide permission-safe cited AI Overviews, AI-assisted active category classification, hybrid semantic search, and smart message noise filtering.

---

## 1. Permission-Safe AI Overviews with Clickable Citations

When a user runs a search on the Global Search page, Typesense Pro can synthesize the top authorized search results into a concise 2–3 sentence **AI Overview** with clickable numbered source badges (`[1]`, `[2]`, `[3]`):

### How Permission Safety Is Guaranteed
- **Authorized Results Only:** The AI Overview is generated strictly from the top search results that have already been verified as accessible to the current user. Content from courses or activities the user cannot access is never read or included.
- **Permission-Isolated Caching:** Cached overviews are tied to the exact set of authorized documents shown to the user, ensuring two users with different course permissions never share an overview unless they have access to the exact same source items.
- **Prompt-Injection Protection:** Search result excerpts and user queries are isolated as untrusted quotations so text inside student forum posts or uploaded documents cannot override system instructions.
- **Course & Activity Context Aware:** The overview accurately references the Moodle activity type (*VideoTime Video*, *Book*, *Forum*, *Assignment*) and parent course name.

### Trigger Modes
Administrators can choose how AI Overviews are triggered under **Typesense Pro (AI & InstantSearch)**:
- **On-Demand Button *(Default)*:** Displays a **"Generate AI Overview from these results"** button above the search results so AI tokens are only used when a user explicitly requests an overview.
- **Automatic on 3+ Word Queries:** Automatically generates an AI Overview whenever a user searches with 3 or more words.

### Connecting Your AI Provider in Moodle
AI Overviews and AI Category Classification use Moodle's built-in AI subsystem:
1. Navigate to **Site administration $\rightarrow$ General $\rightarrow$ AI $\rightarrow$ AI providers**.
2. Enable and configure your preferred provider (**Google Gemini**, **OpenAI**, or **Microsoft Azure AI**) and ensure the **Generate text** action is enabled.
3. Navigate to **Site administration $\rightarrow$ General $\rightarrow$ AI $\rightarrow$ AI placements** to ensure AI actions are enabled on your site.

---

## 2. Scope-Aware Hybrid Semantic Search (Optional)

In addition to keyword and typo-tolerant matching, administrators can optionally enable **Hybrid Semantic Search** (`1536`-dimension vector embeddings) so conceptual searches match relevant course content even when exact keywords differ.

### Cost-Saving Active Course Scope
On multi-year Moodle sites, generating vector embeddings for years of archived courses wastes AI tokens. By default, Typesense Pro restricts background embedding generation to **Current courses & system areas**, reducing embedding token usage by **85–90%**.

### How to Enable Hybrid Semantic Search
Under **Site administration $\rightarrow$ Plugins $\rightarrow$ Search $\rightarrow$ Typesense $\rightarrow$ Typesense Pro (AI & InstantSearch)**:
1. Check **Enable Hybrid Semantic Search (Vector + Keyword)**.
2. Check **I consent to sending indexed document text and search queries to the configured Embedding API endpoint**.
3. Enter your **Embedding API Endpoint** (default: `https://api.openai.com/v1/embeddings`), **Embedding API Key**, and **Embedding Model** (default: `text-embedding-3-small`).

---

## 3. AI Category Classifier, Term Rollover & Historical Intent Banner

### AI Category Annotations
On the **Active Course Categories** page, Typesense Pro adds confidence badges (such as `AI: 96% Active · Term 2605`, `AI: 89% Upcoming · Term 2607`, or `AI: 84% Template`) and plain-English rationales explaining why each category was selected—including how many active-dated courses, recent enrollments, and automatically excluded expired courses exist in that folder.

### Automatic Term Rollover
When **Automatically synchronize active categories at term rollover** is enabled, a daily scheduled task re-checks course start/end dates and enrollment velocity so new academic term folders become active automatically when a new term begins.

### Historical Cohort Spillover Banner
If a user searches inside **Current courses** for a past cohort or term code (such as `2501` or `FA24`), or gets 0 results in **Current courses** when matching courses exist in **Historical / other courses**, a helpful 1-click banner appears above the results offering to switch directly to **Historical / other courses**.

---

## 4. Smart Message Noise & Broadcast Filtering

Multi-year Moodle sites often accumulate millions of rows in the Moodle messages table from automated system notifications, mass class announcements sent to hundreds of students, and 1-word replies (`"Thanks"`, `"Ok"`).

When **Enable Smart Message Noise & Broadcast Filtering** is enabled (default), Typesense Pro keeps message search fast and relevant by automatically filtering out:
- **Archived Historical Messages:** Only indexes messages from the recent retention window (default: **180 days**).
- **Trivial 1–2 Word Replies:** Skips messages shorter than the minimum character threshold (default: **20 characters**).
- **Automated System Notifications:** Skips `"Do not reply to this email"` and automated course/assignment notification boilerplate.
- **Mass Broadcast Fan-Out:** Caps duplicate copies of identical mass announcements sent by an instructor to large cohorts (default: **25 copies**).
