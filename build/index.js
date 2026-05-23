(function (wp) {
	const apiFetch = wp && wp.apiFetch;
	const components = wp && wp.components;
	const element = wp && wp.element;
	const i18n = wp && wp.i18n;
	if (!apiFetch || !components || !element || !i18n) { return; }

	const { Button, Card, CardBody, Spinner, TabPanel, TextControl } = components;
	const { createElement: h, Fragment, render, useEffect, useRef, useState } = element;
	const { __ } = i18n;
	const config = window.ssiaAdmin || {};
	const apiRoot = config.root || '/wp-json/ssia/v1';
	const SEARCH_BATCH_SIZE = 24;
	const SEARCH_LOAD_MORE_SIZE = 24;
	const SEARCH_RESULT_OPTIONS = [12, 24, 36, 48, 50];
	if (config.nonce && apiFetch.createNonceMiddleware) { apiFetch.use(apiFetch.createNonceMiddleware(config.nonce)); }

	function request(path, options) { return apiFetch(Object.assign({ url: apiRoot + path }, options || {})); }
	function callbackParams() { const p = new URLSearchParams(window.location.search); return { code: p.get('code'), state: p.get('state'), error: p.get('error'), errorDescription: p.get('error_description') }; }
	function isCallback() { const p = callbackParams(); return !!((p.code && p.state) || p.error); }
	function cleanUrl() { return window.location.pathname + '?page=ssia-dashboard#ssia-settings'; }
	function serverCallbackStatus() { const p = new URLSearchParams(window.location.search); return { status: p.get('ssia_oauth_status'), message: p.get('ssia_oauth_message') }; }
	function postOAuth(payload) { if (!window.opener || window.opener.closed) { return false; } window.opener.postMessage(Object.assign({ type: 'ssia_oauth_result' }, payload), window.location.origin); return true; }
	function safeMessage(error, fallback) { return (error && error.message) ? error.message : fallback; }

	function App() {
		const [activeView, setActiveView] = useState('pages');
		const [pages, setPages] = useState([]);
		const [selectedPages, setSelectedPages] = useState([]);
		const [settings, setSettings] = useState({ acf_mapping: {}, acf_image_fields: [] });
		const [filters, setFilters] = useState({ post_type: '', search: '' });
		const [search, setSearch] = useState({ query: '', orientation: 'horizontal', image_type: 'photo', category: '', color: '', safe: true, sort: 'relevance', per_page: SEARCH_BATCH_SIZE, load_more_per_page: SEARCH_LOAD_MORE_SIZE, page: 1 });
		const [results, setResults] = useState([]);
		const [selectedImages, setSelectedImages] = useState([]);
		const [selectedImageItems, setSelectedImageItems] = useState([]);
		const [searchHasMore, setSearchHasMore] = useState(false);
		const [applyResults, setApplyResults] = useState([]);
		const [toasts, setToasts] = useState([]);
		const [loading, setLoading] = useState(false);
		const [loadingLabel, setLoadingLabel] = useState(__('Loading...', 'seo-shutterstock-image-assistant'));
		const shellRef = useRef(null);

		useEffect(function () {
			const node = shellRef.current;
			if (!node) { return; }

			function syncOverlayCenter() {
				const rect = node.getBoundingClientRect();
				node.style.setProperty('--ssia-overlay-center-x', (rect.left + (rect.width / 2)) + 'px');
			}

			syncOverlayCenter();
			window.addEventListener('resize', syncOverlayCenter);
			window.addEventListener('orientationchange', syncOverlayCenter);

			let observer = null;
			if ('ResizeObserver' in window) {
				observer = new ResizeObserver(syncOverlayCenter);
				observer.observe(node);
			}

			return function () {
				window.removeEventListener('resize', syncOverlayCenter);
				window.removeEventListener('orientationchange', syncOverlayCenter);
				if (observer) { observer.disconnect(); }
			};
		}, []);

		function toast(status, message) {
			const id = Date.now() + Math.random();
			setToasts(function (current) { return current.concat({ id: id, status: status || 'info', message: message }); });
			window.setTimeout(function () { setToasts(function (current) { return current.filter(function (item) { return item.id !== id; }); }); }, 4600);
		}

		function loadSettings() { return request('/settings').then(function (r) { setSettings(r || {}); return r || {}; }).catch(function () { return {}; }); }
		function scanPages(showToast) {
			const baseParams = new URLSearchParams();
			baseParams.set('missing', '1');
			baseParams.set('status', 'publish');
			baseParams.set('per_page', '50');
			if (filters.post_type) { baseParams.set('post_type', filters.post_type); }
			if (filters.search) { baseParams.set('search', filters.search); }

			const allItems = [];
			const seen = {};
			const maxPages = 1000;
			let page = 1;

			function fetchPage() {
				const params = new URLSearchParams(baseParams.toString());
				params.set('page', String(page));
				return request('/pages?' + params.toString()).then(function (response) {
					const items = response.items || [];
					items.forEach(function (item) {
						if (item && item.id && !seen[item.id]) {
							seen[item.id] = true;
							allItems.push(item);
						}
					});
					setPages(allItems.slice());
					if (response.has_more === true && page < maxPages) {
						page += 1;
						setLoadingLabel(__('Scanning default-template pages...', 'seo-shutterstock-image-assistant') + ' ' + allItems.length);
						return fetchPage();
					}
					return allItems;
				});
			}

			setLoading(true); setLoadingLabel(__('Scanning default-template pages...', 'seo-shutterstock-image-assistant'));
			return fetchPage().then(function (items) {
				const ids = items.map(function (p) { return p.id; });
				setSelectedPages(function (current) { return current.filter(function (id) { return ids.indexOf(id) !== -1; }); });
				if (showToast !== false) { toast('success', items.length + ' ' + __('default-template published page(s) with empty image slots found.', 'seo-shutterstock-image-assistant')); }
			}).catch(function (e) {
				toast('error', safeMessage(e, __('Page scan failed.', 'seo-shutterstock-image-assistant')));
			}).finally(function () { setLoading(false); });
		}
		function refresh() { return loadSettings().then(function () { return scanPages(false); }); }

		useEffect(function () { if (!isCallback()) { refresh(); } }, []);
		useEffect(function () { const t = window.setTimeout(function () { scanPages(false); }, 350); return function () { window.clearTimeout(t); }; }, [filters.post_type, filters.search]);

		useEffect(function () {
			function handleOAuthMessage(event) {
				if (event.origin !== window.location.origin || !event.data || event.data.type !== 'ssia_oauth_result') { return; }
				toast(event.data.status === 'success' ? 'success' : 'error', event.data.message || __('Shutterstock connection updated.', 'seo-shutterstock-image-assistant'));
				refresh(); setActiveView('settings');
			}
			window.addEventListener('message', handleOAuthMessage);
			return function () { window.removeEventListener('message', handleOAuthMessage); };
		}, []);

		useEffect(function () {
			const params = callbackParams();
			if (!config.canManage || !isCallback()) { return; }
			function finish(payload) { if (postOAuth(payload)) { window.setTimeout(function () { window.close(); }, 250); return true; } return false; }
			if (params.error) { const msg = params.errorDescription || params.error; if (!finish({ status:'error', message: msg })) { toast('error', msg); window.history.replaceState({}, document.title, cleanUrl()); } return; }
			setLoading(true); setLoadingLabel(__('Completing Shutterstock login...', 'seo-shutterstock-image-assistant'));
			request('/oauth-exchange', { method:'POST', data:{ code: params.code, state: params.state } }).then(function (response) {
				const msg = response.message || __('Shutterstock connected.', 'seo-shutterstock-image-assistant');
				if (!finish({ status:'success', message: msg })) { toast('success', msg); window.history.replaceState({}, document.title, cleanUrl()); refresh(); }
			}).catch(function (error) { const msg = safeMessage(error, __('OAuth callback could not be completed.', 'seo-shutterstock-image-assistant')); if (!finish({ status:'error', message: msg })) { toast('error', msg); window.history.replaceState({}, document.title, cleanUrl()); } }).finally(function () { setLoading(false); });
		}, []);

		function togglePage(id) { setSelectedPages(function (current) { return current.indexOf(id) !== -1 ? current.filter(function (x) { return x !== id; }) : current.concat(id); }); }
		function toggleVisiblePages() { const ids = pages.map(function (p) { return p.id; }); const all = ids.length && ids.every(function (id) { return selectedPages.indexOf(id) !== -1; }); setSelectedPages(all ? selectedPages.filter(function (id) { return ids.indexOf(id) === -1; }) : Array.from(new Set(selectedPages.concat(ids)))); }
		function deselectAllPages() { setSelectedPages([]); }
		function selectedPageObjects() { const byId = {}; pages.forEach(function (page) { byId[page.id] = page; }); return selectedPages.map(function (id) { return byId[id]; }).filter(Boolean); }
		function missingSlotsFor(page) { return Array.isArray(page && page.missing_slots) ? page.missing_slots : []; }
		function requiredImageCount() { return selectedPageObjects().reduce(function (sum, page) { return sum + missingSlotsFor(page).length; }, 0); }
		function imageTitle(item) { const raw = String((item && (item.title || item.description || item.alt || item.id)) || __('Shutterstock image', 'seo-shutterstock-image-assistant')).trim(); return raw.length > 55 ? raw.slice(0, 52).replace(/\s+\S*$/, '') + '...' : raw; }
		function selectedImageObjects() { const byId = {}; results.forEach(function (item) { if (item && item.id) { byId[item.id] = item; } }); selectedImageItems.forEach(function (item) { if (item && item.id && !byId[item.id]) { byId[item.id] = item; } }); return selectedImages.map(function (id) { return byId[id] || { id: id, description: 'ID ' + id }; }); }
		function availableImageObjects() { const picked = selectedImageObjects(); const seen = {}; picked.forEach(function (item) { if (item && item.id) { seen[item.id] = true; } }); results.forEach(function (item) { if (item && item.id && !seen[item.id]) { seen[item.id] = true; picked.push(item); } }); return picked; }
		function placementPlan() { const images = availableImageObjects(); let cursor = 0; return selectedPageObjects().map(function (page) { const slots = missingSlotsFor(page); const assignments = slots.map(function (slot) { const image = images[cursor] || null; cursor += 1; return { slot: slot, image: image }; }); return { page: page, assignments: assignments }; }); }
		function toggleImage(item) { const id = item && item.id ? item.id : String(item || ''); if (!id) { return; } setSelectedImages(function (current) { return current.indexOf(id) !== -1 ? current.filter(function (x) { return x !== id; }) : current.concat(id); }); setSelectedImageItems(function (current) { const exists = current.some(function (entry) { return entry && entry.id === id; }); if (exists) { return current.filter(function (entry) { return !entry || entry.id !== id; }); } return current.concat(item && item.id ? item : { id: id }); }); }
		function resultCount(key, fallback) {
			const value = Number(search[key] || fallback);
			return SEARCH_RESULT_OPTIONS.indexOf(value) !== -1 ? value : fallback;
		}
		function runSearch() {
			if (!search.query.trim()) { toast('warning', __('Type a keyword first.', 'seo-shutterstock-image-assistant')); return; }
			if (!selectedPages.length) { toast('warning', __('Select at least one published page first.', 'seo-shutterstock-image-assistant')); return; }
			const perPage = resultCount('per_page', SEARCH_BATCH_SIZE);
			const loadMorePerPage = resultCount('load_more_per_page', SEARCH_LOAD_MORE_SIZE);
			const payload = Object.assign({}, search, { page: 1, per_page: perPage, load_more_per_page: loadMorePerPage });
			setLoading(true); setLoadingLabel(__('Searching Shutterstock...', 'seo-shutterstock-image-assistant'));
			request('/search', { method:'POST', data: payload }).then(function (response) { const items = response.items || []; setSearch(Object.assign({}, search, { page: 1, per_page: perPage, load_more_per_page: loadMorePerPage })); setResults(items); setSelectedImages([]); setSelectedImageItems([]); setSearchHasMore(items.length >= perPage); toast('success', items.length + ' ' + __('image result(s) loaded.', 'seo-shutterstock-image-assistant')); setActiveView('search'); }).catch(function (error) { toast('error', safeMessage(error, __('Search failed.', 'seo-shutterstock-image-assistant'))); }).finally(function () { setLoading(false); });
		}
		function loadMoreImages() {
			if (!search.query.trim()) { toast('warning', __('Type a keyword first.', 'seo-shutterstock-image-assistant')); return; }
			const perPage = resultCount('load_more_per_page', SEARCH_LOAD_MORE_SIZE);
			const firstPage = Math.min(100, Math.max(1, Math.floor((results || []).length / perPage) + 1));
			const seen = {};
			(results || []).forEach(function (item) { if (item && item.id) { seen[item.id] = true; } });
			const found = [];
			let lastPage = firstPage;

			function fetchMore(page) {
				lastPage = page;
				return request('/search', { method:'POST', data: Object.assign({}, search, { page: page, per_page: perPage }) }).then(function (response) {
					const incoming = response.items || [];
					incoming.forEach(function (item) { if (item && item.id && !seen[item.id] && found.length < perPage) { seen[item.id] = true; found.push(item); } });
					if (incoming.length >= perPage && found.length < perPage && page < 100) { return fetchMore(page + 1); }
					return incoming.length >= perPage;
				});
			}

			setLoading(true); setLoadingLabel(__('Loading more images...', 'seo-shutterstock-image-assistant'));
			fetchMore(firstPage).then(function (hasMore) { setSearch(Object.assign({}, search, { page: lastPage, load_more_per_page: perPage })); setResults(function (current) { return current.concat(found); }); setSearchHasMore(!!hasMore); toast('success', found.length + ' ' + __('more image result(s) loaded.', 'seo-shutterstock-image-assistant')); }).catch(function (error) { toast('error', safeMessage(error, __('Could not load more images.', 'seo-shutterstock-image-assistant'))); }).finally(function () { setLoading(false); });
		}
		function applyImages() {
			const required = requiredImageCount();
			if (!selectedPages.length) { toast('warning', __('Select at least one page first.', 'seo-shutterstock-image-assistant')); return; }
			if (!required) { toast('warning', __('Selected pages have no missing image slots.', 'seo-shutterstock-image-assistant')); return; }
			if (availableImageObjects().length < required) { toast('warning', __('Load more images before continuing.', 'seo-shutterstock-image-assistant') + ' ' + availableImageObjects().length + '/' + required); return; }
			setLoading(true); setLoadingLabel(__('Licensing, downloading and replacing images...', 'seo-shutterstock-image-assistant'));
			const todo = placementPlan().map(function (entry) { return { post_id: entry.page.id, title: entry.page.title, image_ids: entry.assignments.map(function (assignment) { return assignment.image && assignment.image.id; }).filter(Boolean), assignments: entry.assignments }; }).filter(function (entry) { return entry.image_ids.length; });
			const out = [];
			function next() {
				const entry = todo.shift();
				if (!entry) { setApplyResults(out); setActiveView('result'); setLoading(false); toast('success', __('Placement run finished.', 'seo-shutterstock-image-assistant')); scanPages(false); return; }
				request('/license-attach', { method:'POST', data:{ post_id: entry.post_id, image_ids: entry.image_ids } }).then(function (r) { out.push({ post_id: entry.post_id, status: 'success', message: r.message || __('Images placed.', 'seo-shutterstock-image-assistant'), attachments: r.attachments || [], assignments: entry.assignments }); }).catch(function (e) { out.push({ post_id: entry.post_id, status: 'failed', message: safeMessage(e, __('Failed.', 'seo-shutterstock-image-assistant')), assignments: entry.assignments }); }).then(next, next);
			}
			next();
		}

		function setFeaturedFromAcf(postIds) {
			const ids = Array.from(new Set((postIds || []).filter(Boolean)));
			if (!ids.length) { toast('warning', __('No eligible pages found.', 'seo-shutterstock-image-assistant')); return; }
			setLoading(true); setLoadingLabel(__('Setting featured images from ACF...', 'seo-shutterstock-image-assistant') + ' 0/' + ids.length);
			const queue = ids.slice();
			const results = [];
			function runChunk() {
				const chunk = queue.splice(0, 50);
				if (!chunk.length) {
					const setCount = results.filter(function (item) { return item.status === 'set'; }).length;
					toast(setCount ? 'success' : 'warning', setCount + ' ' + __('featured image(s) set from first ACF image.', 'seo-shutterstock-image-assistant'));
					return scanPages(false);
				}
				setLoadingLabel(__('Setting featured images from ACF...', 'seo-shutterstock-image-assistant') + ' ' + (ids.length - queue.length - chunk.length) + '/' + ids.length);
				return request('/featured-from-acf', { method:'POST', data:{ post_ids: chunk } }).then(function (response) {
					(response.results || []).forEach(function (item) { results.push(item); });
					setLoadingLabel(__('Setting featured images from ACF...', 'seo-shutterstock-image-assistant') + ' ' + (ids.length - queue.length) + '/' + ids.length);
					return runChunk();
				});
			}
			runChunk().catch(function (error) {
				toast('error', safeMessage(error, __('Featured image update failed.', 'seo-shutterstock-image-assistant')));
			}).finally(function () { setLoading(false); });
		}
		function updateSetting(key, value) { setSettings(function (current) { return Object.assign({}, current, { [key]: value }); }); }
		function updateMapping(key, value) { setSettings(function (current) { return Object.assign({}, current, { acf_mapping: Object.assign({}, current.acf_mapping || {}, { [key]: value }) }); }); }
		function saveSettings() { setLoading(true); setLoadingLabel(__('Saving settings...', 'seo-shutterstock-image-assistant')); return request('/settings', { method:'POST', data: settings }).then(function (r) { setSettings(r || {}); toast('success', __('Settings saved.', 'seo-shutterstock-image-assistant')); scanPages(false); return r || {}; }).catch(function (e) { toast('error', safeMessage(e, __('Settings could not be saved.', 'seo-shutterstock-image-assistant'))); throw e; }).finally(function () { setLoading(false); }); }
		function testConnection() {
			setLoading(true); setLoadingLabel(__('Testing Shutterstock connection...', 'seo-shutterstock-image-assistant'));
			return request('/settings', { method:'POST', data: settings }).then(function (saved) {
				setSettings(saved || {});
				return request('/test-connection', { method:'POST' });
			}).then(function (response) {
				if (response.subscription_id) {
					setSettings(function (current) { return Object.assign({}, current, { subscription_id: response.subscription_id }); });
				}
				toast(response.connected ? 'success' : 'warning', response.subscription_id ? (response.message + ' ' + __('Subscription ID detected:', 'seo-shutterstock-image-assistant') + ' ' + response.subscription_id) : (response.message || __('Connection test completed.', 'seo-shutterstock-image-assistant')));
				return response;
			}).catch(function (error) {
				toast('error', safeMessage(error, __('Connection test failed.', 'seo-shutterstock-image-assistant')));
				throw error;
			}).finally(function () { setLoading(false); });
		}
		function connectOAuth() {
			const popup = window.open('about:blank', 'ssiaShutterstockOAuth', 'popup=yes,width=720,height=760,resizable=yes,scrollbars=yes');
			if (!popup) { toast('error', __('Popup was blocked. Allow popups and try again.', 'seo-shutterstock-image-assistant')); return; }
			try { popup.document.body.textContent = 'Saving settings and opening Shutterstock...'; } catch(e) {}
			request('/settings', { method:'POST', data: settings }).then(function (saved) { setSettings(saved || {}); return request('/oauth-url', { method:'POST' }); }).then(function (response) { if (response.url) { popup.location.href = response.url; } else { popup.close(); toast('error', __('OAuth URL could not be created.', 'seo-shutterstock-image-assistant')); } }).catch(function (error) { popup.close(); toast('error', safeMessage(error, __('Login could not be started.', 'seo-shutterstock-image-assistant'))); });
		}

		const selectedPageData = selectedPageObjects();
		const requiredImages = requiredImageCount();
		const currentPlacementPlan = placementPlan();

		return h('div', { className:'ssia-admin ssia-admin--clean' }, h('div', { className:'ssia-admin__shell', ref:shellRef },
			loading ? h('div', { className:'ssia-admin__loading' }, h(Spinner), h('span', null, loadingLabel)) : null,
			h(ToastStack, { toasts: toasts }),
			stepper(activeView, setActiveView),
			activeView === 'pages' ? h(PagesStep, { pages:pages, selectedPages:selectedPages, togglePage:togglePage, toggleVisiblePages:toggleVisiblePages, deselectAllPages:deselectAllPages, scanPages:scanPages, setActiveView:setActiveView, setFeaturedFromAcf:setFeaturedFromAcf }) : null,
			activeView === 'search' ? h(SearchStep, { search:search, setSearch:setSearch, results:results, selectedImages:selectedImages, selectedImageItems:selectedImageItems, toggleImage:toggleImage, runSearch:runSearch, loadMoreImages:loadMoreImages, searchHasMore:searchHasMore, applyImages:applyImages, selectedPages:selectedPages, selectedPageData:selectedPageData, requiredImages:requiredImages, availableImages:availableImageObjects().length, imageTitle:imageTitle, setActiveView:setActiveView }) : null,
			activeView === 'result' ? h(ResultStep, { applyResults:applyResults, pages:pages, imageTitle:imageTitle, setActiveView:setActiveView }) : null,
			activeView === 'settings' && config.canManage ? h(SettingsPanel, { settings:settings, updateSetting:updateSetting, updateMapping:updateMapping, saveSettings:saveSettings, connectOAuth:connectOAuth, testConnection:testConnection }) : null
		));
	}

	function ToastStack(p) { return h('div', { className:'ssia-admin__toast-stack', role:'status', 'aria-live':'polite' }, (p.toasts || []).map(function (t) { return h('div', { key:t.id, className:'ssia-admin__toast is-' + t.status }, t.message); })); }
	function stepper(active,setActive) { const items=[{key:'pages',label:__('Pages','seo-shutterstock-image-assistant')},{key:'search',label:__('Search','seo-shutterstock-image-assistant')},{key:'result',label:__('Result','seo-shutterstock-image-assistant')},config.canManage?{key:'settings',label:__('Settings','seo-shutterstock-image-assistant')}:null].filter(Boolean); return h('nav',{className:'ssia-admin__step-tabs'},items.map(function(i,index){return h('button',{type:'button',key:i.key,className:active===i.key?'is-active':'',onClick:function(){setActive(i.key);}},h('span',null,index+1),i.label);})); }
	function field(label, child) { return h('label', { className:'ssia-admin__field' }, h('span', null, label), child); }

	function PagesStep(p){ return h(Card,{className:'ssia-admin__card'},h(CardBody,null,
		h('div',{className:'ssia-admin__scan-panel'},
			h('div',{className:'ssia-admin__scan-content'},
				h('span',{className:'ssia-admin__scan-kicker'},__('Step 1','seo-shutterstock-image-assistant')),
				h('strong',null,__('Scan default-template pages','seo-shutterstock-image-assistant')),
				h('span',{className:'ssia-admin__scan-description'},__('Find default-template pages with empty featured or ACF image fields. Elementor Canvas, Elementor Full Width and custom templates are ignored.','seo-shutterstock-image-assistant'))
			),
			h(Button,{variant:'primary',className:'ssia-admin__scan-button',onClick:function(){p.scanPages(true);}},__('Scan pages','seo-shutterstock-image-assistant'))
		),
		h('div',{className:'ssia-admin__action-bar'},h('strong',null,p.pages.length+' '+__('default-template published page(s) with missing image slots found','seo-shutterstock-image-assistant')),h('div',{className:'ssia-admin__button-row'},h(Button,{variant:'secondary',onClick:p.toggleVisiblePages},__('Select visible','seo-shutterstock-image-assistant')),h(Button,{variant:'secondary',disabled:!p.selectedPages.length,onClick:p.deselectAllPages},__('Deselect all','seo-shutterstock-image-assistant')),h(Button,{variant:'secondary',disabled:!p.pages.some(function(page){return !!page.can_featured_from_acf;}),onClick:function(){p.setFeaturedFromAcf(p.pages.filter(function(page){return !!page.can_featured_from_acf;}).map(function(page){return page.id;}));}},__('Set featured from ACF','seo-shutterstock-image-assistant')),h(Button,{variant:'primary',disabled:!p.selectedPages.length,onClick:function(){p.setActiveView('search');}},__('Next: search images','seo-shutterstock-image-assistant')))),
		p.pages.length?h('div',{className:'ssia-admin__page-list'},p.pages.map(function(page){const selected=p.selectedPages.indexOf(page.id)!==-1;return h('div',{key:page.id,className:selected?'ssia-admin__page-row is-selected':'ssia-admin__page-row'},h('button',{type:'button',className:'ssia-admin__page-select',onClick:function(){p.togglePage(page.id);}},h('span',{className:'ssia-admin__row-check'},selected?'✓':''),h('span',{className:'ssia-admin__row-main'},h('strong',null,page.title||('#'+page.id)),h('small',null,page.post_type+' · published'))),h('span',{className:'ssia-admin__slots'},(function(){const missing=page.missing_slots||[]; const labels={featured_image:'featured image',image_1:'image 1',image_2:'image 2',image_3:'image 3'}; return ['featured_image','image_1','image_2','image_3'].map(function(slot){const isMissing=missing.indexOf(slot)!==-1; return h('em',{key:slot,className:isMissing?'':'is-placeholder','aria-hidden':isMissing?undefined:'true'},labels[slot]||slot.replace('_',' '));});})()),h('span',{className:'ssia-admin__row-actions'},page.can_featured_from_acf?h(Button,{variant:'secondary',onClick:function(){p.setFeaturedFromAcf([page.id]);}},__('Use first ACF as featured','seo-shutterstock-image-assistant')):null,page.edit_link?h('a',{href:page.edit_link,target:'_blank',rel:'noreferrer'},__('Edit','seo-shutterstock-image-assistant')):null));})):empty(__('No empty image slots found','seo-shutterstock-image-assistant'),__('Run a scan. Elementor/custom-template pages are ignored.','seo-shutterstock-image-assistant'))
	)); }

	function SearchStep(p){
		const required = Number(p.requiredImages || 0);
		const selectedCount = (p.selectedImages || []).length;
		const availableCount = Number(p.availableImages || 0);
		const remaining = Math.max(0, required - availableCount);
		const enough = required > 0 && availableCount >= required;
		const autoFill = Math.max(0, Math.min(required, availableCount) - selectedCount);
		const extra = Math.max(0, availableCount - required);
		const summaryRef = useRef(null);
		const [showFloating, setShowFloating] = useState(false);
		useEffect(function () {
			const node = summaryRef.current;
			if (!node || !('IntersectionObserver' in window)) { setShowFloating(false); return; }
			const observer = new IntersectionObserver(function (entries) {
				const entry = entries[0];
				setShowFloating(!entry.isIntersecting);
			}, { threshold: 0.1 });
			observer.observe(node);
			return function () { observer.disconnect(); };
		}, []);
		return h(Card,{className:'ssia-admin__card'},h(CardBody,null,
			h('div',{className:'ssia-admin__section-head'},h('h2',null,__('Search Shutterstock','seo-shutterstock-image-assistant')),h('p',null,(p.selectedPages||[]).length+' '+__('selected published page(s). Selected images are used first; otherwise the loaded results are used in order.','seo-shutterstock-image-assistant'))),
			h('div',{className:'ssia-admin__selection-summary',ref:summaryRef},
				h('span',null,__('Selected pages','seo-shutterstock-image-assistant')+': '+(p.selectedPages||[]).length),
				h('span',null,__('Missing image slots','seo-shutterstock-image-assistant')+': '+required),
				h('strong',{className:enough?'is-complete':'is-needed'},__('Loaded images','seo-shutterstock-image-assistant')+': '+availableCount),
				h('strong',{className:enough?'is-complete':'is-needed'},__('Needed images','seo-shutterstock-image-assistant')+': '+required),
				remaining?h('em',null,__('Load','seo-shutterstock-image-assistant')+' '+remaining+' '+__('more image(s).','seo-shutterstock-image-assistant')):autoFill?h('em',null,autoFill+' '+__('image(s) will be chosen automatically.','seo-shutterstock-image-assistant')):extra?h('em',null,extra+' '+__('extra image(s) will not be used.','seo-shutterstock-image-assistant')):h('em',null,__('Ready to download and replace.','seo-shutterstock-image-assistant'))
			),
			h('div',{className:'ssia-admin__search-box'},h('div',{className:'ssia-admin__search-input-wrap'},h('label',{className:'screen-reader-text',htmlFor:'ssia-image-search-keyword'},__('Keyword','seo-shutterstock-image-assistant')),h('input',{id:'ssia-image-search-keyword',className:'ssia-admin__search-input',type:'search',value:p.search.query,placeholder:__('Search Shutterstock images','seo-shutterstock-image-assistant'),onChange:function(e){p.setSearch(Object.assign({},p.search,{query:e.target.value}));},onKeyDown:function(e){if(e.key==='Enter'){p.runSearch();}}})),h(Button,{variant:'primary',className:'ssia-admin__search-submit',onClick:p.runSearch},__('Search','seo-shutterstock-image-assistant'))),
			h('div',{className:'ssia-admin__filter-grid'},select('orientation',__('Orientation','seo-shutterstock-image-assistant'),p,[['horizontal','Horizontal'],['vertical','Vertical'],['square','Square']]),select('image_type',__('Image type','seo-shutterstock-image-assistant'),p,[['photo','Photo'],['illustration','Illustration'],['vector','Vector']]),select('sort',__('Sort','seo-shutterstock-image-assistant'),p,[['relevance','Relevance'],['popular','Popular'],['newest','Newest']]),imageBatchField(p)),

			h('div',{className:'ssia-admin__action-bar'},h('strong',null,availableCount+' '+__('loaded image(s)','seo-shutterstock-image-assistant')+' · '+required+' '+__('needed image(s)','seo-shutterstock-image-assistant')),h('div',{className:'ssia-admin__button-row'},h(Button,{variant:'secondary',onClick:function(){p.setActiveView('pages');}},__('Back','seo-shutterstock-image-assistant')),h(Button,{variant:'primary',disabled:!enough,onClick:p.applyImages},__('Download and replace','seo-shutterstock-image-assistant')))),
			p.results.length?h(Fragment,null,h('p',{className:'ssia-admin__search-note'},__('Already-used Media Library images are hidden. Selected images are used first; unselected loaded results fill the remaining slots automatically.','seo-shutterstock-image-assistant')),h('div',{className:'ssia-admin__image-grid'},p.results.map(function(item){const selected=p.selectedImages.indexOf(item.id)!==-1;return h('button',{type:'button',key:item.id,className:selected?'ssia-admin__image-card is-selected':'ssia-admin__image-card',onClick:function(){p.toggleImage(item);},'aria-pressed':selected?'true':'false','aria-label':(selected?__('Deselect image','seo-shutterstock-image-assistant'):__('Select image','seo-shutterstock-image-assistant'))+' '+p.imageTitle(item)},selected?h('span',{className:'ssia-admin__image-check','aria-hidden':'true'},'✓'):null,h('span',{className:'ssia-admin__image-frame'},h('img',{src:item.thumbnail,alt:''})),h('span',{className:'ssia-admin__image-copy'},h('strong',null,p.imageTitle(item))));})),h('div',{className:'ssia-admin__load-more-row'},h(Button,{variant:'secondary',disabled:!p.searchHasMore,onClick:p.loadMoreImages},p.searchHasMore?__('Load more images','seo-shutterstock-image-assistant'):__('No more images','seo-shutterstock-image-assistant')))):empty(__('No results yet','seo-shutterstock-image-assistant'),__('Enter a keyword and click Search.','seo-shutterstock-image-assistant')),
			availableCount > 0 && showFloating ? h('div',{className:'ssia-admin__floating-selection',role:'status','aria-live':'polite'},h('strong',{className:enough?'is-complete':'is-needed'},__('Loaded images','seo-shutterstock-image-assistant')+': '+availableCount),h('span',null,required+' '+__('needed image(s)','seo-shutterstock-image-assistant')+'. '+(remaining?__('Load','seo-shutterstock-image-assistant')+' '+remaining+' '+__('more image(s).','seo-shutterstock-image-assistant'):autoFill?autoFill+' '+__('image(s) will be chosen automatically.','seo-shutterstock-image-assistant'):extra?extra+' '+__('extra image(s) will not be used.','seo-shutterstock-image-assistant'):__('Ready to download and replace.','seo-shutterstock-image-assistant')))):null
		)); }

	function nativeSelect(value, opts, onChange) { return h('select', { value: String(value == null ? '' : value), onChange: function(e) { onChange(e.target.value); } }, opts.map(function(o) { return h('option', { key: String(o[0]), value: String(o[0]) }, String(o[1])); })); }
	function select(key,label,p,opts){ return field(label,nativeSelect(p.search[key], opts, function(v){const next=Object.assign({},p.search); next[key]=(key==='per_page'||key==='load_more_per_page')?Number(v):v; p.setSearch(next);})); }
	function imageBatchField(p){ const opts=SEARCH_RESULT_OPTIONS.map(function(n){return[n,String(n)];}); return h('div',{className:'ssia-admin__field ssia-admin__field--batch'},h('span',null,__('Image batch size','seo-shutterstock-image-assistant')),h('div',{className:'ssia-admin__batch-selects'},h('label',{className:'ssia-admin__mini-field'},h('span',null,__('After search','seo-shutterstock-image-assistant')),nativeSelect(p.search.per_page,opts,function(v){const next=Object.assign({},p.search); next.per_page=Number(v); p.setSearch(next);})),h('label',{className:'ssia-admin__mini-field'},h('span',null,__('Load more','seo-shutterstock-image-assistant')),nativeSelect(p.search.load_more_per_page,opts,function(v){const next=Object.assign({},p.search); next.load_more_per_page=Number(v); p.setSearch(next);})))) }
	function slotLabel(slot){ const labels={featured_image:__('Featured image','seo-shutterstock-image-assistant'),image_1:__('Image 1','seo-shutterstock-image-assistant'),image_2:__('Image 2','seo-shutterstock-image-assistant'),image_3:__('Image 3','seo-shutterstock-image-assistant')}; return labels[slot] || String(slot || '').replace(/_/g,' '); }
	function ResultStep(p){ const byId={}; (p.pages||[]).forEach(function(page){byId[page.id]=page;}); return h(Card,{className:'ssia-admin__card'},h(CardBody,null,h('div',{className:'ssia-admin__section-head'},h('h2',null,__('Result','seo-shutterstock-image-assistant')),h('p',null,__('Placement status per page.','seo-shutterstock-image-assistant'))),p.applyResults.length?h('div',{className:'ssia-admin__result-list'},p.applyResults.map(function(r){const page=byId[r.post_id]||{};return h('div',{key:r.post_id,className:'ssia-admin__result-row is-'+r.status},h('strong',null,page.title||('#'+r.post_id)),h('span',null,r.status),h('small',null,r.message),r.assignments&&r.assignments.length?h('small',null,r.assignments.map(function(a){return slotLabel(a.slot)+' → '+(a.image?p.imageTitle(a.image):__('missing','seo-shutterstock-image-assistant'));}).join(' · ')):null,r.attachments&&r.attachments.length?h('small',null,__('Attachments: ','seo-shutterstock-image-assistant')+r.attachments.join(', ')):null);})):h('div',{className:'ssia-admin__notice-clean'},__('Results will appear here after images are placed.','seo-shutterstock-image-assistant')))); }

	function SettingsPanel(p){ const s=p.settings||{}; const m=s.acf_mapping||{}; return h(Card,{className:'ssia-admin__card ssia-admin__settings-card'},h(CardBody,null,h('div',{className:'ssia-admin__section-head ssia-admin__settings-head'},h('div',null,h('h2',null,__('Settings','seo-shutterstock-image-assistant')),null),h(Button,{variant:'primary',onClick:p.saveSettings},__('Save settings','seo-shutterstock-image-assistant'))),h(TabPanel,{className:'ssia-admin__tabs',tabs:[{name:'acf',title:__('ACF fields','seo-shutterstock-image-assistant')},{name:'defaults',title:__('Defaults','seo-shutterstock-image-assistant')},{name:'connection',title:__('Connection','seo-shutterstock-image-assistant')}]},function(active){return h('div',{className:'ssia-admin__tab-panel'},settingsTab(active.name,s,m,p));}))); }
	function settingsTab(name,s,m,p){ if(name==='connection'){return h('div',{className:'ssia-admin__connection-panel'},h('div',{className:'ssia-admin__connection-header'},h('div',null,h('strong',null,__('Shutterstock connection','seo-shutterstock-image-assistant')),h('p',null,__('Save your credentials, then test the connection.','seo-shutterstock-image-assistant'))),h('span',{className:'ssia-admin__status '+(s.connected?'is-success':'is-warning')},s.connected?__('Connected','seo-shutterstock-image-assistant'):__('Not connected','seo-shutterstock-image-assistant'))),h('div',{className:'ssia-admin__connection-grid'},field(__('Callback URL','seo-shutterstock-image-assistant'),h(TextControl,{hideLabelFromVision:true,value:s.oauth_redirect_uri||'',placeholder:window.location.origin+'/wp-admin/admin.php',onChange:function(v){p.updateSetting('oauth_redirect_uri',v);}})),field(__('Consumer key','seo-shutterstock-image-assistant'),h(TextControl,{hideLabelFromVision:true,value:s.oauth_client_id||'',autoComplete:'off',onChange:function(v){p.updateSetting('oauth_client_id',v);}})),field(__('Consumer secret','seo-shutterstock-image-assistant'),h(TextControl,{hideLabelFromVision:true,type:'password',value:s.oauth_client_secret||'',autoComplete:'new-password',onChange:function(v){p.updateSetting('oauth_client_secret',v);}})),field(__('Access token','seo-shutterstock-image-assistant'),h('textarea',{className:'ssia-admin__textarea',value:s.access_token||'',rows:4,placeholder:__('Paste an existing access token here or connect via OAuth.','seo-shutterstock-image-assistant'),onChange:function(e){p.updateSetting('access_token',e.target.value);}})),field(__('Shutterstock subscription ID','seo-shutterstock-image-assistant'),h(TextControl,{hideLabelFromVision:true,value:s.subscription_id||'',placeholder:__('Leave empty for auto-detection, or paste your corporate/team subscription ID.','seo-shutterstock-image-assistant'),autoComplete:'off',onChange:function(v){p.updateSetting('subscription_id',v);}}))),h('div',{className:'ssia-admin__connection-actions'},h(Button,{variant:'primary',onClick:p.saveSettings},__('Save settings','seo-shutterstock-image-assistant')),h(Button,{variant:'secondary',onClick:p.connectOAuth},s.connected?__('Reconnect Shutterstock','seo-shutterstock-image-assistant'):__('Login with Shutterstock','seo-shutterstock-image-assistant')),h(Button,{variant:'secondary',onClick:p.testConnection},__('Test connection','seo-shutterstock-image-assistant')))); } if(name==='acf'){const options=[{label:__('Choose automatically detected field','seo-shutterstock-image-assistant'),value:''}].concat((s.acf_image_fields||[]).map(function(f){return{label:f.label+' ('+f.value+')',value:f.value};}));return h('div',{className:'ssia-admin__acf-panel'},h('div',{className:'ssia-admin__acf-intro'},h('div',null,h('strong',null,__('ACF image mapping','seo-shutterstock-image-assistant')),null)),h('div',{className:'ssia-admin__acf-grid ssia-admin__settings-grid-clean'},['image_1','image_2','image_3'].map(function(slot,index){const label='Image '+String(index+1);return field(label,nativeSelect(m[slot]||'',options.map(function(option){return[option.value,option.label];}),function(v){p.updateMapping(slot,v);}));}))); } return h('div',{className:'ssia-admin__settings-grid-clean'},field(__('Default orientation','seo-shutterstock-image-assistant'),nativeSelect(s.default_orientation||'horizontal',[['horizontal','Horizontal'],['vertical','Vertical'],['square','Square']],function(v){p.updateSetting('default_orientation',v);})),field(__('Default results count','seo-shutterstock-image-assistant'),nativeSelect(String(s.default_results_count||24),[12,24,48,50].map(function(n){return[n,String(n)];}),function(v){p.updateSetting('default_results_count',Number(v));})),field(__('License size','seo-shutterstock-image-assistant'),nativeSelect(s.license_size||'huge',[['small','Small'],['medium','Medium'],['huge','Huge'],['supersize','Supersize']],function(v){p.updateSetting('license_size',v);})),field(__('Safe search','seo-shutterstock-image-assistant'),h('label',{className:'ssia-admin__safe-toggle'},h('input',{type:'checkbox',checked:!!s.safe_search,onChange:function(e){p.updateSetting('safe_search',e.target.checked);}}),h('span',{className:'ssia-admin__switch','aria-hidden':'true'},h('span',{className:'ssia-admin__switch-knob'})),h('span',{className:'ssia-admin__switch-text'},s.safe_search?__('Enabled','seo-shutterstock-image-assistant'):__('Disabled','seo-shutterstock-image-assistant'))))); }
	function empty(title,text){return h('div',{className:'ssia-admin__empty'},h('strong',null,title),h('p',null,text));}
	const root=document.getElementById('ssia-admin-root'); if(root){render(h(App),root);}
}(window.wp));
