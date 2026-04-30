(function () {
	'use strict';

	var config = window.PWCPaymentPage || {};
	var pollTimer = null;
	var expiryTimer = null;
	var redirected = false;
	var currentData = config.initialStatus || {};

	var QR_BLOCKS_M = {
		1: { ec: 10, data: [16] },
		2: { ec: 16, data: [28] },
		3: { ec: 26, data: [44] },
		4: { ec: 18, data: [32, 32] },
		5: { ec: 24, data: [43, 43] },
		6: { ec: 16, data: [27, 27, 27, 27] },
		7: { ec: 18, data: [31, 31, 31, 31] },
		8: { ec: 22, data: [38, 38, 39, 39] },
		9: { ec: 22, data: [36, 36, 36, 37, 37] },
		10: { ec: 26, data: [43, 43, 43, 43, 44] }
	};

	var QR_ALIGN = {
		1: [],
		2: [6, 18],
		3: [6, 22],
		4: [6, 26],
		5: [6, 30],
		6: [6, 34],
		7: [6, 22, 38],
		8: [6, 24, 42],
		9: [6, 26, 46],
		10: [6, 28, 50]
	};

	var gfExp = [];
	var gfLog = [];
	(function initGalois() {
		var x = 1;
		for (var i = 0; i < 255; i += 1) {
			gfExp[i] = x;
			gfLog[x] = i;
			x <<= 1;
			if (x & 0x100) {
				x ^= 0x11d;
			}
		}
		for (var j = 255; j < 512; j += 1) {
			gfExp[j] = gfExp[j - 255];
		}
	}());

	function gfMul(a, b) {
		if (a === 0 || b === 0) {
			return 0;
		}
		return gfExp[gfLog[a] + gfLog[b]];
	}

	function rsGenerator(degree) {
		var poly = [1];
		for (var i = 0; i < degree; i += 1) {
			var next = new Array(poly.length + 1).fill(0);
			for (var j = 0; j < poly.length; j += 1) {
				next[j] ^= poly[j];
				next[j + 1] ^= gfMul(poly[j], gfExp[i]);
			}
			poly = next;
		}
		return poly;
	}

	function rsRemainder(data, degree) {
		var gen = rsGenerator(degree);
		var result = data.slice();
		for (var z = 0; z < degree; z += 1) {
			result.push(0);
		}
		for (var i = 0; i < data.length; i += 1) {
			var factor = result[i];
			if (factor === 0) {
				continue;
			}
			for (var j = 0; j < gen.length; j += 1) {
				result[i + j] ^= gfMul(gen[j], factor);
			}
		}
		return result.slice(result.length - degree);
	}

	function appendBits(bits, value, length) {
		for (var i = length - 1; i >= 0; i -= 1) {
			bits.push((value >>> i) & 1);
		}
	}

	function chooseVersion(byteLength) {
		for (var version = 1; version <= 10; version += 1) {
			var spec = QR_BLOCKS_M[version];
			var dataCodewords = spec.data.reduce(function (sum, value) { return sum + value; }, 0);
			var countBits = version < 10 ? 8 : 16;
			if (4 + countBits + byteLength * 8 <= dataCodewords * 8) {
				return version;
			}
		}
		return 0;
	}

	function buildCodewords(text) {
		var bytes = Array.prototype.slice.call(new TextEncoder().encode(text));
		var version = chooseVersion(bytes.length);
		if (!version) {
			throw new Error('QR data is too long');
		}

		var spec = QR_BLOCKS_M[version];
		var dataCodewords = spec.data.reduce(function (sum, value) { return sum + value; }, 0);
		var capacity = dataCodewords * 8;
		var bits = [];

		appendBits(bits, 0x4, 4);
		appendBits(bits, bytes.length, version < 10 ? 8 : 16);
		bytes.forEach(function (byte) { appendBits(bits, byte, 8); });

		var terminator = Math.min(4, capacity - bits.length);
		appendBits(bits, 0, terminator);
		while (bits.length % 8 !== 0) {
			bits.push(0);
		}

		var data = [];
		for (var i = 0; i < bits.length; i += 8) {
			data.push(parseInt(bits.slice(i, i + 8).join(''), 2));
		}

		var pad = 0;
		while (data.length < dataCodewords) {
			data.push(pad % 2 === 0 ? 0xec : 0x11);
			pad += 1;
		}

		var blocks = [];
		var offset = 0;
		spec.data.forEach(function (length) {
			var chunk = data.slice(offset, offset + length);
			offset += length;
			blocks.push({ data: chunk, ec: rsRemainder(chunk, spec.ec) });
		});

		var codewords = [];
		var maxData = Math.max.apply(null, spec.data);
		for (var d = 0; d < maxData; d += 1) {
			blocks.forEach(function (block) {
				if (typeof block.data[d] !== 'undefined') {
					codewords.push(block.data[d]);
				}
			});
		}

		for (var e = 0; e < spec.ec; e += 1) {
			blocks.forEach(function (block) {
				codewords.push(block.ec[e]);
			});
		}

		return { version: version, codewords: codewords };
	}

	function makeMatrix(text) {
		var encoded = buildCodewords(text);
		var version = encoded.version;
		var size = 21 + (version - 1) * 4;
		var modules = [];
		var reserved = [];

		for (var y = 0; y < size; y += 1) {
			modules[y] = new Array(size).fill(false);
			reserved[y] = new Array(size).fill(false);
		}

		function set(x, y, dark, reserve) {
			if (x < 0 || y < 0 || x >= size || y >= size) {
				return;
			}
			modules[y][x] = !!dark;
			if (reserve !== false) {
				reserved[y][x] = true;
			}
		}

		function drawFinder(x, y) {
			for (var dy = -1; dy <= 7; dy += 1) {
				for (var dx = -1; dx <= 7; dx += 1) {
					var xx = x + dx;
					var yy = y + dy;
					var inside = dx >= 0 && dx <= 6 && dy >= 0 && dy <= 6;
					var dark = inside && (dx === 0 || dx === 6 || dy === 0 || dy === 6 || (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4));
					set(xx, yy, dark, true);
				}
			}
		}

		drawFinder(0, 0);
		drawFinder(size - 7, 0);
		drawFinder(0, size - 7);

		for (var t = 8; t < size - 8; t += 1) {
			set(t, 6, t % 2 === 0, true);
			set(6, t, t % 2 === 0, true);
		}

		(QR_ALIGN[version] || []).forEach(function (cx) {
			(QR_ALIGN[version] || []).forEach(function (cy) {
				if (reserved[cy] && reserved[cy][cx]) {
					return;
				}
				for (var dy = -2; dy <= 2; dy += 1) {
					for (var dx = -2; dx <= 2; dx += 1) {
						var max = Math.max(Math.abs(dx), Math.abs(dy));
						set(cx + dx, cy + dy, max === 2 || max === 0, true);
					}
				}
			});
		});

		reserveFormatAreas(set, size);
		set(8, size - 8, true, true);
		if (version >= 7) {
			drawVersionInfo(set, version, size);
		}

		var bitStream = [];
		encoded.codewords.forEach(function (codeword) { appendBits(bitStream, codeword, 8); });

		var bitIndex = 0;
		var upward = true;
		for (var right = size - 1; right >= 1; right -= 2) {
			if (right === 6) {
				right -= 1;
			}
			for (var vert = 0; vert < size; vert += 1) {
				var y = upward ? size - 1 - vert : vert;
				for (var col = 0; col < 2; col += 1) {
					var x = right - col;
					if (reserved[y][x]) {
						continue;
					}
					var bit = bitIndex < bitStream.length ? bitStream[bitIndex] : 0;
					bitIndex += 1;
					var masked = bit ^ (((x + y) % 2 === 0) ? 1 : 0);
					set(x, y, masked, false);
				}
			}
			upward = !upward;
		}

		drawFormatInfo(set, 0, size);
		return modules;
	}

	function reserveFormatAreas(set, size) {
		for (var i = 0; i <= 8; i += 1) {
			if (i !== 6) {
				set(8, i, false, true);
				set(i, 8, false, true);
			}
		}
		for (var x = size - 8; x < size; x += 1) {
			set(x, 8, false, true);
		}
		for (var y = size - 7; y < size; y += 1) {
			set(8, y, false, true);
		}
	}

	function formatBits(mask) {
		var data = mask; // ECC level M is 00.
		var bits = data << 10;
		for (var i = 14; i >= 10; i -= 1) {
			if ((bits >>> i) & 1) {
				bits ^= 0x537 << (i - 10);
			}
		}
		return ((data << 10) | bits) ^ 0x5412;
	}

	function drawFormatInfo(set, mask, size) {
		var bits = formatBits(mask);
		for (var i = 0; i <= 5; i += 1) {
			set(8, i, (bits >>> i) & 1, true);
		}
		set(8, 7, (bits >>> 6) & 1, true);
		set(8, 8, (bits >>> 7) & 1, true);
		set(7, 8, (bits >>> 8) & 1, true);
		for (var j = 9; j < 15; j += 1) {
			set(14 - j, 8, (bits >>> j) & 1, true);
		}
		for (var k = 0; k < 8; k += 1) {
			set(size - 1 - k, 8, (bits >>> k) & 1, true);
		}
		for (var l = 8; l < 15; l += 1) {
			set(8, size - 15 + l, (bits >>> l) & 1, true);
		}
	}

	function versionBits(version) {
		var bits = version << 12;
		for (var i = 17; i >= 12; i -= 1) {
			if ((bits >>> i) & 1) {
				bits ^= 0x1f25 << (i - 12);
			}
		}
		return (version << 12) | bits;
	}

	function drawVersionInfo(set, version, size) {
		var bits = versionBits(version);
		for (var i = 0; i < 18; i += 1) {
			var bit = (bits >>> i) & 1;
			set(size - 11 + (i % 3), Math.floor(i / 3), bit, true);
			set(Math.floor(i / 3), size - 11 + (i % 3), bit, true);
		}
	}

	function matrixToSvg(matrix) {
		var border = 4;
		var size = matrix.length;
		var viewSize = size + border * 2;
		var path = [];
		for (var y = 0; y < size; y += 1) {
			for (var x = 0; x < size; x += 1) {
				if (matrix[y][x]) {
					path.push('M' + (x + border) + ' ' + (y + border) + 'h1v1h-1z');
				}
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + viewSize + ' ' + viewSize + '" role="img" aria-label="Payment QR code"><rect width="100%" height="100%" fill="#fff"/><path d="' + path.join('') + '" fill="#111"/></svg>';
	}

	function renderQr(text) {
		var container = document.querySelector('[data-pwc-qr]');
		if (!container) {
			return;
		}
		if (!text) {
			container.textContent = 'Waiting for payment address...';
			return;
		}
		try {
			container.innerHTML = matrixToSvg(makeMatrix(String(text)));
		} catch (error) {
			container.textContent = String(text);
		}
	}

	function updateFields(data) {
		currentData = Object.assign({}, currentData, data || {});

		setText('status_label', currentData.status_label || currentData.status || 'Awaiting Payment');
		setText('amount_crypto', currentData.amount_crypto || '');
		setInput('amount_crypto_input', currentData.amount_crypto || '');
		setText('amount_fiat', currentData.amount_fiat || '');
		setText('fiat_currency', currentData.fiat_currency || '');
		setText('crypto', currentData.crypto || '');
		setInput('payment_address', currentData.payment_address || '');
		setText('chain', currentData.chain || '');
		setText('network', currentData.network || '');
		setText('confirmed_amount', currentData.confirmed_amount || '0');
		setText('remaining_amount', currentData.remaining_amount || '');
		setText('tx_hash', currentData.tx_hash || '');
		updateBadge(currentData.status || 'PENDING');
		updateMessage(currentData.payment_status_message || currentData.sync_notice || '');
		updateTransaction(currentData.tx_hash || '', currentData.transaction_url || '');
		updateExpiry(currentData.expires_at || '');
		renderQr(currentData.qr_code_data || currentData.payment_address || '');

		if (isTerminal(currentData.status)) {
			stopPolling();
		}

		if (String(currentData.status || '').toUpperCase() === 'PAID' && !redirected && currentData.redirect_url) {
			redirected = true;
			updateMessage((config.i18n && config.i18n.redirecting) || 'Payment received. Redirecting...');
			window.setTimeout(function () {
				window.location.href = currentData.redirect_url;
			}, 1800);
		}
	}

	function setText(field, value) {
		document.querySelectorAll('[data-pwc-field="' + field + '"]').forEach(function (node) {
			node.textContent = value;
		});
	}

	function setInput(field, value) {
		document.querySelectorAll('[data-pwc-field="' + field + '"]').forEach(function (node) {
			if ('value' in node) {
				node.value = value;
			} else {
				node.textContent = value;
			}
		});
	}

	function updateBadge(status) {
		var badge = document.querySelector('[data-pwc-status-badge]');
		if (!badge) {
			return;
		}
		badge.className = badge.className.replace(/\bpwc-status-[a-z0-9_-]+\b/g, '').trim();
		badge.classList.add('pwc-status-badge');
		badge.classList.add('pwc-status-' + String(status || 'pending').toLowerCase().replace(/[^a-z0-9_-]/g, '-'));
	}

	function updateMessage(message) {
		var node = document.querySelector('[data-pwc-message]');
		if (!node) {
			return;
		}
		node.innerHTML = message || '';
		node.hidden = !message;
	}

	function updateTransaction(txHash, txUrl) {
		var wrap = document.querySelector('[data-pwc-transaction]');
		var link = document.querySelector('[data-pwc-tx-link]');
		if (wrap) {
			wrap.hidden = !txHash;
		}
		if (link) {
			if (txUrl) {
				link.href = txUrl;
				link.hidden = false;
			} else {
				link.removeAttribute('href');
			}
		}
	}

	function updateExpiry(value) {
		var node = document.querySelector('[data-pwc-expires-at]');
		if (!node) {
			return;
		}
		node.setAttribute('data-pwc-expires-at', value || '');
		if (expiryTimer) {
			window.clearInterval(expiryTimer);
		}
		function tick() {
			var text = formatExpiry(node.getAttribute('data-pwc-expires-at'));
			node.textContent = text || value || '';
		}
		tick();
		expiryTimer = window.setInterval(tick, 1000);
	}

	function formatExpiry(value) {
		if (!value) {
			return '';
		}
		var ms;
		if (/^\d+$/.test(String(value))) {
			ms = parseInt(value, 10);
			if (ms < 100000000000) {
				ms *= 1000;
			}
		} else {
			ms = Date.parse(value);
		}
		if (!ms || Number.isNaN(ms)) {
			return String(value);
		}
		var diff = Math.max(0, Math.floor((ms - Date.now()) / 1000));
		if (diff <= 0) {
			return (config.i18n && config.i18n.expired) || 'Expired';
		}
		var minutes = Math.floor(diff / 60);
		var seconds = diff % 60;
		return minutes + ':' + String(seconds).padStart(2, '0');
	}

	function bindCopyButtons() {
		document.querySelectorAll('[data-pwc-copy]').forEach(function (button) {
			button.addEventListener('click', function () {
				var key = button.getAttribute('data-pwc-copy');
				var value = key === 'payment_address' ? (currentData.payment_address || '') : (currentData.amount_crypto || '');
				copyText(value, button);
			});
		});
	}

	function copyText(value, button) {
		if (!value) {
			return;
		}
		var original = button.textContent;
		var copied = function () {
			button.textContent = (config.i18n && config.i18n.copied) || 'Copied';
			window.setTimeout(function () { button.textContent = original; }, 1400);
		};
		var failed = function () {
			button.textContent = (config.i18n && config.i18n.copyFailed) || 'Copy failed';
			window.setTimeout(function () { button.textContent = original; }, 1400);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(value).then(copied).catch(function () {
				fallbackCopy(value) ? copied() : failed();
			});
			return;
		}

		fallbackCopy(value) ? copied() : failed();
	}

	function fallbackCopy(value) {
		var input = document.createElement('textarea');
		input.value = value;
		input.setAttribute('readonly', 'readonly');
		input.style.position = 'fixed';
		input.style.left = '-9999px';
		document.body.appendChild(input);
		input.select();
		try {
			return document.execCommand('copy');
		} catch (error) {
			return false;
		} finally {
			document.body.removeChild(input);
		}
	}

	function pollStatus() {
		if (!config.statusUrl || isTerminal(currentData.status)) {
			return;
		}
		window.fetch(config.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (response) { return response.ok ? response.json() : null; })
			.then(function (data) {
				if (data) {
					updateFields(data);
				}
			})
			.catch(function () {});
	}

	function startPolling() {
		if (isTerminal(currentData.status)) {
			return;
		}
		pollTimer = window.setInterval(pollStatus, config.pollInterval || 3500);
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function isTerminal(status) {
		return ['PAID', 'FAILED', 'EXPIRED', 'CLOSED', 'CANCELLED'].indexOf(String(status || '').toUpperCase()) !== -1;
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (!document.querySelector('.pwc-payment-page')) {
			return;
		}
		bindCopyButtons();
		updateFields(currentData);
		startPolling();
	});
}());
