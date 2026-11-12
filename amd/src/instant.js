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
 * AMD module for Typesense Pro live navbar and global search InstantSearch typeahead.
 *
 * @module     local_typesensepro/instant
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

let initialized = false;

/**
 * Escape HTML special characters in plain text.
 *
 * @param {string} str Raw string.
 * @returns {string} Escaped HTML string.
 */
const escapeHtml = (str) => String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

/**
 * Highlight matching query tokens inside escaped HTML text.
 *
 * @param {string} text Source text.
 * @param {string} query Active query string.
 * @returns {string} HTML string with <mark> tags.
 */
const highlightText = (text, query) => {
    const safe = escapeHtml(text);
    const tokens = String(query || '').trim().split(/\s+/).filter((t) => t.length >= 2);
    if (!tokens.length) {
        return safe;
    }
    const pattern = tokens.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
    try {
        const regex = new RegExp('(' + pattern + ')', 'gi');
        return safe.replace(regex, '<mark>$1</mark>');
    } catch (e) {
        return safe;
    }
};

/**
 * Synchronize the hidden scope input on a search form.
 *
 * @param {HTMLInputElement|null} input Search input element.
 * @param {string} scope Active scope key.
 */
const syncFormScope = (input, scope) => {
    if (!input || !input.form) {
        return;
    }
    let scopeInput = input.form.querySelector('input[name="scope"]');
    if (!scopeInput) {
        scopeInput = document.createElement('input');
        scopeInput.type = 'hidden';
        scopeInput.name = 'scope';
        input.form.appendChild(scopeInput);
    }
    scopeInput.value = scope;
};

/**
 * Initialize the Typesense Pro InstantSearch widget.
 *
 * @param {Object} cfg Configuration payload from instant_widget::render().
 */
export const init = (cfg) => {
    if (initialized || !cfg || !cfg.queryUrl) {
        return;
    }
    initialized = true;

    let activeScope = cfg.defaultScope || 'all';
    let activeInput = null;
    let debounceTimer = null;
    let requestSeq = 0;
    let currentHits = [];
    let highlightedIndex = -1;
    let lastData = null;

    const popover = document.createElement('div');
    popover.className = 'local-typesensepro-instant-popover';
    popover.setAttribute('role', 'listbox');
    popover.setAttribute('aria-label', 'Typesense Pro InstantSearch');
    document.body.appendChild(popover);

    const syncThemeColors = () => {
        try {
            const probe = document.createElement('button');
            probe.className = 'btn btn-primary';
            probe.style.position = 'absolute';
            probe.style.visibility = 'hidden';
            probe.style.pointerEvents = 'none';
            document.body.appendChild(probe);
            const bg = window.getComputedStyle(probe).backgroundColor;
            document.body.removeChild(probe);
            if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
                popover.style.setProperty('--ts-primary', bg);
                const m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                if (m) {
                    popover.style.setProperty(
                        '--ts-primary-soft',
                        'rgba(' + m[1] + ', ' + m[2] + ', ' + m[3] + ', 0.08)'
                    );
                }
            }
        } catch (e) {
            // Keep CSS variable fallback if computed style probe is unavailable.
        }
    };
    syncThemeColors();

    const closePopover = () => {
        popover.classList.remove('is-open');
        highlightedIndex = -1;
    };

    const positionPopover = () => {
        if (!activeInput || !popover.classList.contains('is-open')) {
            return;
        }
        const rect = activeInput.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            closePopover();
            return;
        }
        let width = Math.max(rect.width, 640);
        width = Math.min(width, window.innerWidth - 24);
        popover.style.width = width + 'px';

        let left = rect.left;
        if (left + width > window.innerWidth - 12) {
            left = Math.max(12, window.innerWidth - width - 12);
        }
        const top = rect.bottom + 6;
        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
    };

    const renderPopover = (data, loading) => {
        lastData = data;
        currentHits = (data && data.hits) ? data.hits : [];
        highlightedIndex = -1;
        const q = activeInput ? activeInput.value.trim() : '';

        if (!loading && data && data.activeScope && data.activeScope !== activeScope) {
            activeScope = data.activeScope;
            syncFormScope(activeInput, activeScope);
        }

        const scopeButtons = (cfg.scopes || []).map((sc) => {
            const btnClass = sc.key === activeScope ? 'btn-primary' : 'btn-outline-secondary';
            return '<button type="button" class="btn btn-sm local-typesensepro-instant-scopepill ' + btnClass +
                '" data-scope="' + escapeHtml(sc.key) + '">' + escapeHtml(sc.label) + '</button>';
        }).join('');

        let latencyHtml = '';
        if (loading && cfg.showLatency) {
            latencyHtml = '<span>' + escapeHtml(cfg.strings.searching) + '</span>';
        } else if (!loading && cfg.showLatency && data && typeof data.elapsedMs === 'number') {
            latencyHtml = '<span class="local-typesensepro-instant-latency">&#9889; ' + data.elapsedMs + ' ms</span>';
        }

        const metaBlock = latencyHtml
            ? '<div class="local-typesensepro-instant-meta">' + latencyHtml + '</div>'
            : '';

        let html = '<div class="local-typesensepro-instant-header">' +
            '<div class="local-typesensepro-instant-scopes">' + scopeButtons + '</div>' +
            metaBlock +
            '</div>';

        if (currentHits.length > 0) {
            html += '<div class="local-typesensepro-instant-list">';
            currentHits.forEach((hit, idx) => {
                const courseHtml = hit.course
                    ? '<div class="local-typesensepro-instant-course">' + highlightText(hit.course, q) + '</div>'
                    : '';
                const snippetHtml = hit.snippet
                    ? '<div class="local-typesensepro-instant-snippet">' + highlightText(hit.snippet, q) + '</div>'
                    : '';
                let userMetaHtml = '';
                if (hit.usermeta && hit.usermeta.length) {
                    userMetaHtml = '<div class="local-typesensepro-instant-usermeta">' +
                        hit.usermeta.map((m) => (
                            '<span class="local-typesensepro-instant-userpill"><strong>' +
                            escapeHtml(m.label) + ':</strong> ' + highlightText(m.value, q) + '</span>'
                        )).join('') + '</div>';
                }
                html += '<a href="' + escapeHtml(hit.url) +
                    '" class="local-typesensepro-instant-item" data-idx="' + idx + '">' +
                    '<div class="local-typesensepro-instant-toprow">' +
                    '<div class="local-typesensepro-instant-title">' + highlightText(hit.title, q) + '</div>' +
                    '<div class="local-typesensepro-instant-badges">' +
                    '<span class="local-typesensepro-instant-area">' + escapeHtml(hit.area) + '</span>' +
                    '</div></div>' +
                    courseHtml + snippetHtml + userMetaHtml +
                    '</a>';
            });
            html += '</div>';
        } else if (!loading) {
            let tryHistBtn = '';
            const hasHist = (cfg.scopes || []).some((s) => s.key === 'historical');
            if (activeScope === 'current' && hasHist) {
                tryHistBtn = '<div style="margin-top:8px;">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary ' +
                    'local-typesensepro-instant-scopepill" data-scope="historical">' +
                    escapeHtml(cfg.strings.tryhistorical) + '</button></div>';
            }
            html += '<div class="local-typesensepro-instant-empty">' +
                escapeHtml(cfg.strings.noresults) + tryHistBtn + '</div>';
        }

        const searchUrl = (data && data.searchUrl)
            ? data.searchUrl
            : (cfg.queryUrl + '?q=' + encodeURIComponent(q) + '&scope=' + encodeURIComponent(activeScope));
        const aiUrl = (data && data.aiUrl)
            ? data.aiUrl
            : (searchUrl + '&aiask=1');

        const aiBtnHtml = cfg.ragEnabled
            ? '<a href="' + escapeHtml(aiUrl) + '" class="btn btn-sm btn-outline-primary ' +
                'local-typesensepro-instant-aibtn">&#10024; ' +
                escapeHtml(cfg.strings.aioverview) + '</a>'
            : '<span class="text-muted" style="font-size:11px;">' + escapeHtml(cfg.strings.kbdhint) + '</span>';

        html += '<div class="local-typesensepro-instant-footer">' +
            '<a href="' + escapeHtml(searchUrl) + '" class="local-typesensepro-instant-viewall">' +
            escapeHtml(cfg.strings.viewall.replace('{$a}', q)) + ' &crarr;</a>' +
            aiBtnHtml +
            '</div>';

        popover.innerHTML = html;
        popover.classList.add('is-open');
        positionPopover();
    };

    const updateHighlight = () => {
        const items = popover.querySelectorAll('.local-typesensepro-instant-item');
        items.forEach((el, idx) => {
            if (idx === highlightedIndex) {
                el.classList.add('is-Highlighted');
                if (el.scrollIntoView) {
                    el.scrollIntoView({block: 'nearest'});
                }
            } else {
                el.classList.remove('is-Highlighted');
            }
        });
    };

    const runInstantQuery = (query) => {
        const seq = ++requestSeq;
        renderPopover(lastData, true);

        Ajax.call([{
            methodname: 'local_typesensepro_instant_search',
            args: {
                q: query,
                scope: activeScope,
            },
        }])[0].then((data) => {
            if (seq !== requestSeq) {
                return;
            }
            if (!data || data.disabled) {
                closePopover();
                return;
            }
            renderPopover(data, false);
            return;
        }).catch(() => {
            // Ignore transient network errors during live typing.
        });
    };

    popover.addEventListener('mousedown', (e) => {
        const pill = e.target.closest('[data-scope]');
        if (pill) {
            e.preventDefault();
            activeScope = pill.getAttribute('data-scope') || 'current';
            syncFormScope(activeInput, activeScope);
            if (activeInput && activeInput.value.trim().length >= 2) {
                runInstantQuery(activeInput.value.trim());
            }
        }
    });

    const bindSearchInput = (input) => {
        if (!input || input.dataset.typesenseInstantBound === '1') {
            return;
        }
        input.dataset.typesenseInstantBound = '1';
        input.setAttribute('autocomplete', 'off');
        syncFormScope(input, activeScope);

        input.addEventListener('input', () => {
            activeInput = input;
            const val = input.value.trim();
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            if (val.length < 2) {
                closePopover();
                return;
            }
            debounceTimer = setTimeout(() => {
                runInstantQuery(val);
            }, 150);
        });

        input.addEventListener('focus', () => {
            activeInput = input;
            const val = input.value.trim();
            if (val.length >= 2) {
                runInstantQuery(val);
            }
        });

        input.addEventListener('keydown', (e) => {
            if (!popover.classList.contains('is-open')) {
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (currentHits.length > 0) {
                    highlightedIndex = (highlightedIndex + 1) % currentHits.length;
                    updateHighlight();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (currentHits.length > 0) {
                    highlightedIndex = (highlightedIndex - 1 + currentHits.length) % currentHits.length;
                    updateHighlight();
                }
            } else if (e.key === 'Enter' && highlightedIndex >= 0 && currentHits[highlightedIndex]) {
                e.preventDefault();
                window.location.href = currentHits[highlightedIndex].url;
            } else if (e.key === 'Escape') {
                closePopover();
            }
        });
    };

    const scanInputs = () => {
        const selectors = [
            '.simplesearchform input[name="q"]',
            '#searchform-navbar input[name="q"]',
            '[data-region="search-input"] input[name="q"]',
            '.search-input-wrapper input[name="q"]',
            'form[action*="/search/"] input[name="q"]',
            '#fitem_id_q input[name="q"]',
        ];
        const nodes = document.querySelectorAll(selectors.join(','));
        nodes.forEach(bindSearchInput);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scanInputs);
    } else {
        scanInputs();
    }

    document.addEventListener('focusin', (e) => {
        if (e.target && e.target.tagName === 'INPUT' && e.target.name === 'q') {
            bindSearchInput(e.target);
        }
    });

    document.addEventListener('mousedown', (e) => {
        if (!popover.contains(e.target) && e.target !== activeInput) {
            closePopover();
        }
    });

    window.addEventListener('resize', positionPopover, {passive: true});
    window.addEventListener('scroll', positionPopover, {passive: true});
};
