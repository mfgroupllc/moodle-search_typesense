import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Typesense for Moodle',
  description: 'Official documentation for search_typesense (Free Core Engine) and local_typesensepro (Typesense Pro AI & InstantSearch) for Moodle LMS.',
  base: '/moodle-typesense/docs/',
  cleanUrls: false,
  lastUpdated: false,
  head: [
    ['link', { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon-32x32.png' }],
    ['link', { rel: 'icon', type: 'image/png', sizes: '16x16', href: '/favicon-16x16.png' }],
  ],
  themeConfig: {
    siteTitle: 'Typesense for Moodle',
    search: {
      provider: 'local',
    },
    nav: [
      { text: 'Overview', link: 'https://www.chrismurad.com/moodle-typesense' },
      { text: 'Getting Started', link: '/GETTING_STARTED' },
      {
        text: 'Core Engine (search_typesense)',
        items: [
          { text: 'Architecture & Security', link: '/ARCHITECTURE' },
          { text: 'Administrator Guide', link: '/ADMIN_GUIDE' },
          { text: 'Fast NDJSON Indexing & Scopes', link: '/INDEXING_AND_SCOPES' },
          { text: 'Operations, AWS & Troubleshooting', link: '/OPERATIONS_AND_TROUBLESHOOTING' },
        ],
      },
      {
        text: 'Typesense Pro (local_typesensepro)',
        items: [
          { text: 'InstantSearch & Guided Discovery', link: '/PRO_INSTANTSEARCH' },
          { text: 'AI Overviews (RAG) & Hybrid Search', link: '/PRO_AI_AND_HYBRID' },
          { text: 'Search Analytics & Privacy', link: '/PRO_ANALYTICS_AND_PRIVACY' },
        ],
      },
      {
        text: 'Changelogs',
        items: [
          { text: 'search_typesense (v1.1.9)', link: '/CHANGELOG_CORE' },
          { text: 'local_typesensepro (v1.1.7)', link: '/CHANGELOG_PRO' },
        ],
      },
      { text: 'GitHub', link: 'https://github.com/mfgroupllc/moodle-search_typesense' },
    ],
    sidebar: [
      {
        text: 'Introduction',
        items: [
          { text: 'Documentation Home', link: '/' },
          { text: 'Getting Started Guide', link: '/GETTING_STARTED' },
        ],
      },
      {
        text: 'Core Engine (search_typesense)',
        items: [
          { text: 'Architecture & Security Model', link: '/ARCHITECTURE' },
          { text: 'Administrator & Settings Guide', link: '/ADMIN_GUIDE' },
          { text: 'Fast NDJSON Indexing & Scopes', link: '/INDEXING_AND_SCOPES' },
          { text: 'Operations, AWS & Troubleshooting', link: '/OPERATIONS_AND_TROUBLESHOOTING' },
        ],
      },
      {
        text: 'Typesense Pro (local_typesensepro)',
        items: [
          { text: 'InstantSearch & Guided Discovery', link: '/PRO_INSTANTSEARCH' },
          { text: 'AI Overviews (RAG) & Hybrid Search', link: '/PRO_AI_AND_HYBRID' },
          { text: 'Search Analytics & Privacy', link: '/PRO_ANALYTICS_AND_PRIVACY' },
        ],
      },
      {
        text: 'Release Notes',
        items: [
          { text: 'search_typesense Changelog', link: '/CHANGELOG_CORE' },
          { text: 'local_typesensepro Changelog', link: '/CHANGELOG_PRO' },
        ],
      },
    ],
    footer: {
      message: 'Licensed under the GNU GPL v3 or later.',
      copyright: '© 2026 Chris Murad Consulting, LLC. All rights reserved.',
    },
    outline: {
      level: [2, 3],
    },
  },
})
