(function () {
	'use strict';

	var cfg = window.stormsaleReferral || {};
	var cookieName =
		typeof cfg.cookieName === 'string' && cfg.cookieName !== ''
			? cfg.cookieName
			: 'stormsale_ref';
	var ttlDays = typeof cfg.ttlDays === 'number' && cfg.ttlDays > 0 ? cfg.ttlDays : 30;
	var ttlSeconds = ttlDays * 86400;
	var params =
		Object.prototype.toString.call(cfg.params) === '[object Array]' && cfg.params.length
			? cfg.params
			: ['ss_ref', 'ref'];
	var secure = typeof cfg.secure === 'boolean' ? cfg.secure : window.location.protocol === 'https:';
	var codePattern = /^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/;

	function readCookie(name) {
		var target = name + '=';
		var parts = document.cookie ? document.cookie.split(';') : [];
		var i;
		var chunk;

		for (i = 0; i < parts.length; i++) {
			chunk = parts[i];
			while (chunk.charAt(0) === ' ') {
				chunk = chunk.substring(1);
			}
			if (chunk.indexOf(target) === 0) {
				try {
					return decodeURIComponent(chunk.substring(target.length));
				} catch (err) {
					return null;
				}
			}
		}

		return null;
	}

	function writeCookie(name, value) {
		var cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + ttlSeconds + '; SameSite=Lax';
		if (secure) {
			cookie += '; Secure';
		}
		document.cookie = cookie;
	}

	function codeFromQuery() {
		var search = window.location.search || '';
		var pairs;
		var i;
		var pair;
		var eq;
		var key;
		var raw;
		var decoded;

		if (search.charAt(0) === '?') {
			search = search.substring(1);
		}
		if (search === '') {
			return null;
		}

		pairs = search.split('&');

		for (i = 0; i < pairs.length; i++) {
			pair = pairs[i];
			if (pair === '') {
				continue;
			}
			eq = pair.indexOf('=');
			key = eq === -1 ? pair : pair.substring(0, eq);
			if (params.indexOf(key) === -1) {
				continue;
			}
			raw = eq === -1 ? '' : pair.substring(eq + 1);
			try {
				decoded = decodeURIComponent(raw.replace(/\+/g, ' '));
			} catch (err) {
				decoded = '';
			}
			if (codePattern.test(decoded)) {
				return decoded;
			}
		}

		return null;
	}

	var landed = codeFromQuery();
	var existing = readCookie(cookieName);
	var code = landed;

	if (!code && existing && codePattern.test(existing)) {
		code = existing;
	}

	if (code) {
		writeCookie(cookieName, code);
	}

	cfg.code = code;
	window.stormsaleReferral = cfg;
})();
