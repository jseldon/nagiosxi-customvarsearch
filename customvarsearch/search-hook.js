/**
 * Custom Variable Search - browser integration for Nagios XI.
 *
 * Included in the <head> of XI pages by customvarsearch_component_head() (PHP). It does
 * two things:
 *
 * 1. Global search box results
 *    XI's search box (the Neptune "Search Nagios XI"/Ctrl+K palette and the classic navbar
 *    autocomplete) asks suggest.php?type=multi for results. XI offers components no hook
 *    to add results, so this script wraps XMLHttpRequest in the top window: when such a
 *    request is sent it fetches the core results and this component's
 *    index.php?format=suggest results in parallel, then completes the original request
 *    with the merged JSON array. XI then shows a "Custom Variables" result group.
 *    - Only same-origin, asynchronous GETs of suggest.php?type=multi are touched.
 *    - If the component endpoint fails, the core results are returned unchanged.
 *    - If core suggest.php fails, the original request is sent untouched, so XI sees
 *      exactly what it would have without this script.
 *
 * 2. Opening the Custom Variables tab
 *    Links from this component to a host's detail page carry ?cvstab=1. On such a page
 *    this script clicks the "Custom Variables" tab once the page has loaded.
 *
 * Why it re-injects itself: the Neptune shell (the top window, which owns the search
 * box) is a React app that doesn't run XI's page-head callback. The script is therefore
 * loaded by the XI pages shown in the content frame, and from there adds a copy of itself
 * to the top window's document. The copy runs in the top window's own JavaScript realm,
 * so the hook keeps working after the frame navigates away. With Neptune the hook becomes
 * active once the first classic XI page (Host Status, CCM, ...) has loaded in the session.
 *
 * Settings come from data-* attributes on the <script> tag:
 *   data-cvs-endpoint   URL of the component's index.php (required)
 *   data-cvs-tab        id of the Custom Variables tab panel on host detail pages
 *   data-cvs-tab-param  query parameter that asks for that tab ("cvstab")
 *
 * No dependencies; plain ES5 plus fetch/Promise/URL, which every browser XI supports has.
 *
 * Copyright (c) 2026 James Seldon - MIT License (see LICENSE)
 */
(function () {
    'use strict';

    var SETTINGS = ['data-cvs-endpoint', 'data-cvs-tab', 'data-cvs-tab-param'];

    var script = document.currentScript;
    var endpoint = script && script.getAttribute('data-cvs-endpoint');
    if (!endpoint) { return; }

    openRequestedTab(script.getAttribute('data-cvs-tab'), script.getAttribute('data-cvs-tab-param'));

    if (window.top !== window) {
        injectIntoTopWindow();
        return;
    }
    installSuggestHook();


    /**
     * If this page's URL has <tabParam>=1, click the tab whose panel id is tabId once the
     * page has loaded. Waiting for 'load' means XI's own $(document).ready() has already
     * initialised its jQuery UI tabs.
     *
     * @param {?string} tabId    Panel id, e.g. "tab-custom-customvariabletab"
     * @param {?string} tabParam Query parameter name, e.g. "cvstab"
     */
    function openRequestedTab(tabId, tabParam) {
        if (!tabId || !tabParam || new URLSearchParams(window.location.search).get(tabParam) !== '1') {
            return;
        }
        window.addEventListener('load', function () {
            var link = document.querySelector('#tabs a[href="#' + CSS.escape(tabId) + '"]');
            if (link) { link.click(); }
        });
    }

    /**
     * Running inside XI's content frame: add a copy of this script (same src and
     * settings) to the top window, unless it is already there or already hooked.
     */
    function injectIntoTopWindow() {
        try {
            var top = window.top;
            if (top.__cvsSearchHook || top.document.getElementById('cvs-search-hook')) {
                return;
            }
            var copy = top.document.createElement('script');
            copy.id = 'cvs-search-hook';
            copy.src = script.src;
            SETTINGS.forEach(function (name) {
                var value = script.getAttribute(name);
                if (value !== null) { copy.setAttribute(name, value); }
            });
            top.document.head.appendChild(copy);
        } catch (e) {
            // Top window is on another origin (XI embedded elsewhere) - nothing to hook
        }
    }

    /**
     * Wrap XMLHttpRequest.prototype.open/send/abort so suggest.php?type=multi requests
     * also return this component's results. Installed once per window.
     */
    function installSuggestHook() {
        if (window.__cvsSearchHook || !window.XMLHttpRequest || !window.fetch) {
            return;
        }
        window.__cvsSearchHook = true;

        var XHR = window.XMLHttpRequest.prototype;
        var origOpen = XHR.open;
        var origSend = XHR.send;
        var origAbort = XHR.abort;

        // Properties complete() overrides on an XHR instance
        var SHADOWED = ['readyState', 'status', 'statusText', 'responseText', 'response', 'responseURL',
                        'getAllResponseHeaders', 'getResponseHeader'];

        /**
         * The search term if url is a same-origin suggest.php?type=multi request.
         *
         * @param {string} url Request URL (absolute or relative)
         * @returns {?string} The term ('' if absent), or null for any other request
         */
        function suggestTerm(url) {
            try {
                var u = new URL(url, window.location.href);
                if (u.origin !== window.location.origin ||
                    !/\/suggest\.php$/.test(u.pathname) || u.searchParams.get('type') !== 'multi') {
                    return null;
                }
                return u.searchParams.get('term') || '';
            } catch (e) {
                return null;
            }
        }

        /**
         * Fetch a JSON array with the session cookie.
         *
         * @param {string} url
         * @returns {Promise<Array>} Rejects on HTTP errors, non-JSON bodies (e.g. a login
         *                           page) or JSON that isn't an array
         */
        function getJSON(url) {
            return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (d) {
                    if (!Array.isArray(d)) { throw new Error('not an array'); }
                    return d;
                });
        }

        /**
         * Finish an XHR that was never sent, as a successful JSON response.
         * The response properties are read-only on the prototype, so they are shadowed
         * with instance getters; then the completion events are dispatched (which also
         * runs the on<event> handler properties - they must not be called directly too).
         *
         * @param {XMLHttpRequest} xhr
         * @param {string} body JSON text
         */
        function complete(xhr, body) {
            var props = {
                readyState: 4, status: 200, statusText: 'OK', responseText: body,
                response: xhr.responseType === 'json' ? JSON.parse(body) : body,
                responseURL: xhr.__cvsUrl
            };
            Object.keys(props).forEach(function (k) {
                Object.defineProperty(xhr, k, { configurable: true, get: function () { return props[k]; } });
            });
            xhr.getAllResponseHeaders = function () { return 'content-type: application/json\r\n'; };
            xhr.getResponseHeader = function (h) { return /^content-type$/i.test(h) ? 'application/json' : null; };

            ['readystatechange', 'load', 'loadend'].forEach(function (type) {
                xhr.dispatchEvent(new ProgressEvent(type));
            });
        }

        /** Remember what is being requested; clear overrides left by a previous use. */
        XHR.open = function (method, url, async) {
            var xhr = this;
            SHADOWED.forEach(function (k) {
                if (Object.prototype.hasOwnProperty.call(xhr, k)) { delete xhr[k]; }
            });
            xhr.__cvsUrl = String(url);
            xhr.__cvsAborted = false;
            // Only asynchronous GETs can be completed later; synchronous ones are left alone
            xhr.__cvsTerm = (String(method).toUpperCase() === 'GET' && async !== false) ? suggestTerm(url) : null;
            return origOpen.apply(xhr, arguments);
        };

        /** An aborted request must not complete later. */
        XHR.abort = function () {
            this.__cvsAborted = true;
            return origAbort.apply(this, arguments);
        };

        /** Suggest requests: fetch core + component results and merge; others: unchanged. */
        XHR.send = function () {
            var xhr = this;
            var term = xhr.__cvsTerm;
            if (term === null || term === undefined) {
                return origSend.apply(xhr, arguments);
            }

            var ours = getJSON(endpoint + '?format=suggest&term=' + encodeURIComponent(term))
                .catch(function () { return []; });
            var core = getJSON(xhr.__cvsUrl);

            Promise.all([core, ours]).then(function (res) {
                if (!xhr.__cvsAborted) {
                    complete(xhr, JSON.stringify(res[0].concat(res[1])));
                }
            }, function () {
                // Core suggest.php failed - send the original request so XI sees the real response
                if (!xhr.__cvsAborted) { origSend.call(xhr); }
            });
        };
    }
})();
