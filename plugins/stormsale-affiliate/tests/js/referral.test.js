'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SCRIPT = fs.readFileSync(
	path.join(__dirname, '..', '..', 'assets', 'js', 'stormsale-referral.js'),
	'utf8'
);

function createSandbox(options = {}) {
	const writes = [];
	const jar = new Map();

	if (options.cookie) {
		for (const part of options.cookie.split(';')) {
			const trimmed = part.trim();
			if (!trimmed) continue;
			const eq = trimmed.indexOf('=');
			jar.set(trimmed.slice(0, eq), trimmed.slice(eq + 1));
		}
	}

	const document = {};
	Object.defineProperty(document, 'cookie', {
		get() {
			return Array.from(jar.entries())
				.map(([name, value]) => `${name}=${value}`)
				.join('; ');
		},
		set(raw) {
			const [pair, ...attrs] = raw.split(';').map((s) => s.trim());
			const eq = pair.indexOf('=');
			const name = pair.slice(0, eq);
			const value = pair.slice(eq + 1);
			const parsed = {};

			for (const attr of attrs) {
				const attrEq = attr.indexOf('=');
				if (attrEq === -1) {
					parsed[attr.toLowerCase()] = true;
				} else {
					parsed[attr.slice(0, attrEq).toLowerCase()] = attr.slice(attrEq + 1);
				}
			}

			writes.push({ name, value, attrs: parsed });

			if (parsed['max-age'] === '0') {
				jar.delete(name);
			} else {
				jar.set(name, value);
			}
		}
	});

	const sandbox = {
		document,
		window: {
			location: {
				search: options.search || '',
				protocol: options.protocol || 'https:'
			},
			stormsaleReferral: options.config || undefined
		}
	};

	sandbox.window.document = document;

	return { sandbox, writes, jar, window: sandbox.window };
}

function run(options) {
	const { sandbox, writes, jar, window } = createSandbox(options);
	vm.runInNewContext(SCRIPT, sandbox, { filename: 'stormsale-referral.js' });
	return { writes, jar, window };
}

test('landing with ss_ref stores the referral cookie for 30 days', () => {
	const { writes, window } = run({ search: '?ss_ref=aff9' });

	assert.strictEqual(writes.length, 1);
	assert.strictEqual(writes[0].name, 'stormsale_ref');
	assert.strictEqual(writes[0].value, 'aff9');
	assert.strictEqual(writes[0].attrs['max-age'], String(30 * 86400));
	assert.strictEqual(writes[0].attrs.path, '/');
	assert.strictEqual(writes[0].attrs['samesite'], 'Lax');
	assert.strictEqual(writes[0].attrs.secure, true, 'secure is set automatically on https stores');
	assert.strictEqual(window.stormsaleReferral.code, 'aff9');
});

test('the ref alias is accepted and ss_ref wins when both are present', () => {
	assert.strictEqual(run({ search: '?ref=aff7' }).writes[0].value, 'aff7');
	assert.strictEqual(run({ search: '?ss_ref=aff9&ref=aff7' }).writes[0].value, 'aff9');
});

test('malformed and hostile referral values never reach the cookie', () => {
	assert.strictEqual(run({ search: '?ss_ref=' }).writes.length, 0);
	assert.strictEqual(run({ search: '?ss_ref=%3Cscript%3E' }).writes.length, 0);
	assert.strictEqual(run({ search: '?ss_ref=bad%20code' }).writes.length, 0);
	assert.strictEqual(run({ search: '?utm_source=newsletter' }).writes.length, 0);
	assert.strictEqual(run({ search: '?ss_ref=%E0%A4%A' }).writes.length, 0, 'broken percent encoding is ignored');
	assert.strictEqual(run({ search: '?ss_ref=' }).window.stormsaleReferral.code, null);
});

test('an existing cookie is refreshed on every page view (sliding window)', () => {
	const { writes } = run({ search: '', cookie: 'stormsale_ref=aff9' });

	assert.strictEqual(writes.length, 1);
	assert.strictEqual(writes[0].value, 'aff9');
	assert.strictEqual(writes[0].attrs['max-age'], String(30 * 86400));
});

test('a corrupted existing cookie is dropped without a write', () => {
	const { writes, window } = run({ search: '', cookie: 'stormsale_ref=bad code!' });

	assert.strictEqual(writes.length, 0);
	assert.strictEqual(window.stormsaleReferral.code, null);
});

test('secure is set for https stores and custom config is honoured', () => {
	const secure = run({ search: '?ss_ref=aff9', protocol: 'https:', config: { secure: true } });
	assert.strictEqual(secure.writes[0].attrs.secure, true);

	const custom = run({
		search: '?partner=aff9',
		protocol: 'http:',
		config: { cookieName: 'partner_ref', params: ['partner'], ttlDays: 7 }
	});

	assert.strictEqual(custom.writes.length, 1);
	assert.strictEqual(custom.writes[0].name, 'partner_ref');
	assert.strictEqual(custom.writes[0].value, 'aff9');
	assert.strictEqual(custom.writes[0].attrs['max-age'], String(7 * 86400));
});
