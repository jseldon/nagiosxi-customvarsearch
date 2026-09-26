//
// Custom Variable Search - global search box integration
//
// XI's search box (Neptune Ctrl+K palette and the classic navbar autocomplete) calls
// suggest.php?type=multi. XI has no hook for adding results, so we wrap XMLHttpRequest:
// when a suggest request goes out we fetch the core results and our custom variable
// matches in parallel, then complete the original XHR with the merged JSON array.
// If our endpoint fails for any reason, the core results are returned unchanged.
//
(function () {
    'use strict';

    var script = document.currentScript;
    var endpoint = script && script.getAttribute('data-cvs-endpoint');
    if (!endpoint) { return; }

    // Loaded inside XI's content frame: inject ourselves into the top window so the hook
    // runs in that window's own JS realm (survives the frame navigating away)
    if (window.top !== window) {
        try {
            var top = window.top;
            if (!top.__cvsSearchHook && !top.document.getElementById('cvs-search-hook')) {
                var s = top.document.createElement('script');
                s.id = 'cvs-search-hook';
                s.src = script.src;
                s.setAttribute('data-cvs-endpoint', endpoint);
                top.document.head.appendChild(s);
            }
        } catch (e) { /* cross-origin top window - nothing to hook */ }
        return;
    }

    if (window.__cvsSearchHook || !window.XMLHttpRequest || !window.fetch) { return; }
    window.__cvsSearchHook = true;

    var XHR = window.XMLHttpRequest.prototype;
    var origOpen = XHR.open;
    var origSend = XHR.send;
    var origAbort = XHR.abort;

    function suggestTerm(url) {
        try {
            var u = new URL(url, window.location.href);
            if (!/\/suggest\.php$/.test(u.pathname) || u.searchParams.get('type') !== 'multi') {
                return null;
            }
            return u.searchParams.get('term') || '';
        } catch (e) {
            return null;
        }
    }

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (d) { return Array.isArray(d) ? d : []; });
    }

    // Complete the XHR ourselves: shadow the read-only response props, then fire events
    function complete(xhr, body) {
        var props = {
            readyState: 4, status: 200, statusText: 'OK',
            responseText: body, response: xhr.responseType === 'json' ? JSON.parse(body) : body,
            responseURL: xhr.__cvsUrl
        };
        Object.keys(props).forEach(function (k) {
            Object.defineProperty(xhr, k, { configurable: true, get: function () { return props[k]; } });
        });
        xhr.getAllResponseHeaders = function () { return 'content-type: application/json\r\n'; };
        xhr.getResponseHeader = function (h) { return /^content-type$/i.test(h) ? 'application/json' : null; };

        ['readystatechange', 'load', 'loadend'].forEach(function (type) {
            var handler = xhr['on' + type];
            var ev = new ProgressEvent(type);
            if (typeof handler === 'function') { handler.call(xhr, ev); }
            xhr.dispatchEvent(ev);
        });
    }

    XHR.open = function (method, url) {
        this.__cvsUrl = String(url);
        this.__cvsTerm = (String(method).toUpperCase() === 'GET') ? suggestTerm(url) : null;
        return origOpen.apply(this, arguments);
    };

    XHR.abort = function () {
        this.__cvsAborted = true;
        return origAbort.apply(this, arguments);
    };

    XHR.send = function () {
        var xhr = this;
        var term = xhr.__cvsTerm;
        if (term === null || term === undefined) {
            return origSend.apply(this, arguments);
        }

        var ours = getJSON(endpoint + '?format=suggest&term=' + encodeURIComponent(term))
            .catch(function () { return []; });
        var core = getJSON(xhr.__cvsUrl);

        Promise.all([core, ours]).then(function (res) {
            if (!xhr.__cvsAborted) {
                complete(xhr, JSON.stringify(res[0].concat(res[1])));
            }
        }, function () {
            // Core fetch failed - fall back to the untouched original request
            if (!xhr.__cvsAborted) { origSend.call(xhr); }
        });
    };
})();
