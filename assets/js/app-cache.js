(function () {
    if (!window.fetch || window.AppRamCache) return;

    const MAX_BYTES = 200 * 1024 * 1024;
    const encoder = window.TextEncoder ? new TextEncoder() : null;
    const nativeFetch = window.fetch.bind(window);
    const entries = new Map();
    let totalBytes = 0;

    function byteLength(text) {
        if (encoder) {
            return encoder.encode(text).length;
        }
        return String(text || '').length * 2;
    }

    function normalizeUrl(input) {
        const raw = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        if (!raw) return null;
        try {
            const url = new URL(raw, window.location.href);
            url.hash = '';
            return url;
        } catch (error) {
            return null;
        }
    }

    function requestMethod(input, init) {
        return String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    }

    function isCacheableRequest(input, init) {
        const url = normalizeUrl(input);
        if (!url || url.origin !== window.location.origin) return false;
        if (requestMethod(input, init) !== 'GET') return false;
        if (!isCacheableApiPath(url.pathname)) return false;
        if (init && init.cache === 'no-store') return false;
        return true;
    }

    function isCacheableApiPath(pathname) {
        return [
            '/api/events.php',
            '/api/games.php',
            '/api/classes.php',
            '/api/class_standings.php'
        ].some(path => pathname.endsWith(path));
    }

    function cacheKey(input) {
        const url = normalizeUrl(input);
        return url ? url.href : '';
    }

    function ttlFor(url, payload) {
        const path = url.pathname;

        if (path.endsWith('/api/games.php')) return 15000;
        if (path.endsWith('/api/events.php')) return 30000;
        if (path.endsWith('/api/classes.php')) return 120000;
        return 30000;
    }

    function makeResponse(entry) {
        return new Response(entry.body, {
            status: entry.status,
            statusText: entry.statusText,
            headers: entry.headers
        });
    }

    function evictExpired(now) {
        for (const [key, entry] of entries) {
            if (entry.expiresAt <= now) {
                entries.delete(key);
                totalBytes -= entry.bytes;
            }
        }
    }

    function evictToLimit() {
        while (totalBytes > MAX_BYTES && entries.size) {
            let oldestKey = null;
            let oldestAccess = Infinity;
            for (const [key, entry] of entries) {
                if (entry.lastAccess < oldestAccess) {
                    oldestAccess = entry.lastAccess;
                    oldestKey = key;
                }
            }
            if (oldestKey === null) return;
            const entry = entries.get(oldestKey);
            entries.delete(oldestKey);
            totalBytes -= entry.bytes;
        }
    }

    function clear(reason) {
        entries.clear();
        totalBytes = 0;
        window.dispatchEvent(new CustomEvent('app-cache-cleared', {detail: {reason: reason || 'manual'}}));
    }

    function shouldInvalidate(input, init) {
        const url = normalizeUrl(input);
        if (!url || url.origin !== window.location.origin) return false;
        if (!url.pathname.includes('/api/')) return false;
        return requestMethod(input, init) !== 'GET';
    }

    async function cachedFetch(input, init) {
        if (shouldInvalidate(input, init)) {
            clear('mutation');
            return nativeFetch(input, init);
        }

        if (!isCacheableRequest(input, init)) {
            return nativeFetch(input, init);
        }

        const key = cacheKey(input);
        const now = Date.now();
        evictExpired(now);

        const cached = entries.get(key);
        if (cached && cached.expiresAt > now) {
            cached.lastAccess = now;
            window.dispatchEvent(new CustomEvent('app-cache-hit', {detail: {url: key}}));
            return makeResponse(cached);
        }

        const response = await nativeFetch(input, init);
        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !contentType.includes('application/json')) {
            return response;
        }

        const clone = response.clone();
        const body = await clone.text();
        let payload = null;
        try {
            payload = JSON.parse(body);
        } catch (error) {
            return response;
        }

        const url = normalizeUrl(input);
        const headers = {};
        response.headers.forEach((value, name) => {
            headers[name] = value;
        });

        const bytes = byteLength(body) + byteLength(key) + 512;
        if (bytes <= MAX_BYTES) {
            if (entries.has(key)) {
                totalBytes -= entries.get(key).bytes;
            }
            entries.set(key, {
                body,
                bytes,
                status: response.status,
                statusText: response.statusText,
                headers,
                expiresAt: now + ttlFor(url, payload),
                lastAccess: now
            });
            totalBytes += bytes;
            evictToLimit();
        }

        return response;
    }

    window.AppRamCache = {
        clear,
        stats() {
            evictExpired(Date.now());
            return {
                entries: entries.size,
                bytes: Math.max(0, totalBytes),
                maxBytes: MAX_BYTES,
                percentUsed: Math.round((Math.max(0, totalBytes) / MAX_BYTES) * 10000) / 100
            };
        }
    };

    window.fetch = cachedFetch;
})();
