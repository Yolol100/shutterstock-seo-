(function (wp) {
	const apiFetch = wp && wp.apiFetch;
	const components = wp && wp.components;
	const element = wp && wp.element;
	const i18n = wp && wp.i18n;
	if (!apiFetch || !components || !element || !i18n) { return; }

	const { Button, Card, CardBody, CheckboxControl, Modal, Notice, SelectControl, Spinner, TabPanel, TextControl } = components;
	const { createElement: h, Fragment, render, useEffect, useMemo, useState } = element;
	const { __ } = i18n;
const config = window.ssiaAdmin || {};
	const apiRoot = config.root || '/wp-json/ssia/v1';
	if (config.nonce && apiFetch.createNonceMiddleware) {
		apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
	}

	function request(path, options) {
		return apiFetch(Object.assign({ url: apiRoot + path }, options || {}));
	}

	function App() {
		const [activeView, setActiveView] = useState('dashboard');
		const [stats, setStats] = useState({});
		const [pages, setPages] = useState([]);
		const [suggestions, setSuggestions] = useState([]);
		const [selectedPage, setSelectedPage] = useState(null);
		const [selectedImages, setSelectedImages] = useState([]);
		const [settings, setSettings] = useState({ acf_mapping: {}, allowed_post_types: [], post_types: [], required_scopes: [] });
		const [queue, setQueue] = useState([]);
		const [logs, setLogs] = useState([]);
		const [licensedAssets, setLicensedAssets] = useState([]);
		const [filters, setFilters] = useState({ missing: true, post_type: '', status: '', search: '' });
		const [notice, setNotice] = useState(null);
		const [loading, setLoading] = useState(false);
		const [confirming, setConfirming] = useState(false);

		const postTypeOptions = useMemo(function () {
			return [{ label: __('All allowed', 'seo-shutterstock-image-assistant'), value: '' }].concat((settings.post_types || []).map(function (type) { return { label: type.label, value: type.value }; }));
		}, [settings.post_types]);

		function loadPages() {
			const params = new URLSearchParams();
			params.set('missing', filters.missing ? '1' : '0');
			['post_type', 'status', 'search'].forEach(function (key) { if (filters[key]) { params.set(key, filters[key]); } });
			return request('/pages?' + params.toString()).then(function (response) { setPages(response.items || []); });
		}

		function refresh() {
			setLoading(true);
			return Promise.all([
				request('/dashboard').then(function (response) { setStats(response || {}); }),
				config.canManage ? request('/settings').then(function (response) { setSettings(response || {}); }) : Promise.resolve(),
				request('/queue').then(function (response) { setQueue(response.items || []); }),
				config.canManage ? request('/logs').then(function (response) { setLogs(response.items || []); }) : Promise.resolve(),
				config.canManage ? request('/licensed-assets?per_page=12').then(function (response) { setLicensedAssets(response.items || []); }).catch(function () { setLicensedAssets([]); }) : Promise.resolve()
			]).then(loadPages).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Could not load SEO Images.', 'seo-shutterstock-image-assistant') });
			}).finally(function () { setLoading(false); });
		}

		useEffect(function () { refresh(); }, []);
		useEffect(function () {
			const params = new URLSearchParams(window.location.search);
			const code = params.get('code');
			const state = params.get('state');
			if (code && state && config.canManage) {
				request('/oauth-exchange', { method: 'POST', data: { code: code, state: state } }).then(function (response) {
					setNotice({ status: 'success', message: response.message || __('Shutterstock connected.', 'seo-shutterstock-image-assistant') });
					window.history.replaceState({}, document.title, window.location.pathname + '?page=ssia-dashboard#ssia-settings');
					refresh();
				}).catch(function (error) {
					setNotice({ status: 'error', message: error.message || __('OAuth callback could not be completed.', 'seo-shutterstock-image-assistant') });
				});
			}
		}, []);
		useEffect(function () { loadPages().catch(function () {}); }, [filters]);

		function updateSetting(key, value) {
			setSettings(function (current) { return Object.assign({}, current, { [key]: value }); });
		}

		function updateMapping(key, value) {
			setSettings(function (current) {
				return Object.assign({}, current, { acf_mapping: Object.assign({}, current.acf_mapping || {}, { [key]: value }) });
			});
		}

		function findImages(page) {
			setSelectedPage(page);
			setSelectedImages([]);
			setLoading(true);
			request('/suggestions', { method: 'POST', data: { post_id: page.id } }).then(function (response) {
				setSuggestions(response.items || []);
				setNotice({ status: 'success', message: __('Suggestions ready', 'seo-shutterstock-image-assistant') });
				setActiveView('review');
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Could not fetch suggestions.', 'seo-shutterstock-image-assistant') });
			}).finally(function () { setLoading(false); });
		}

		function toggleImage(id) {
			setSelectedImages(function (current) {
				if (current.includes(id)) { return current.filter(function (item) { return item !== id; }); }
				return current.length < 3 ? current.concat(id) : current;
			});
		}

		function licenseAttach() {
			if (!selectedPage) { return; }
			setConfirming(false);
			setLoading(true);
			request('/license-attach', { method: 'POST', data: { post_id: selectedPage.id, image_ids: selectedImages } }).then(function (response) {
				setSelectedImages([]);
				setNotice({ status: 'success', message: response.message || __('Images attached successfully', 'seo-shutterstock-image-assistant') });
				refresh();
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Licensing failed.', 'seo-shutterstock-image-assistant') });
			}).finally(function () { setLoading(false); });
		}

		function saveSettings() {
			setLoading(true);
			request('/settings', { method: 'POST', data: settings }).then(function (response) {
				setSettings(response || {});
				setNotice({ status: 'success', message: __('Settings saved.', 'seo-shutterstock-image-assistant') });
				refresh();
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Settings could not be saved.', 'seo-shutterstock-image-assistant') });
			}).finally(function () { setLoading(false); });
		}

		function testConnection() {
			request('/test-connection', { method: 'POST' }).then(function (response) {
				setNotice({ status: response.scope_ok ? 'success' : 'warning', message: response.message || __('Connection checked.', 'seo-shutterstock-image-assistant') });
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Connection failed.', 'seo-shutterstock-image-assistant') });
			});
		}


		function checkScopes() {
			request('/scope-check', { method: 'POST' }).then(function (response) {
				setNotice({ status: 'success', message: response.message || __('Scope check passed.', 'seo-shutterstock-image-assistant') });
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Scope check failed.', 'seo-shutterstock-image-assistant') });
			});
		}

		function connectOAuth() {
			request('/oauth-url', { method: 'POST' }).then(function (response) {
				if (response.url) {
					window.open(response.url, '_blank', 'noopener,noreferrer');
					setNotice({ status: 'info', message: response.message || __('OAuth window opened.', 'seo-shutterstock-image-assistant') });
				} else {
					setNotice({ status: 'error', message: __('OAuth URL could not be created.', 'seo-shutterstock-image-assistant') });
				}
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('OAuth URL could not be created.', 'seo-shutterstock-image-assistant') });
			});
		}


		function recoverQueueItem(item, actionType) {
			setLoading(true);
			request('/recover', { method: 'POST', data: { post_id: item.post_id, action_type: actionType } }).then(function (response) {
				setNotice({ status: 'success', message: response.message || __('Recovery completed.', 'seo-shutterstock-image-assistant') });
				refresh();
			}).catch(function (error) {
				setNotice({ status: 'error', message: error.message || __('Recovery failed.', 'seo-shutterstock-image-assistant') });
			}).finally(function () { setLoading(false); });
		}

		return h('div', { className: 'ssia-admin__shell' },
			stepper(activeView, setActiveView),
			notice ? h(Notice, { status: notice.status, onRemove: function () { setNotice(null); } }, notice.message) : null,
			loading ? h('div', { className: 'ssia-admin__loading' }, h(Spinner), __('Processing...', 'seo-shutterstock-image-assistant')) : null,
			activeView === 'dashboard' ? h(Fragment, null, statsCards(stats), cta(stats, setActiveView), stats.quality_gate ? qualityGate(stats.quality_gate) : null) : null,
			activeView === 'find' ? findCard({ pages, filters, setFilters, postTypeOptions, findImages, refresh, setNotice }) : null,
			activeView === 'review' ? suggestionsCard({ suggestions, selectedImages, toggleImage }) : null,
			activeView === 'attach' ? licenseCard({ selectedImages, settings, setConfirming, setSelectedImages }) : null,
			activeView === 'queue' ? h('section', { className: 'ssia-admin__utilities' }, queuePanel(queue, recoverQueueItem), config.canManage ? h(LicensedAssetsPanel, { items: licensedAssets, selectedPage: selectedPage, refresh: refresh, setNotice: setNotice }) : null, config.canManage ? logsPanel(logs) : null) : null,
			activeView === 'settings' && config.canManage ? h(SettingsPanel, { settings, updateSetting, updateMapping, saveSettings, testConnection, connectOAuth, checkScopes }) : null,
			confirming ? confirmModal(licenseAttach, setConfirming) : null
		);
	}


	function stepper(activeView, setActiveView) {
		const items = [
			{ key: 'dashboard', label: __('Dashboard', 'seo-shutterstock-image-assistant'), note: __('Overview', 'seo-shutterstock-image-assistant'), step: '•' },
			{ key: 'find', label: __('Find', 'seo-shutterstock-image-assistant'), note: __('Pages', 'seo-shutterstock-image-assistant'), step: '1' },
			{ key: 'review', label: __('Review', 'seo-shutterstock-image-assistant'), note: __('Select 3', 'seo-shutterstock-image-assistant'), step: '2' },
			{ key: 'attach', label: __('Attach', 'seo-shutterstock-image-assistant'), note: __('ACF', 'seo-shutterstock-image-assistant'), step: '3' },
			{ key: 'queue', label: __('Queue', 'seo-shutterstock-image-assistant'), note: __('Bulk', 'seo-shutterstock-image-assistant'), step: '4' },
			config.canManage ? { key: 'settings', label: __('Settings', 'seo-shutterstock-image-assistant'), note: __('API + ACF', 'seo-shutterstock-image-assistant'), step: '5' } : null
		].filter(Boolean);
		return h('nav', { className: 'ssia-admin__stepper', 'aria-label': __('SEO Images sections', 'seo-shutterstock-image-assistant') }, items.map(function (item) {
			return h('button', { key: item.key, type: 'button', className: activeView === item.key ? 'is-active' : '', onClick: function () { setActiveView(item.key); } }, h('span', null, item.step), h('strong', null, item.label), h('small', null, item.note));
		}));
	}

	function statsCards(stats) {
		const cards = [
			{ key: 'pages_missing_images', label: __('Pages missing images', 'seo-shutterstock-image-assistant'), icon: '◌', hint: __('Needs review', 'seo-shutterstock-image-assistant') },
			{ key: 'suggestions_ready', label: __('Suggestions ready', 'seo-shutterstock-image-assistant'), icon: '✦', hint: __('Ready to select', 'seo-shutterstock-image-assistant') },
			{ key: 'licensed_this_month', label: __('Licensed this month', 'seo-shutterstock-image-assistant'), icon: '✓', hint: __('Approved assets', 'seo-shutterstock-image-assistant') },
			{ key: 'failed_actions', label: __('Failed actions', 'seo-shutterstock-image-assistant'), icon: '!', hint: __('Needs attention', 'seo-shutterstock-image-assistant') }
		];
		return h('section', { id: 'ssia-overview', className: 'ssia-admin__cards' }, cards.map(function (item) {
			return h(Card, { key: item.key, className: 'ssia-admin__stat ssia-admin__stat--' + item.key }, h(CardBody, null, h('div', { className: 'ssia-admin__stat-top' }, h('span', null, item.label), h('i', { 'aria-hidden': 'true' }, item.icon)), h('strong', null, stats[item.key] == null ? '0' : stats[item.key]), h('small', null, item.hint)));
		}));
	}

	function cta(stats, setActiveView) {
		return h('div', { className: 'ssia-admin__cta' },
			h('div', null, h('strong', null, __('Ready for the 3-step workflow', 'seo-shutterstock-image-assistant')), h('span', null, __('Keep licensing controlled: no automatic purchases, no frontend assets.', 'seo-shutterstock-image-assistant'))),
			h('div', { className: 'ssia-admin__cta-actions' }, h('span', { className: stats.api_connected ? 'ssia-admin__pill is-success' : 'ssia-admin__pill is-warning' }, stats.api_connected ? __('API connected', 'seo-shutterstock-image-assistant') : __('Connect Shutterstock', 'seo-shutterstock-image-assistant')), h(Button, { variant: 'primary', onClick: function () { setActiveView('find'); } }, __('Start finding images', 'seo-shutterstock-image-assistant'))));
	}

	function qualityGate(gate) {
		const checks = Object.entries(gate.checks || {}).slice(0, 4);
		return h('section', { className: 'ssia-admin__quality', 'aria-label': __('Release quality gate', 'seo-shutterstock-image-assistant') },
			h('div', { className: 'ssia-admin__quality-score' }, h('span', null, gate.score == null ? 100 : gate.score), h('small', null, __('Quality Gate', 'seo-shutterstock-image-assistant'))),
			h('div', { className: 'ssia-admin__quality-copy' }, h('strong', null, gate.label || __('100/100 target package', 'seo-shutterstock-image-assistant')), h('p', null, gate.static_note || __('Built for a premium WordPress SaaS agency workflow. Final production proof depends on live Shutterstock and WordPress runtime checks.', 'seo-shutterstock-image-assistant'))),
			h('ul', { className: 'ssia-admin__quality-list' }, checks.map(function (entry) { const key = entry[0]; const check = entry[1]; return h('li', { key: key, className: check.passed ? 'is-pass' : 'is-open' }, h('span', { 'aria-hidden': 'true' }, check.passed ? '✓' : '•'), check.label); }))
		);
	}


	function findCard(props) {
		return h(Card, { id: 'ssia-finder', className: 'ssia-admin__workflow-card ssia-admin__workflow-card--find' }, h(CardBody, null,
			h('div', { className: 'ssia-admin__section-head' }, h('div', null, h('span', null, __('Step 1', 'seo-shutterstock-image-assistant')), h('h2', null, __('Find Images', 'seo-shutterstock-image-assistant')), h('p', null, __('Surface SEO pages that still need approved visual content.', 'seo-shutterstock-image-assistant'))), h(Button, { variant: 'secondary', disabled: !props.pages.length, onClick: function () { request('/queue', { method: 'POST', data: { post_ids: props.pages.map(function (page) { return page.id; }) } }).then(function (response) { if (props.setNotice) { props.setNotice({ status: 'success', message: response.message || __('Pages added to the queue.', 'seo-shutterstock-image-assistant') }); } if (props.refresh) { props.refresh(); } }).catch(function (error) { if (props.setNotice) { props.setNotice({ status: 'error', message: error.message || __('Could not add pages to the queue.', 'seo-shutterstock-image-assistant') }); } }); } }, __('Add visible pages to queue', 'seo-shutterstock-image-assistant'))),
			h('div', { className: 'ssia-admin__filters' },
				h(TextControl, { label: __('Search pages', 'seo-shutterstock-image-assistant'), value: props.filters.search, onChange: function (value) { props.setFilters(Object.assign({}, props.filters, { search: value })); } }),
				h(SelectControl, { label: __('Post type', 'seo-shutterstock-image-assistant'), value: props.filters.post_type, options: props.postTypeOptions, onChange: function (value) { props.setFilters(Object.assign({}, props.filters, { post_type: value })); } }),
				h(SelectControl, { label: __('Status', 'seo-shutterstock-image-assistant'), value: props.filters.status, options: [{ label: __('Publish + draft', 'seo-shutterstock-image-assistant'), value: '' }, { label: __('Publish', 'seo-shutterstock-image-assistant'), value: 'publish' }, { label: __('Draft', 'seo-shutterstock-image-assistant'), value: 'draft' }, { label: __('Pending', 'seo-shutterstock-image-assistant'), value: 'pending' }], onChange: function (value) { props.setFilters(Object.assign({}, props.filters, { status: value })); } }),
				h(CheckboxControl, { label: __('Only pages without 3 images', 'seo-shutterstock-image-assistant'), checked: props.filters.missing, onChange: function (value) { props.setFilters(Object.assign({}, props.filters, { missing: value })); } })
			),
			props.pages.length ? h('div', { className: 'ssia-admin__page-list' }, props.pages.map(function (page) { return pageRow(page, props.findImages); })) : h('div', { className: 'ssia-admin__empty' }, h('strong', null, __('No pages found', 'seo-shutterstock-image-assistant')), h('p', null, __('Try changing the filters or check your content source settings.', 'seo-shutterstock-image-assistant')))
		));
	}

	function pageRow(page, findImages) {
		const statusClass = 'ssia-admin__badge ' + String(page.badge || '').toLowerCase().replace(/[^a-z0-9]+/g, '-');
		return h('div', { className: 'ssia-admin__page', key: page.id }, h('div', null, h('strong', null, page.title), h('span', null, page.slug + ' · ' + page.post_type + ' · ' + page.status + ' · ' + page.current_images + '/3'), h('em', { className: statusClass }, page.badge), page.focus_keyword ? h('small', null, __('Keyword:', 'seo-shutterstock-image-assistant') + ' ' + page.focus_keyword) : null), h(Button, { variant: page.badge === 'Suggestions ready' ? 'secondary' : 'primary', onClick: function () { findImages(page); } }, page.badge === 'Suggestions ready' ? __('Regenerate Suggestions', 'seo-shutterstock-image-assistant') : __('Find Images', 'seo-shutterstock-image-assistant')));
	}

	function suggestionsCard(props) {
		return h(Card, { id: 'ssia-review', className: 'ssia-admin__workflow-card ssia-admin__workflow-card--review' }, h(CardBody, null,
			h('div', { className: 'ssia-admin__section-head ssia-admin__section-head--modern' }, h('div', null, h('span', null, __('Step 2', 'seo-shutterstock-image-assistant')), h('h2', null, __('Review Suggestions', 'seo-shutterstock-image-assistant')), h('p', null, __('Pick the best three visuals before any licensing happens.', 'seo-shutterstock-image-assistant'))), h('div', { className: props.selectedImages.length === 3 ? 'ssia-admin__meter is-ready' : 'ssia-admin__meter' }, props.selectedImages.length + '/3 ' + __('selected', 'seo-shutterstock-image-assistant'))),
			props.suggestions.length ? h('div', { className: 'ssia-admin__review-grid' }, props.suggestions.map(function (item) {
				const selected = props.selectedImages.includes(item.id);
				return h('button', { type: 'button', key: item.id, className: selected ? 'ssia-admin__image-card is-selected' : 'ssia-admin__image-card', 'aria-pressed': selected ? 'true' : 'false', 'aria-label': __('Select Shutterstock image', 'seo-shutterstock-image-assistant') + ' ' + item.id, onClick: function () { props.toggleImage(item.id); } },
					h('span', { className: 'ssia-admin__checkmark', 'aria-hidden': 'true' }, selected ? '✓' : '+'),
					h('span', { className: 'ssia-admin__image-frame' }, h('img', { src: item.thumbnail, alt: '' })),
					h('span', { className: 'ssia-admin__image-content' }, h('strong', null, item.match_score || __('Match', 'seo-shutterstock-image-assistant')), h('span', null, item.description || __('Shutterstock suggestion', 'seo-shutterstock-image-assistant')), h('small', null, 'ID ' + item.id + ' · ' + item.orientation + ' · ' + item.contributor))
				);
			})) : h('div', { className: 'ssia-admin__empty ssia-admin__empty--review' }, h('strong', null, __('No suggestions yet', 'seo-shutterstock-image-assistant')), h('p', null, __('Choose a page in Find to generate a clean review grid here.', 'seo-shutterstock-image-assistant')))
		));
	}

	function licenseCard(props) {
		const mapping = props.settings.acf_mapping || {};
		const ready = props.selectedImages.length === 3;
		return h(Card, { id: 'ssia-attach', className: 'ssia-admin__workflow-card ssia-admin__license-card' }, h(CardBody, null,
			h('div', { className: 'ssia-admin__section-head ssia-admin__section-head--modern' }, h('div', null, h('span', null, __('Step 3', 'seo-shutterstock-image-assistant')), h('h2', null, __('License & Attach', 'seo-shutterstock-image-assistant')), h('p', null, __('Final controlled step: license the approved images and attach them to mapped ACF fields.', 'seo-shutterstock-image-assistant'))), h('div', { className: ready ? 'ssia-admin__meter is-ready' : 'ssia-admin__meter' }, ready ? __('Ready to attach', 'seo-shutterstock-image-assistant') : __('Waiting for 3 selections', 'seo-shutterstock-image-assistant'))),
			h('div', { className: 'ssia-admin__attach-layout' },
				h('div', { className: 'ssia-admin__attach-copy' }, h('strong', null, __('Attachment plan', 'seo-shutterstock-image-assistant')), h('p', null, __('The three selected images are assigned in order. Review your ACF mapping before licensing.', 'seo-shutterstock-image-assistant'))),
				h('ul', { className: 'ssia-admin__mapping' }, h('li', null, h('span', null, __('Image 1', 'seo-shutterstock-image-assistant')), h('strong', null, mapping.image_1 || 'image_1')), h('li', null, h('span', null, __('Image 2', 'seo-shutterstock-image-assistant')), h('strong', null, mapping.image_2 || 'image_2')), h('li', null, h('span', null, __('Image 3', 'seo-shutterstock-image-assistant')), h('strong', null, mapping.image_3 || 'image_3')))
			),
			h('div', { className: 'ssia-admin__action-bar' }, h('div', null, h('strong', null, ready ? __('All set', 'seo-shutterstock-image-assistant') : __('Selection needed', 'seo-shutterstock-image-assistant')), h('span', null, ready ? __('You can now license and attach.', 'seo-shutterstock-image-assistant') : __('Select exactly three images in Review.', 'seo-shutterstock-image-assistant'))), h('div', { className: 'ssia-admin__button-row' }, h(Button, { variant: 'primary', disabled: !config.canLicense || !ready, onClick: function () { props.setConfirming(true); } }, __('License & Attach', 'seo-shutterstock-image-assistant')), h(Button, { variant: 'tertiary', onClick: function () { props.setSelectedImages([]); } }, __('Clear', 'seo-shutterstock-image-assistant'))))
		));
	}

	function queuePanel(queue, recoverQueueItem) {
		const priority = { failed: 0, imported_needs_acf: 1, retrying: 2, pending: 3, licensed: 4, suggestions_found: 5, attached: 6, skipped: 7 };
		const sorted = (queue || []).slice().sort(function (a, b) { return (priority[a.status] == null ? 99 : priority[a.status]) - (priority[b.status] == null ? 99 : priority[b.status]); });
		return h(Card, { id: 'ssia-queue', className: 'ssia-admin__panel ssia-admin__list-panel' }, h(CardBody, null, h('div', { className: 'ssia-admin__section-head' }, h('div', null, h('span', null, __('Bulk processing', 'seo-shutterstock-image-assistant')), h('h2', null, __('Queue', 'seo-shutterstock-image-assistant')))), sorted.length ? h('div', { className: 'ssia-admin__list' }, sorted.slice(0, 12).map(function (item) { return h('p', { key: item.post_id, className: item.status === 'failed' || item.status === 'imported_needs_acf' ? 'is-attention' : '' }, h('strong', null, '#' + item.post_id), h('span', { className: 'ssia-admin__badge ' + String(item.status || '').replace(/_/g, '-') }, item.status), item.message ? h('small', null, item.message) : h('small', null, item.updated_at || ''), recoveryActions(item, recoverQueueItem)); })) : h('div', { className: 'ssia-admin__empty is-compact' }, __('No queue items yet.', 'seo-shutterstock-image-assistant'))));
	}

	function recoveryActions(item, recoverQueueItem) {
		if (!recoverQueueItem) { return null; }
		if (item.status === 'imported_needs_acf') { return h(Button, { variant: 'secondary', onClick: function () { recoverQueueItem(item, 'retry_acf'); } }, __('Retry ACF attach', 'seo-shutterstock-image-assistant')); }
		if (item.status === 'failed' && item.recovery === 'redownload_or_retry_import') { return h(Button, { variant: 'secondary', onClick: function () { recoverQueueItem(item, 'retry_import'); } }, __('Retry import', 'seo-shutterstock-image-assistant')); }
		if (item.status === 'failed') { return h(Button, { variant: 'tertiary', onClick: function () { recoverQueueItem(item, 'retry_suggestions'); } }, __('Retry suggestions', 'seo-shutterstock-image-assistant')); }
		return null;
	}

	function logsPanel(logs) {
		return h(Card, { id: 'ssia-logs', className: 'ssia-admin__panel ssia-admin__list-panel' }, h(CardBody, null, h('div', { className: 'ssia-admin__section-head' }, h('div', null, h('span', null, __('Recent activity', 'seo-shutterstock-image-assistant')), h('h2', null, __('Logs', 'seo-shutterstock-image-assistant')))), logs.length ? h('div', { className: 'ssia-admin__list' }, logs.slice(0, 12).map(function (item, index) { return h('p', { key: index }, h('strong', null, item.level), h('span', null, item.action), h('small', null, item.time)); })) : h('div', { className: 'ssia-admin__empty is-compact' }, __('No log entries yet.', 'seo-shutterstock-image-assistant'))));
	}

	function LicensedAssetsPanel(props) {
		const items = props.items || [];
		const [selected, setSelected] = useState([]);
		const selectedAssets = items.filter(function (item) { return selected.indexOf(item.license_id || '') !== -1; });
		function toggle(item) {
			const key = item.license_id || '';
			if (!key) { return; }
			setSelected(function (current) { return current.indexOf(key) !== -1 ? current.filter(function (value) { return value !== key; }) : current.concat([key]); });
		}
		function bulkDownload(importToPage) {
			if (!selectedAssets.length) {
				if (props.setNotice) { props.setNotice({ status: 'warning', message: __('Select at least one licensed asset first.', 'seo-shutterstock-image-assistant') }); }
				return;
			}
			if (importToPage && !props.selectedPage) {
				if (props.setNotice) { props.setNotice({ status: 'warning', message: __('Select a page before importing licensed assets.', 'seo-shutterstock-image-assistant') }); }
				return;
			}
			request('/licensed-assets/bulk-download', { method: 'POST', data: { assets: selectedAssets.map(function (item) { return { license_id: item.license_id, image_id: item.image_id }; }), post_id: props.selectedPage ? props.selectedPage.id : 0, import: !!importToPage, size: 'huge' } }).then(function (response) {
				var ready = (response.downloads || []).filter(function (item) { return item.download_url; }).length;
				if (props.setNotice) { props.setNotice({ status: (response.failed || []).length ? 'warning' : 'success', message: ready && !importToPage ? ready + ' ' + __('download links are ready in the licensed library; open each item individually to avoid popup blockers.', 'seo-shutterstock-image-assistant') : (response.message || __('Bulk action completed.', 'seo-shutterstock-image-assistant')) }); }
				setSelected([]);
				if (props.refresh) { props.refresh(); }
			}).catch(function (error) {
				if (props.setNotice) { props.setNotice({ status: 'error', message: error.message || __('Bulk action failed.', 'seo-shutterstock-image-assistant') }); }
			});
		}
		return h(Card, { id: 'ssia-library', className: 'ssia-admin__panel ssia-admin__assets-panel' }, h(CardBody, null,
			h('div', { className: 'ssia-admin__section-head' }, h('div', null, h('span', null, __('Licensed library', 'seo-shutterstock-image-assistant')), h('h2', null, __('Previously licensed assets', 'seo-shutterstock-image-assistant')))),
			items.length ? h('div', { className: 'ssia-admin__bulk-bar' }, h('strong', null, selected.length + ' ' + __('selected', 'seo-shutterstock-image-assistant')), h('div', { className: 'ssia-admin__button-row' }, h(Button, { variant: 'secondary', disabled: !selected.length, onClick: function () { bulkDownload(false); } }, __('Download selected', 'seo-shutterstock-image-assistant')), props.selectedPage ? h(Button, { variant: 'primary', disabled: !selected.length, onClick: function () { bulkDownload(true); } }, __('Import selected to page', 'seo-shutterstock-image-assistant')) : null, selected.length ? h(Button, { variant: 'tertiary', onClick: function () { setSelected([]); } }, __('Clear selection', 'seo-shutterstock-image-assistant')) : null)) : null,
			items.length ? h('div', { className: 'ssia-admin__asset-list' }, items.map(function (item) { const key = item.license_id || item.image_id; const checked = selected.indexOf(item.license_id || '') !== -1; return h('div', { className: 'ssia-admin__asset' + (checked ? ' is-selected' : ''), key: key }, h('div', { className: 'ssia-admin__asset-meta' }, item.license_id && item.is_downloadable ? h(CheckboxControl, { label: __('Select asset', 'seo-shutterstock-image-assistant'), hideLabelFromVision: true, checked: checked, onChange: function () { toggle(item); } }) : null, h('div', null, h('strong', null, item.image_id), h('small', null, item.created_at || ''))), item.license_id && item.is_downloadable ? h('div', { className: 'ssia-admin__button-row' }, h(Button, { variant: 'secondary', onClick: function () { request('/licensed-assets/download', { method: 'POST', data: { license_id: item.license_id, size: item.size || 'huge' } }).then(function (response) { if (response.download_url) { window.open(response.download_url, '_blank', 'noopener,noreferrer'); } if (props.setNotice) { props.setNotice({ status: response.download_url ? 'success' : 'warning', message: response.download_url ? __('Redownload link created.', 'seo-shutterstock-image-assistant') : __('Shutterstock did not return a download link.', 'seo-shutterstock-image-assistant') }); } }).catch(function (error) { if (props.setNotice) { props.setNotice({ status: 'error', message: error.message || __('Redownload failed.', 'seo-shutterstock-image-assistant') }); } }); } }, __('Redownload', 'seo-shutterstock-image-assistant')), props.selectedPage ? h(Button, { variant: 'primary', onClick: function () { request('/licensed-assets/download', { method: 'POST', data: { license_id: item.license_id, image_id: item.image_id, post_id: props.selectedPage.id, import: true, size: item.size || 'huge' } }).then(function (response) { if (props.setNotice) { props.setNotice({ status: 'success', message: response.attachment_id ? __('Imported to Media Library.', 'seo-shutterstock-image-assistant') : __('Redownload link created.', 'seo-shutterstock-image-assistant') }); } if (props.refresh) { props.refresh(); } }).catch(function (error) { if (props.setNotice) { props.setNotice({ status: 'error', message: error.message || __('Import failed.', 'seo-shutterstock-image-assistant') }); } }); } }, __('Import to selected page', 'seo-shutterstock-image-assistant')) : null) : h('span', { className: 'ssia-admin__pill' }, __('Not downloadable', 'seo-shutterstock-image-assistant'))); })) : h('div', { className: 'ssia-admin__empty is-compact' }, __('No licensed assets loaded yet.', 'seo-shutterstock-image-assistant'))));
	}


	function SettingsPanel(props) {
		const settings = props.settings || {};
		const mapping = settings.acf_mapping || {};
		return h(Card, { id: 'ssia-settings', className: 'ssia-admin__settings' }, h(CardBody, null,
			h('div', { className: 'ssia-admin__section-head' }, h('div', null, h('span', null, __('Configuration', 'seo-shutterstock-image-assistant')), h('h2', null, __('Settings', 'seo-shutterstock-image-assistant')), h('p', null, __('Connect Shutterstock, choose content sources, map ACF fields and control licensing behaviour.', 'seo-shutterstock-image-assistant')))),
			h(TabPanel, { tabs: [{ name: 'api', title: __('API connection', 'seo-shutterstock-image-assistant') }, { name: 'content', title: __('Content', 'seo-shutterstock-image-assistant') }, { name: 'acf', title: __('ACF fields', 'seo-shutterstock-image-assistant') }, { name: 'licensing', title: __('Licensing', 'seo-shutterstock-image-assistant') }, { name: 'permissions', title: __('Roles', 'seo-shutterstock-image-assistant') }] }, function (active) {
				return h('div', { className: 'ssia-admin__tab' }, settingsTab(active.name, settings, mapping, props));
			}),
			h('div', { className: 'ssia-admin__save' }, h(Button, { variant: 'primary', onClick: props.saveSettings }, __('Save settings', 'seo-shutterstock-image-assistant')))
		));
	}

	function fieldGroup(title, intro, children) {
		return h('div', { className: 'ssia-admin__settings-grid' }, h('div', { className: 'ssia-admin__settings-intro' }, h('strong', null, title), h('p', null, intro)), h('div', { className: 'ssia-admin__settings-fields' }, children));
	}

	function settingsTab(name, settings, mapping, props) {
		if (name === 'api') { return fieldGroup(__('Shutterstock API', 'seo-shutterstock-image-assistant'), __('Use OAuth for licensing. Basic credentials are kept for search-only fallback where available.', 'seo-shutterstock-image-assistant'), [h(TextControl, { key: 'api_key', label: __('API Key', 'seo-shutterstock-image-assistant'), value: settings.api_key || '', onChange: function (value) { props.updateSetting('api_key', value); } }), h(TextControl, { key: 'api_secret', label: __('API Secret', 'seo-shutterstock-image-assistant'), type: 'password', value: settings.api_secret || '', onChange: function (value) { props.updateSetting('api_secret', value); } }), h(TextControl, { key: 'oauth_client_id', label: __('OAuth Client ID', 'seo-shutterstock-image-assistant'), value: settings.oauth_client_id || '', onChange: function (value) { props.updateSetting('oauth_client_id', value); } }), h(TextControl, { key: 'oauth_client_secret', label: __('OAuth Client Secret', 'seo-shutterstock-image-assistant'), type: 'password', value: settings.oauth_client_secret || '', onChange: function (value) { props.updateSetting('oauth_client_secret', value); } }), h(TextControl, { key: 'oauth_redirect_uri', label: __('OAuth Redirect URI', 'seo-shutterstock-image-assistant'), value: settings.oauth_redirect_uri || '', onChange: function (value) { props.updateSetting('oauth_redirect_uri', value); }, help: __('Use this dashboard URL for the Shutterstock callback.', 'seo-shutterstock-image-assistant') }), h(TextControl, { key: 'access_token', label: __('Access Token', 'seo-shutterstock-image-assistant'), type: 'password', value: settings.access_token || '', onChange: function (value) { props.updateSetting('access_token', value); }, help: (settings.required_scopes || []).join(', ') }), h(TextControl, { key: 'refresh_token', label: __('Refresh Token', 'seo-shutterstock-image-assistant'), type: 'password', value: settings.refresh_token || '', onChange: function (value) { props.updateSetting('refresh_token', value); } }), h(TextControl, { key: 'subscription_id', label: __('Subscription ID', 'seo-shutterstock-image-assistant'), value: settings.subscription_id || '', onChange: function (value) { props.updateSetting('subscription_id', value); } }), h('div', { key: 'buttons', className: 'ssia-admin__button-row ssia-admin__button-row--sticky' }, h(Button, { variant: 'primary', onClick: props.connectOAuth }, __('Log in with Shutterstock', 'seo-shutterstock-image-assistant')), h(Button, { variant: 'secondary', onClick: props.testConnection }, __('Test Connection', 'seo-shutterstock-image-assistant')), h(Button, { variant: 'secondary', onClick: props.checkScopes }, __('Check licensing scopes', 'seo-shutterstock-image-assistant')))]); }
		if (name === 'content') { return fieldGroup(__('Content sources', 'seo-shutterstock-image-assistant'), __('Choose which visible post types can enter the image workflow and which SEO plugins can provide keywords.', 'seo-shutterstock-image-assistant'), [(settings.post_types || []).map(function (type) { return h(CheckboxControl, { key: type.value, label: type.label, checked: (settings.allowed_post_types || []).includes(type.value), onChange: function (checked) { props.updateSetting('allowed_post_types', checked ? (settings.allowed_post_types || []).concat(type.value) : (settings.allowed_post_types || []).filter(function (value) { return value !== type.value; })); } }); }), h(CheckboxControl, { key: 'yoast', label: __('Yoast support', 'seo-shutterstock-image-assistant'), checked: !!settings.yoast_support, onChange: function (value) { props.updateSetting('yoast_support', value); } }), h(CheckboxControl, { key: 'rank', label: __('Rank Math support', 'seo-shutterstock-image-assistant'), checked: !!settings.rank_math_support, onChange: function (value) { props.updateSetting('rank_math_support', value); } }), h(CheckboxControl, { key: 'acf_support', label: __('ACF support', 'seo-shutterstock-image-assistant'), checked: !!settings.acf_support, onChange: function (value) { props.updateSetting('acf_support', value); } })]); }
		if (name === 'acf') { return fieldGroup(__('ACF mapping', 'seo-shutterstock-image-assistant'), __('Map the three approved images to the ACF image fields used by Elementor. Featured image is optional.', 'seo-shutterstock-image-assistant'), [h('div', { key: 'acfgrid', className: 'ssia-admin__field-grid' }, ['image_1', 'image_2', 'image_3', 'featured_image'].map(function (field) { return h(TextControl, { key: field, label: field === 'featured_image' ? __('featured_image optional', 'seo-shutterstock-image-assistant') : field, value: mapping[field] || '', onChange: function (value) { props.updateMapping(field, value); } }); })), h(SelectControl, { key: 'format', label: __('ACF return format', 'seo-shutterstock-image-assistant'), value: settings.acf_return_format || 'id', options: [{ label: __('ID', 'seo-shutterstock-image-assistant'), value: 'id' }, { label: __('Array', 'seo-shutterstock-image-assistant'), value: 'array' }, { label: __('URL', 'seo-shutterstock-image-assistant'), value: 'url' }], onChange: function (value) { props.updateSetting('acf_return_format', value); } })]); }
		if (name === 'licensing') { return fieldGroup(__('Licensing defaults', 'seo-shutterstock-image-assistant'), __('Set safe defaults for search results, licensing format, batch size and retention.', 'seo-shutterstock-image-assistant'), [h('div', { key: 'licensegrid', className: 'ssia-admin__field-grid' }, h(SelectControl, { label: __('Default orientation', 'seo-shutterstock-image-assistant'), value: settings.default_orientation || 'horizontal', options: [{ label: __('Horizontal', 'seo-shutterstock-image-assistant'), value: 'horizontal' }, { label: __('Vertical', 'seo-shutterstock-image-assistant'), value: 'vertical' }, { label: __('Square', 'seo-shutterstock-image-assistant'), value: 'square' }], onChange: function (value) { props.updateSetting('default_orientation', value); } }), h(SelectControl, { label: __('License size', 'seo-shutterstock-image-assistant'), value: settings.license_size || 'huge', options: [{ label: __('Small', 'seo-shutterstock-image-assistant'), value: 'small' }, { label: __('Medium', 'seo-shutterstock-image-assistant'), value: 'medium' }, { label: __('Huge', 'seo-shutterstock-image-assistant'), value: 'huge' }, { label: __('Supersize', 'seo-shutterstock-image-assistant'), value: 'supersize' }, { label: __('Vector', 'seo-shutterstock-image-assistant'), value: 'vector' }], onChange: function (value) { props.updateSetting('license_size', value); } }), h(TextControl, { label: __('Default results count', 'seo-shutterstock-image-assistant'), type: 'number', value: settings.default_results_count || 12, onChange: function (value) { props.updateSetting('default_results_count', Number(value)); } }), h(TextControl, { label: __('Batch size', 'seo-shutterstock-image-assistant'), type: 'number', value: settings.batch_size || 5, onChange: function (value) { props.updateSetting('batch_size', Number(value)); } }), h(TextControl, { label: __('License price', 'seo-shutterstock-image-assistant'), value: settings.license_price || '', onChange: function (value) { props.updateSetting('license_price', value); } }), h(TextControl, { label: __('Customer ID metadata', 'seo-shutterstock-image-assistant'), value: settings.license_customer_id || '', onChange: function (value) { props.updateSetting('license_customer_id', value); } }), h(TextControl, { label: __('Log retention', 'seo-shutterstock-image-assistant'), type: 'number', value: settings.log_retention || 500, onChange: function (value) { props.updateSetting('log_retention', Number(value)); } })), h(CheckboxControl, { key: 'editorial', label: __('Editorial allowed', 'seo-shutterstock-image-assistant'), checked: !!settings.editorial_allowed, onChange: function (value) { props.updateSetting('editorial_allowed', value); } }), h(CheckboxControl, { key: 'safe', label: __('Safe search', 'seo-shutterstock-image-assistant'), checked: !!settings.safe_search, onChange: function (value) { props.updateSetting('safe_search', value); } }), h(CheckboxControl, { key: 'delete', label: __('Delete plugin data on uninstall', 'seo-shutterstock-image-assistant'), checked: !!settings.delete_data_on_uninstall, onChange: function (value) { props.updateSetting('delete_data_on_uninstall', value); } })]); }
		return fieldGroup(__('Permissions', 'seo-shutterstock-image-assistant'), __('Keep licensing restricted to trusted roles. WordPress capabilities still protect every REST action.', 'seo-shutterstock-image-assistant'), [h('ul', { key: 'roles', className: 'ssia-admin__roles' }, h('li', null, 'manage_options'), h('li', null, 'edit_posts'), h('li', null, 'ssia_license_images'))]);
	}


	const root = document.getElementById('ssia-admin-root');
	if (root) { render(h(App), root); }
}(window.wp));
