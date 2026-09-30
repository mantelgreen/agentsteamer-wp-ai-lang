(function () {
	'use strict';

	var cfg = window.AgentSteamerLang || {};
	var i18n = cfg.i18n || {};

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		// Tabs.
		var tabs = document.querySelectorAll('.asl-tabs .nav-tab');
		var panels = document.querySelectorAll('.asl-panel');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				var target = tab.getAttribute('data-tab');
				tabs.forEach(function (t) {
					t.classList.remove('nav-tab-active');
				});
				tab.classList.add('nav-tab-active');
				panels.forEach(function (p) {
					p.classList.toggle('asl-hidden', p.getAttribute('data-panel') !== target);
				});
			});
		});

		// Provider connection test.
		var button = document.getElementById('asl-test-provider');
		var status = document.getElementById('asl-test-status');
		if (button && status && cfg.restUrl) {
			button.addEventListener('click', function () {
				button.disabled = true;
				status.textContent = i18n.testing || 'Testing...';
				window.fetch(cfg.restUrl + '/providers/test', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce || ''
					},
					body: JSON.stringify({})
				})
					.then(function (res) {
						return res.json().then(function (data) {
							return { ok: res.ok, data: data };
						});
					})
					.then(function (result) {
						if (result.ok) {
							status.textContent = result.data.message + ' (' + result.data.elapsed + 's)';
						} else {
							status.textContent = (i18n.error || 'Error: ') + (result.data.message || '');
						}
					})
					.catch(function (err) {
						status.textContent = (i18n.error || 'Error: ') + err.message;
					})
					.finally(function () {
						button.disabled = false;
					});
			});
		}
	});
})();
