// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Offer the links in this page's footer for the search shortcut list.
 *
 * Runs only on the Typesense settings page. The footer is read as the theme renders it, so links from unused theme
 * settings never appear, and nothing is saved until the administrator saves the page.
 *
 * @module     search_typesense/footer_links
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

/** @var {string} Footer regions used by Moodle themes. */
const FOOTER_SELECTOR = 'footer, #page-footer, [role="contentinfo"]';

/** @var {RegExp} Links that act rather than navigate. */
const ACTION_LINK = /[?&]sesskey=|\/login\/logout\.php|\/course\/switchrole\.php|\/course\/loginas\.php/i;

/**
 * Lines already in the list, keyed by URL.
 *
 * @param {HTMLTextAreaElement} textarea Shortcut list.
 * @returns {Set<string>} URLs in the list.
 */
const listedUrls = (textarea) => new Set(textarea.value.split(/\r?\n/)
    .map((line) => (line.split('|')[1] || '').trim())
    .filter((url) => url !== ''));

/**
 * Links in the page footer that could become shortcuts.
 *
 * @returns {Array<{label: string, url: string}>} Footer links without duplicates.
 */
const footerLinks = () => {
    const seen = new Set();
    const links = [];
    document.querySelectorAll(FOOTER_SELECTOR).forEach((region) => {
        region.querySelectorAll('a[href]').forEach((anchor) => {
            const href = anchor.getAttribute('href') || '';
            if (href === '' || href.startsWith('#') || /^javascript:/i.test(href) || /^mailto:/i.test(href)) {
                return;
            }
            const url = anchor.href.split('#')[0];
            const label = (anchor.textContent || anchor.getAttribute('aria-label') || anchor.getAttribute('title') || '')
                .replace(/\s+/g, ' ').replace(/\|/g, ' ').trim();
            if (label === '' || ACTION_LINK.test(url) || url === window.location.href.split('#')[0] || seen.has(url)) {
                return;
            }
            seen.add(url);
            links.push({label, url});
        });
    });
    return links;
};

/**
 * Show the footer links that are not in the list yet, each with a button that adds it.
 *
 * @param {string} textareaid Id of the shortcut list textarea.
 */
export const init = async(textareaid) => {
    const textarea = document.getElementById(textareaid);
    const region = document.querySelector('[data-region="search-typesense-footer-links"][data-for="' + textareaid + '"]');
    if (!textarea || !region) {
        return;
    }
    const list = region.querySelector('[data-region="list"]');
    const status = region.querySelector('[data-region="status"]');
    const listed = listedUrls(textarea);
    const links = footerLinks().filter((link) => !listed.has(link.url));
    const addlabel = await getString('shortcuts_footer_add', 'search_typesense');

    links.forEach((link) => {
        const item = document.createElement('li');
        item.className = 'mb-1';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary btn-sm me-2 mr-2';
        button.textContent = addlabel;
        const text = document.createElement('span');
        text.textContent = link.label + ' — ' + link.url;
        button.addEventListener('click', async() => {
            const value = textarea.value.replace(/\s+$/, '');
            textarea.value = (value === '' ? '' : value + '\n') + link.label + ' | ' + link.url;
            textarea.dispatchEvent(new Event('change', {bubbles: true}));
            item.remove();
            status.textContent = await getString('shortcuts_footer_added', 'search_typesense', link.label);
        });
        item.append(button, text);
        list.append(item);
    });
    region.querySelector('[data-region="empty"]').hidden = links.length > 0;
    region.hidden = false;
};
