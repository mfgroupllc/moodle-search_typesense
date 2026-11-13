import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Typesense for Moodle',
  description: 'Administrator and user documentation for Typesense Search (search_typesense) and Typesense Pro (local_typesensepro) for Moodle LMS.',
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
      { text: 'Getting Started', link: '/GETTING_STARTED.html' },
      {
        text: 'Core Search Engine',
        items: [
          { text: 'How Search & Permissions Work', link: '/ARCHITECTURE.html' },
          { text: 'Administrator Settings Guide', link: '/ADMIN_GUIDE.html' },
          { text: 'Indexing & Active Course Scopes', link: '/INDEXING_AND_SCOPES.html' },
          { text: 'AWS Setup, Sizing & FAQ', link: '/OPERATIONS_AND_TROUBLESHOOTING.html' },
        ],
      },
      {
        text: 'Typesense Pro',
        items: [
          { text: 'Navbar InstantSearch & Discovery', link: '/PRO_INSTANTSEARCH.html' },
          { text: 'AI Overviews & Hybrid Search', link: '/PRO_AI_AND_HYBRID.html' },
          { text: 'Search Analytics & Privacy', link: '/PRO_ANALYTICS_AND_PRIVACY.html' },
        ],
      },
      {
        text: 'Release Notes',
        items: [
          { text: 'Typesense Search (v1.1.14)', link: '/CHANGELOG_CORE.html' },
          { text: 'Typesense Pro (v1.1.11)', link: '/CHANGELOG_PRO.html' },
        ],
      },
      { text: 'GitHub', link: 'https://github.com/mfgroupllc/moodle-search_typesense' },
    ],
    sidebar: [
      {
        text: 'Introduction',
        items: [
          { text: 'Documentation Home', link: '/' },
          { text: 'Getting Started Guide', link: '/GETTING_STARTED.html' },
        ],
      },
      {
        text: 'Core Search Engine',
        items: [
          { text: 'How Search & Permissions Work', link: '/ARCHITECTURE.html' },
          { text: 'Administrator Settings Guide', link: '/ADMIN_GUIDE.html' },
          { text: 'Indexing & Active Course Scopes', link: '/INDEXING_AND_SCOPES.html' },
          { text: 'AWS Setup, Sizing & FAQ', link: '/OPERATIONS_AND_TROUBLESHOOTING.html' },
        ],
      },
      {
        text: 'Typesense Pro Add-On',
        items: [
          { text: 'Navbar InstantSearch & Discovery', link: '/PRO_INSTANTSEARCH.html' },
          { text: 'AI Overviews & Hybrid Search', link: '/PRO_AI_AND_HYBRID.html' },
          { text: 'Search Analytics & Privacy', link: '/PRO_ANALYTICS_AND_PRIVACY.html' },
        ],
      },
      {
        text: 'Release Notes',
        items: [
          { text: 'Typesense Search Changelog', link: '/CHANGELOG_CORE.html' },
          { text: 'Typesense Pro Changelog', link: '/CHANGELOG_PRO.html' },
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
