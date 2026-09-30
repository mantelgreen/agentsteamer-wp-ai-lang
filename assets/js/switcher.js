(function () {
	'use strict';

	function closeEl(root) {
		root.classList.remove('is-open');
		var btn = root.querySelector('.asl-dd-toggle');
		if (btn) {
			btn.setAttribute('aria-expanded', 'false');
		}
	}

	function closeAll(except) {
		Array.prototype.forEach.call(document.querySelectorAll('.asl-dd.is-open'), function (el) {
			if (el !== except) {
				closeEl(el);
			}
		});
	}

	function openEl(root) {
		closeAll(root);
		root.classList.add('is-open');
		var btn = root.querySelector('.asl-dd-toggle');
		if (btn) {
			btn.setAttribute('aria-expanded', 'true');
		}
	}

	function init(root) {
		var btn = root.querySelector('.asl-dd-toggle');
		var menu = root.querySelector('.asl-dd-menu');
		if (!btn || !menu) {
			return;
		}

		btn.addEventListener('click', function (e) {
			e.preventDefault();
			if (root.classList.contains('is-open')) {
				closeEl(root);
			} else {
				openEl(root);
			}
		});

		btn.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				openEl(root);
				var first = menu.querySelector('a');
				if (first) {
					first.focus();
				}
			}
		});

		menu.addEventListener('keydown', function (e) {
			var items = Array.prototype.slice.call(menu.querySelectorAll('a'));
			if (!items.length) {
				return;
			}
			var i = items.indexOf(document.activeElement);
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				items[i + 1] ? items[i + 1].focus() : items[0].focus();
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				items[i - 1] ? items[i - 1].focus() : items[items.length - 1].focus();
			} else if (e.key === 'Home') {
				e.preventDefault();
				items[0].focus();
			} else if (e.key === 'End') {
				e.preventDefault();
				items[items.length - 1].focus();
			} else if (e.key === 'Escape') {
				e.preventDefault();
				closeEl(root);
				btn.focus();
			}
		});
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		document.addEventListener('click', function (e) {
			Array.prototype.forEach.call(document.querySelectorAll('.asl-dd.is-open'), function (el) {
				if (!el.contains(e.target)) {
					closeEl(el);
				}
			});
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeAll(null);
			}
		});

		Array.prototype.forEach.call(document.querySelectorAll('[data-asl-dd]'), init);
	});
})();
