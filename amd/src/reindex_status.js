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
 * AMD module for polling background re-index status on the Fast Area Re-index page.
 *
 * @module     search_typesense/reindex_status
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @var {number} Poll interval while a re-index is running, in milliseconds. */
const ACTIVE_DELAY = 2500;

/** @var {number} First poll interval while nothing is running, in milliseconds. */
const IDLE_DELAY = 8000;

/** @var {number} Longest poll interval while nothing is running, in milliseconds. */
const MAX_IDLE_DELAY = 60000;

/**
 * Initialize background re-index status polling.
 *
 * Polling pauses while the page is hidden (another tab, minimised window) and slows down while no re-index is
 * running, so a page left open does not keep calling the server.
 *
 * @param {boolean} initialActive Whether a background re-index task is initially active.
 */
export const init = (initialActive) => {
    let timer = null;
    let idleDelay = IDLE_DELAY;

    const schedule = (delay) => {
        clearTimeout(timer);
        timer = null;
        if (!document.hidden) {
            timer = setTimeout(pollStatus, delay);
        }
    };

    const pollStatus = () => {
        timer = null;
        Ajax.call([{
            methodname: 'search_typesense_get_reindex_status',
            args: {},
        }])[0].then((data) => {
            if (!data) {
                return;
            }
            const banner = document.getElementById('ts-reindex-bg-banner');
            const titleEl = document.getElementById('ts-reindex-bg-title');
            const progEl = document.getElementById('ts-reindex-bg-progress');
            const barWrap = document.getElementById('ts-reindex-bg-bar-wrap');
            const barEl = document.getElementById('ts-reindex-bg-bar');
            if (banner) {
                if (data.active) {
                    banner.classList.remove('d-none');
                    banner.classList.add('d-flex');
                } else {
                    banner.classList.remove('d-flex');
                    banner.classList.add('d-none');
                }
            }
            if (titleEl && data.title) {
                titleEl.textContent = data.title;
            }
            if (progEl) {
                progEl.innerHTML = data.progresstext || '';
            }
            if (barWrap && barEl) {
                if (data.active && data.pct > 0) {
                    barWrap.classList.remove('d-none');
                    barEl.style.width = data.pct + '%';
                } else if (data.active && data.progresstext) {
                    barWrap.classList.remove('d-none');
                    barEl.style.width = '100%';
                } else {
                    barWrap.classList.add('d-none');
                }
            }
            if (Array.isArray(data.rows)) {
                data.rows.forEach((row) => {
                    const cEl = document.querySelector('[data-ts-count="' + row.areaid + '"]');
                    const nEl = document.querySelector('[data-ts-newest="' + row.areaid + '"]');
                    const lEl = document.querySelector('[data-ts-lastrun="' + row.areaid + '"]');
                    if (cEl && row.counthtml !== undefined) {
                        cEl.innerHTML = row.counthtml;
                    }
                    if (nEl && row.newestdoc !== undefined) {
                        nEl.textContent = row.newestdoc;
                    }
                    if (lEl && row.lastrunhtml !== undefined) {
                        lEl.innerHTML = row.lastrunhtml;
                    }
                });
            }
            if (data.active) {
                idleDelay = IDLE_DELAY;
                schedule(ACTIVE_DELAY);
            } else {
                schedule(idleDelay);
                idleDelay = Math.min(idleDelay * 2, MAX_IDLE_DELAY);
            }
            return;
        }).catch(() => {
            schedule(Math.max(idleDelay, IDLE_DELAY));
        });
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(timer);
            timer = null;
        } else if (timer === null) {
            // Refresh straight away when the page is shown again.
            idleDelay = IDLE_DELAY;
            schedule(0);
        }
    });

    schedule(initialActive ? ACTIVE_DELAY : IDLE_DELAY);
};
