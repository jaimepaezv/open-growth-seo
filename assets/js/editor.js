(function (wp) {
	'use strict';

	if (!wp) {
		return;
	}

	const localized = window.ogsSeoEditor || {};
	const __ = wp.i18n ? wp.i18n.__ : function (value) {
		return value;
	};

	const bindClassicSocialImagePicker = function () {
		if (!wp.media) {
			return;
		}

		const input = document.querySelector('.ogs-social-image-url-field');
		const selectButton = document.querySelector('.ogs-select-social-image');
		const removeButton = document.querySelector('.ogs-remove-social-image');
		const previewWrap = document.querySelector('.ogs-social-image-preview');
		const previewImage = previewWrap ? previewWrap.querySelector('img') : null;

		if (!input || !selectButton || !removeButton || !previewWrap || !previewImage) {
			return;
		}

		let frame;
		const selectText = localized.selectImageText || __('Select from Media Library', 'open-growth-seo');
		const replaceText = localized.replaceImageText || __('Replace image', 'open-growth-seo');

		const syncPreview = function (value) {
			const imageUrl = (value || '').toString().trim();
			previewImage.src = imageUrl;
			previewWrap.classList.toggle('ogs-is-hidden', !imageUrl);
			removeButton.classList.toggle('ogs-is-hidden', !imageUrl);
			selectButton.textContent = imageUrl ? replaceText : selectText;
		};

		selectButton.addEventListener('click', function (event) {
			event.preventDefault();

			if (!frame) {
				frame = wp.media({
					title: localized.mediaFrameTitle || __('Select social image', 'open-growth-seo'),
					button: {
						text: localized.mediaFrameButton || __('Use image URL', 'open-growth-seo')
					},
					library: {
						type: 'image'
					},
					multiple: false
				});

				frame.on('select', function () {
					const selection = frame.state().get('selection').first();
					const attachment = selection ? selection.toJSON() : null;
					if (!attachment || !attachment.url) {
						return;
					}

					input.value = attachment.url;
					input.dispatchEvent(new Event('input', { bubbles: true }));
					input.dispatchEvent(new Event('change', { bubbles: true }));
					syncPreview(attachment.url);
				});
			}

			frame.open();
		});

		removeButton.addEventListener('click', function (event) {
			event.preventDefault();
			input.value = '';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			input.dispatchEvent(new Event('change', { bubbles: true }));
			syncPreview('');
		});

		input.addEventListener('input', function () {
			syncPreview(input.value);
		});

		syncPreview(input.value);
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindClassicSocialImagePicker);
	} else {
		bindClassicSocialImagePicker();
	}

	if (!wp.plugins) {
		return;
	}

	const el = wp.element.createElement;
	const useEffect = wp.element.useEffect;
	const useRef = wp.element.useRef;
	const useState = wp.element.useState;
	const registerPlugin = wp.plugins.registerPlugin;
	const PluginDocumentSettingPanel = wp.editor ? wp.editor.PluginDocumentSettingPanel : wp.editPost.PluginDocumentSettingPanel;
	const TextControl = wp.components.TextControl;
	const TextareaControl = wp.components.TextareaControl;
	const SelectControl = wp.components.SelectControl;
	const Notice = wp.components.Notice;
	const Button = wp.components.Button;
	const Flex = wp.components.Flex;
	const FlexItem = wp.components.FlexItem;
	const PanelBody = wp.components.PanelBody;
	const useSelect = wp.data.useSelect;
	const useDispatch = wp.data.useDispatch;
	const MediaUpload = wp.blockEditor ? wp.blockEditor.MediaUpload : null;
	const MediaUploadCheck = wp.blockEditor ? wp.blockEditor.MediaUploadCheck : null;
	const apiFetch = wp.apiFetch || null;
	const simpleMode = (localized.editorMode || 'simple') === 'simple';
	const sectionDefaults = localized.editorSections || {};

	const trimForPreview = function (value, max) {
		const text = (value || '').toString().replace(/\s+/g, ' ').trim();
		if (text.length <= max) {
			return text;
		}
		return text.slice(0, Math.max(0, max - 3)).trim() + '...';
	};

	const suggestionConfidenceLabel = function (value) {
		const confidence = (value || '').toString().trim().toLowerCase();
		if (confidence === 'high') {
			return __('High confidence', 'open-growth-seo');
		}
		if (confidence === 'medium') {
			return __('Medium confidence', 'open-growth-seo');
		}
		return __('Low confidence', 'open-growth-seo');
	};

	const renderSocialPreviewImage = function (imageUrl) {
		const value = (imageUrl || '').toString().trim();
		if (!value) {
			return el('div', { className: 'ogs-social-image' }, el('span', null, __('Image optional', 'open-growth-seo')));
		}

		return el(
			'div',
			{ className: 'ogs-social-image' },
			el('img', {
				src: value,
				alt: localized.mediaPreviewAlt || __('Selected social image preview', 'open-growth-seo')
			})
		);
	};

	const analyze = function (content, postTitle, focusKeyphrase, cornerstone) {
		const plain = (content || '').replace(/<[^>]*>/g, ' ').trim();
		const intro = plain.slice(0, 420);
		const checks = [];
		const recs = [];
		const intents = [];
		if (/\b(what is|definition|overview)\b/i.test(plain)) intents.push('what');
		if (/\b(how to|step|process|method)\b/i.test(plain)) intents.push('how');
		if (/\b(vs|compare|difference|alternative)\b/i.test(plain)) intents.push('comparison');
		if (/\b(cost|price|pricing|budget)\b/i.test(plain)) intents.push('cost');
		if (/\b(error|issue|fix|troubleshoot)\b/i.test(plain)) intents.push('troubleshoot');

		const answerFirst = /\b(is|are|means|defined as|in short)\b/i.test(intro);
		checks.push(answerFirst
			? __('Answer-first signal found.', 'open-growth-seo')
			: __('Primary answer should appear earlier.', 'open-growth-seo'));
		if (!answerFirst) recs.push(__('Add a concise answer-first paragraph in the opening section.', 'open-growth-seo'));

		const hasList = /<ul|<ol/i.test(content || '');
		const hasSteps = /\b(step\s*[0-9]+|first|second|third|next|finally)\b/i.test(plain);
		checks.push(hasList || hasSteps
			? __('Extractable structure found (list/steps).', 'open-growth-seo')
			: __('Add list or step blocks for extractable answers.', 'open-growth-seo'));
		if (!hasList && !hasSteps) recs.push(__('Add an ordered step block or bullet list for key actions.', 'open-growth-seo'));

		const hasTable = /<table/i.test(content || '');
		checks.push(hasTable
			? __('Comparison table signal detected.', 'open-growth-seo')
			: __('No table detected. Add one for comparisons when relevant.', 'open-growth-seo'));

		const words = plain.split(/\s+/).filter(Boolean).length;
		checks.push(words > 320
			? __('Content length supports contextual answers.', 'open-growth-seo')
			: __('Content may be short for intent coverage.', 'open-growth-seo'));
		if (words <= 320) recs.push(__('Expand with concise sections for intent variants and follow-up needs.', 'open-growth-seo'));

		const internalLinks = (content.match(/<a\s+[^>]*href=["'][^"']+["'][^>]*>/gi) || []).length;
		checks.push(internalLinks >= 2
			? __('Internal linking signal is present.', 'open-growth-seo')
			: __('Add internal links to related pages.', 'open-growth-seo'));
		if (internalLinks < 2) recs.push(__('Add 2-4 thematic internal links to related guides or definitions.', 'open-growth-seo'));

		const normalizedKeyphrase = (focusKeyphrase || '').toString().trim().toLowerCase();
		const normalizedTitle = (postTitle || '').toString().trim().toLowerCase();
		if (normalizedKeyphrase) {
			const titleMatch = normalizedTitle.indexOf(normalizedKeyphrase) !== -1;
			const introMatch = intro.toLowerCase().indexOf(normalizedKeyphrase) !== -1;
			checks.push(titleMatch
				? __('Focus keyphrase appears in the title.', 'open-growth-seo')
				: __('Focus keyphrase is missing from the title.', 'open-growth-seo'));
			checks.push(introMatch
				? __('Focus keyphrase appears in the opening section.', 'open-growth-seo')
				: __('Move the focus keyphrase into the opening section.', 'open-growth-seo'));
			if (!titleMatch) recs.push(__('Use the focus keyphrase naturally in the title.', 'open-growth-seo'));
			if (!introMatch) recs.push(__('Mention the focus keyphrase naturally in the opening section.', 'open-growth-seo'));
		} else {
			recs.push(__('Set a focus keyphrase so title and introduction checks can guide you.', 'open-growth-seo'));
		}

		const sentenceParts = plain.split(/[.!?]+/).map(function (part) { return part.trim(); }).filter(Boolean);
		const avgSentenceLength = sentenceParts.length ? Math.round(words / sentenceParts.length) : words;
		const readability = avgSentenceLength <= 20 && words >= 120
			? __('Good', 'open-growth-seo')
			: (avgSentenceLength <= 28 ? __('Needs work', 'open-growth-seo') : __('Fix this first', 'open-growth-seo'));
		checks.push(sprintf(__('Readability: %s', 'open-growth-seo'), readability));
		if (readability !== __('Good', 'open-growth-seo')) {
			recs.push(__('Shorten long sentences and keep one idea per paragraph for easier scanning.', 'open-growth-seo'));
		}

		if (cornerstone) {
			checks.push(__('Cornerstone content is marked for this page.', 'open-growth-seo'));
			if (internalLinks < 3) {
				recs.push(__('Because this is cornerstone content, add more internal links from related pages into it.', 'open-growth-seo'));
			}
		}

		const status = !answerFirst
			? __('Fix this first', 'open-growth-seo')
			: (recs.length > 1 ? __('Needs work', 'open-growth-seo') : __('Good', 'open-growth-seo'));

		return {
			checks,
			recs,
			intents,
			status,
			readability
		};
	};

	const toDateTimeLocal = function (value) {
		const text = (value || '').toString().trim();
		if (!text) {
			return '';
		}
		if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(text)) {
			return text;
		}
		const parsed = new Date(text);
		if (Number.isNaN(parsed.getTime())) {
			return '';
		}
		const year = parsed.getFullYear();
		const month = String(parsed.getMonth() + 1).padStart(2, '0');
		const day = String(parsed.getDate()).padStart(2, '0');
		const hours = String(parsed.getHours()).padStart(2, '0');
		const minutes = String(parsed.getMinutes()).padStart(2, '0');
		return `${year}-${month}-${day}T${hours}:${minutes}`;
	};

	const sprintf = function (format, value) {
		return format.replace('%s', value);
	};

	const Panel = function () {
		const meta = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('meta') || {};
		}, []);
		const postId = useSelect(function (select) {
			return select('core/editor').getCurrentPostId ? select('core/editor').getCurrentPostId() : Number(localized.postId || 0);
		}, []);
		const isSavingPost = useSelect(function (select) {
			return select('core/editor').isSavingPost ? select('core/editor').isSavingPost() : false;
		}, []);
		const isAutosavingPost = useSelect(function (select) {
			return select('core/editor').isAutosavingPost ? select('core/editor').isAutosavingPost() : false;
		}, []);
		const didSaveSucceed = useSelect(function (select) {
			return select('core/editor').didPostSaveRequestSucceed ? select('core/editor').didPostSaveRequestSucceed() : false;
		}, []);
		const content = useSelect(function (select) {
			return select('core/editor').getEditedPostContent() || '';
		}, []);
		const title = useSelect(function (select) {
			const value = select('core/editor').getEditedPostAttribute('title');
			if (typeof value === 'string') {
				return value;
			}
			return value && value.raw ? value.raw : '';
		}, []);
		const permalink = useSelect(function (select) {
			if (typeof select('core/editor').getPermalink === 'function') {
				return select('core/editor').getPermalink();
			}
			return '';
		}, []);
		const dispatch = useDispatch('core/editor');
		const notices = wp.data.dispatch('core/notices');
		const touchedRef = useRef({});
		const saveStateRef = useRef({ saving: false, lastHash: '' });
		const initialMetaRef = useRef(localized.metaDefaults || {});
		const [schemaPreview, setSchemaPreview] = useState(localized.schemaPreviewInitial || {});
		const [schemaPreviewLoading, setSchemaPreviewLoading] = useState(false);
		const [schemaPreviewError, setSchemaPreviewError] = useState('');

		const update = function (key, value) {
			touchedRef.current[key] = true;
			dispatch.editPost({ meta: { ...meta, [key]: value } });
		};

		const refreshSchemaPreview = function () {
			if (!localized.schemaPreviewAjaxUrl || !postId) {
				setSchemaPreviewError(localized.schemaPreviewUnavailableText || __('Schema preview is unavailable for this post.', 'open-growth-seo'));
				return Promise.resolve();
			}

			setSchemaPreviewLoading(true);
			setSchemaPreviewError('');

			return window.fetch(localized.schemaPreviewAjaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: new URLSearchParams({
					action: 'ogs_seo_schema_preview',
					_ajax_nonce: localized.schemaPreviewNonce || '',
					post_id: String(postId)
				}).toString()
			}).then(function (response) {
				return response.json();
			}).then(function (result) {
				if (!result || !result.success || !result.data) {
					throw new Error(result && result.data && result.data.message ? result.data.message : (localized.schemaPreviewUnavailableText || __('Schema preview is unavailable for this post.', 'open-growth-seo')));
				}
				setSchemaPreview(result.data);
			}).catch(function (error) {
				setSchemaPreviewError(error && error.message ? error.message : (localized.schemaPreviewUnavailableText || __('Schema preview is unavailable for this post.', 'open-growth-seo')));
			}).finally(function () {
				setSchemaPreviewLoading(false);
			});
		};

		const getMetaValue = function (key, fallback) {
			if (touchedRef.current[key]) {
				return Object.prototype.hasOwnProperty.call(meta, key) ? meta[key] : fallback;
			}
			if (Object.prototype.hasOwnProperty.call(meta, key) && typeof meta[key] !== 'undefined' && meta[key] !== null) {
				if (meta[key] !== '') {
					return meta[key];
				}
				if (Object.prototype.hasOwnProperty.call(initialMetaRef.current, key) && initialMetaRef.current[key] !== '') {
					return initialMetaRef.current[key];
				}
				return meta[key];
			}
			if (Object.prototype.hasOwnProperty.call(initialMetaRef.current, key) && initialMetaRef.current[key] !== '') {
				return initialMetaRef.current[key];
			}
			return fallback;
		};

		const indexValue = getMetaValue('ogs_seo_index', 'index');
		const followValue = getMetaValue('ogs_seo_follow', 'follow');
		const nosnippetResolved = getMetaValue('ogs_seo_nosnippet', '');
		const noarchiveResolved = getMetaValue('ogs_seo_noarchive', '');
		const notranslateResolved = getMetaValue('ogs_seo_notranslate', '');
		const nosnippetValue = (nosnippetResolved === '1' || nosnippetResolved === '0') ? nosnippetResolved : '';
		const noarchiveValue = (noarchiveResolved === '1' || noarchiveResolved === '0') ? noarchiveResolved : '';
		const notranslateValue = (notranslateResolved === '1' || notranslateResolved === '0') ? notranslateResolved : '';
		const robotsValue = indexValue + ',' + followValue;

		useEffect(function () {
			if (meta.ogs_seo_robots !== robotsValue) {
				dispatch.editPost({ meta: { ...meta, ogs_seo_robots: robotsValue } });
			}
		}, [robotsValue]);

		const fieldHelp = {
			ogs_seo_title: __('Title that appears on Google / defines SEO relevance / E.g.: Privacy Policy | MySite', 'open-growth-seo'),
			ogs_seo_description: __('Description in search results / improves CTR / E.g.: Find out how we protect your data and privacy.', 'open-growth-seo'),
			ogs_seo_focus_keyphrase: __('Primary phrase this page should answer clearly / used for title, intro, and guidance checks / E.g.: technical seo audit', 'open-growth-seo'),
			ogs_seo_cornerstone: __('Mark only strategic pages that should attract internal links and periodic freshness reviews.', 'open-growth-seo'),
			ogs_seo_primary_term: __('Optional main category/topic used for breadcrumb paths and archive context. Leave on automatic unless one term clearly represents this page.', 'open-growth-seo'),
			ogs_seo_canonical: __('Official URL to avoid duplicate content / strengthens SEO / E.g.: https://midominio.com/privacy-policy', 'open-growth-seo'),
			ogs_seo_index: __('Indicates whether Google should index the page / E.g.: index', 'open-growth-seo'),
			ogs_seo_follow: __('Indicates whether links pass authority / E.g.: follow', 'open-growth-seo'),
			ogs_seo_max_snippet: __('Character limit for the snippet on Google / E.g.: 160', 'open-growth-seo'),
			ogs_seo_max_image_preview: __('Permitted size of image previews / E.g.: large', 'open-growth-seo'),
			ogs_seo_max_video_preview: __('Duration of video preview in seconds / E.g.: -1 (no limit)', 'open-growth-seo'),
			ogs_seo_nosnippet: __('Prevents Google from displaying a text snippet / E.g.: Disable', 'open-growth-seo'),
			ogs_seo_noarchive: __('Prevents Google from caching the page / E.g.: Enable', 'open-growth-seo'),
			ogs_seo_notranslate: __('Prevents Google from offering automatic translation / E.g.: Disable', 'open-growth-seo'),
			ogs_seo_unavailable_after: __('Date on which the page stops being indexed / E.g.: 2026-12-31 23:59', 'open-growth-seo'),
			ogs_seo_social_title: __('Optional title for social sharing / overrides the SEO title on supported platforms / E.g.: Privacy Policy | MySite', 'open-growth-seo'),
			ogs_seo_social_description: __('Optional description for social sharing / helps the shared post read clearly / E.g.: Learn how we protect your data and privacy.', 'open-growth-seo'),
			ogs_seo_social_image: __('Optional image for social sharing / used by supported platforms / E.g.: https://midominio.com/uploads/privacy-cover.jpg', 'open-growth-seo'),
			ogs_seo_schema_type: __('Use only when the visible page content clearly matches the selected schema type / E.g.: FAQPage', 'open-growth-seo'),
			ogs_seo_data_nosnippet_ids: __('HTML IDs to exclude from snippets / one ID per line without # / E.g.: pricing-table', 'open-growth-seo')
		};
		const siteName = localized.siteName || 'Site';
		const fallbackDesc = localized.siteDescription || __('No custom description yet.', 'open-growth-seo');
		const blogPublic = Number(localized.blogPublic || 0);
		const indexabilityText = blogPublic === 1
			? (localized.indexabilityPublicText || __('Site visibility is public. You can still set this URL to noindex if needed.', 'open-growth-seo'))
			: (localized.indexabilityPrivateText || __('Site visibility discourages indexing globally. URL-level index settings can still be saved but may be overridden by site visibility.', 'open-growth-seo'));
		const indexabilityStatus = blogPublic === 1 ? 'info' : 'warning';
		const snippetTitleRaw = getMetaValue('ogs_seo_title', '') || title || siteName;
		const snippetDescRaw = getMetaValue('ogs_seo_description', '') || fallbackDesc;
		const socialTitleRaw = getMetaValue('ogs_seo_social_title', '') || snippetTitleRaw;
		const socialDescRaw = getMetaValue('ogs_seo_social_description', '') || snippetDescRaw;
		const socialImageRaw = getMetaValue('ogs_seo_social_image', '');
		const focusKeyphrase = getMetaValue('ogs_seo_focus_keyphrase', '');
		const cornerstoneValue = getMetaValue('ogs_seo_cornerstone', '') === '1';
		const primaryTermValue = getMetaValue('ogs_seo_primary_term', '');
		const socialImageButtonText = socialImageRaw
			? (localized.replaceImageText || __('Replace image', 'open-growth-seo'))
			: (localized.selectImageText || __('Select from Media Library', 'open-growth-seo'));
		const unavailableAfterValue = toDateTimeLocal(getMetaValue('ogs_seo_unavailable_after', ''));
		const schemaType = (getMetaValue('ogs_seo_schema_type', '') || '').trim();
		const schemaState = localized.schemaOverride || {};
		const primaryTermOptions = Array.isArray(localized.primaryTermOptions) ? localized.primaryTermOptions : [];
		const linkSuggestions = localized.linkSuggestions || {};
		const outboundSuggestions = Array.isArray(linkSuggestions.outbound) ? linkSuggestions.outbound : [];
		const inboundSuggestions = Array.isArray(linkSuggestions.inbound) ? linkSuggestions.inbound : [];
		const mastersPlus = localized.mastersPlus || {};
		const mastersDiagnostics = localized.mastersDiagnostics || {};
		const schemaOptions = (schemaState.options || []).map(function (option) {
			return {
				label: option.label,
				value: option.value
			};
		});
		if (schemaType && !schemaOptions.some(function (option) { return option.value === schemaType; })) {
			schemaOptions.push({ label: schemaType + ' (' + __('saved legacy override', 'open-growth-seo') + ')', value: schemaType });
		}
		const selectedSchemaOption = (schemaState.options || []).find(function (option) {
			return option.value === schemaType;
		});
		const schemaHint = schemaType
			? (selectedSchemaOption && selectedSchemaOption.reason ? selectedSchemaOption.reason : (schemaState.currentReason || sprintf(__('Schema override set to %s. Ensure visible content matches it.', 'open-growth-seo'), schemaType)))
			: (schemaState.message || __('No schema override. Default contextual schema rules will apply.', 'open-growth-seo'));
		const aeo = analyze(content, title, focusKeyphrase, cornerstoneValue);
		const safePermalink = permalink || window.location.origin;
		const socialDomain = function () {
			try {
				return new window.URL(safePermalink, window.location.origin).host;
			} catch (error) {
				return safePermalink;
			}
		}();

		useEffect(function () {
			const currentSaving = Boolean(isSavingPost);
			if (saveStateRef.current.saving && !currentSaving && !isAutosavingPost && didSaveSucceed && apiFetch && postId > 0 && localized.contentMetaEndpoint) {
				const payloadMeta = {
					ogs_seo_title: getMetaValue('ogs_seo_title', ''),
					ogs_seo_description: getMetaValue('ogs_seo_description', ''),
					ogs_seo_focus_keyphrase: focusKeyphrase,
					ogs_seo_cornerstone: cornerstoneValue ? '1' : '',
					ogs_seo_primary_term: primaryTermValue,
					ogs_seo_canonical: getMetaValue('ogs_seo_canonical', ''),
					ogs_seo_index: indexValue,
					ogs_seo_follow: followValue,
					ogs_seo_social_title: getMetaValue('ogs_seo_social_title', ''),
					ogs_seo_social_description: getMetaValue('ogs_seo_social_description', ''),
					ogs_seo_social_image: socialImageRaw,
					ogs_seo_max_snippet: getMetaValue('ogs_seo_max_snippet', ''),
					ogs_seo_max_image_preview: getMetaValue('ogs_seo_max_image_preview', ''),
					ogs_seo_max_video_preview: getMetaValue('ogs_seo_max_video_preview', ''),
					ogs_seo_nosnippet: nosnippetValue,
					ogs_seo_noarchive: noarchiveValue,
					ogs_seo_notranslate: notranslateValue,
					ogs_seo_unavailable_after: getMetaValue('ogs_seo_unavailable_after', ''),
					ogs_seo_data_nosnippet_ids: getMetaValue('ogs_seo_data_nosnippet_ids', ''),
					ogs_seo_schema_type: getMetaValue('ogs_seo_schema_type', '')
				};
				const payloadHash = JSON.stringify(payloadMeta);
				if (payloadHash !== saveStateRef.current.lastHash) {
					apiFetch({
						path: localized.contentMetaEndpoint.replace(/^.*?(\/wp-json)/, '').replace(/^\/wp-json/, ''),
						method: 'POST',
						data: { meta: payloadMeta }
					}).then(function (response) {
						if (response && response.meta) {
							initialMetaRef.current = response.meta;
							saveStateRef.current.lastHash = payloadHash;
						}
					}).catch(function () {
						if (notices && typeof notices.createErrorNotice === 'function') {
							notices.createErrorNotice(localized.metaSaveErrorText || __('Open Growth SEO could not save its fields after the post update. Refresh and try again.', 'open-growth-seo'), {
								id: 'ogs-seo-meta-save-error',
								isDismissible: true
							});
						}
					});
				}
			}
			saveStateRef.current.saving = currentSaving;
		}, [isSavingPost, isAutosavingPost, didSaveSucceed, postId, socialImageRaw, indexValue, followValue, nosnippetValue, noarchiveValue, notranslateValue, unavailableAfterValue, schemaType, primaryTermValue, meta]);

		useEffect(function () {
			if (!postId) {
				return;
			}
			if (didSaveSucceed && !isSavingPost && !isAutosavingPost) {
				refreshSchemaPreview();
			}
		}, [didSaveSucceed, isSavingPost, isAutosavingPost, postId]);

		const schemaPreviewSummary = schemaPreview && schemaPreview.summary ? schemaPreview.summary : {};
		const schemaPreviewTypes = schemaPreview && Array.isArray(schemaPreview.node_types) ? schemaPreview.node_types : [];
		const schemaPreviewIssues = schemaPreview && Array.isArray(schemaPreview.issues) ? schemaPreview.issues : [];
		const schemaPreviewJson = schemaPreview && schemaPreview.json_pretty ? schemaPreview.json_pretty : '';

		return el(
			PluginDocumentSettingPanel,
			{ title: 'Open Growth SEO', icon: 'chart-line', name: 'ogs-seo-panel' },
			el(
				PanelBody,
				{ title: __('Basics', 'open-growth-seo'), initialOpen: !!sectionDefaults.basics },
				el(TextControl, { label: __('SEO Title', 'open-growth-seo'), className: 'ogs-gutenberg-field-seo-title', value: getMetaValue('ogs_seo_title', ''), placeholder: 'Privacy Policy | MySite', help: fieldHelp.ogs_seo_title, onChange: function (v) { update('ogs_seo_title', v); } }),
				el(TextareaControl, { label: __('Meta Description', 'open-growth-seo'), value: getMetaValue('ogs_seo_description', ''), placeholder: __('Find out how we protect your data and privacy.', 'open-growth-seo'), help: fieldHelp.ogs_seo_description, onChange: function (v) { update('ogs_seo_description', v); } }),
				el(TextControl, { label: __('Canonical URL', 'open-growth-seo'), value: getMetaValue('ogs_seo_canonical', ''), placeholder: 'https://midominio.com/privacy-policy', help: fieldHelp.ogs_seo_canonical + ' ' + __('You can also use a root-relative path.', 'open-growth-seo'), onChange: function (v) { update('ogs_seo_canonical', v); } }),
				el(SelectControl, { label: __('Indexing', 'open-growth-seo'), value: indexValue, help: fieldHelp.ogs_seo_index, options: [{ label: 'index', value: 'index' }, { label: 'noindex', value: 'noindex' }], onChange: function (v) { update('ogs_seo_index', v); } }),
				el(SelectControl, { label: __('Links', 'open-growth-seo'), value: followValue, help: fieldHelp.ogs_seo_follow, options: [{ label: 'follow', value: 'follow' }, { label: 'nofollow', value: 'nofollow' }], onChange: function (v) { update('ogs_seo_follow', v); } }),
				primaryTermOptions.length > 1 ? el(SelectControl, { label: __('Primary topic', 'open-growth-seo'), value: primaryTermValue, help: fieldHelp.ogs_seo_primary_term, options: primaryTermOptions.map(function (option) { return { label: option.label, value: option.value }; }), onChange: function (v) { update('ogs_seo_primary_term', v); } }) : null,
				el(Notice, { status: indexabilityStatus, isDismissible: false }, indexabilityText)
			),
			el(
				PanelBody,
				{ title: __('Social', 'open-growth-seo'), initialOpen: !!sectionDefaults.social },
				el(TextControl, { label: __('Social Title', 'open-growth-seo'), value: getMetaValue('ogs_seo_social_title', ''), placeholder: 'Privacy Policy | MySite', help: fieldHelp.ogs_seo_social_title, onChange: function (v) { update('ogs_seo_social_title', v); } }),
				el(TextareaControl, { label: __('Social Description', 'open-growth-seo'), value: getMetaValue('ogs_seo_social_description', ''), placeholder: __('Learn how we protect your data and privacy.', 'open-growth-seo'), help: fieldHelp.ogs_seo_social_description, onChange: function (v) { update('ogs_seo_social_description', v); } }),
				el(TextControl, {
					label: __('Social Image URL', 'open-growth-seo'),
					className: 'ogs-gutenberg-field-social-image',
					type: 'url',
					value: socialImageRaw,
					placeholder: 'https://midominio.com/uploads/privacy-cover.jpg',
					help: fieldHelp.ogs_seo_social_image + ' ' + (localized.manualImageHint || __('Paste an image URL or choose one from the Media Library.', 'open-growth-seo')),
					onChange: function (v) { update('ogs_seo_social_image', v); }
				}),
				MediaUpload && MediaUploadCheck ? el(
					Flex,
					{ gap: 2, align: 'center' },
					el(
						FlexItem,
						null,
						el(
							MediaUploadCheck,
							null,
							el(MediaUpload, {
								onSelect: function (media) {
									if (media && media.url) {
										update('ogs_seo_social_image', media.url);
									}
								},
								allowedTypes: ['image'],
								render: function (obj) {
									return el(Button, { variant: 'secondary', onClick: obj.open }, socialImageButtonText);
								}
							})
						)
					),
					socialImageRaw ? el(
						FlexItem,
						null,
						el(Button, {
							variant: 'tertiary',
							isDestructive: true,
							onClick: function () {
								update('ogs_seo_social_image', '');
							}
						}, localized.removeImageText || __('Remove image', 'open-growth-seo'))
					) : null
				) : null,
				socialImageRaw ? el(
					'div',
					{ className: 'ogs-social-image-preview' },
					el('img', {
						src: socialImageRaw,
						alt: localized.mediaPreviewAlt || __('Selected social image preview', 'open-growth-seo')
					})
				) : null,
				el('p', { className: 'description' }, __('Safe default: leave social fields empty to inherit SEO values and featured media.', 'open-growth-seo'))
			),
			el(
				PanelBody,
				{ title: __('Advanced snippet controls', 'open-growth-seo'), initialOpen: !!sectionDefaults.advanced_snippet },
				simpleMode ? el(Notice, { status: 'info', isDismissible: false }, __('These controls are advanced. Leave them empty unless you intentionally need to restrict previews or snippets.', 'open-growth-seo')) : null,
				el(TextControl, { label: __('Max Snippet', 'open-growth-seo'), type: 'number', min: -1, step: 1, placeholder: '160', help: fieldHelp.ogs_seo_max_snippet, value: getMetaValue('ogs_seo_max_snippet', ''), onChange: function (v) { update('ogs_seo_max_snippet', v); } }),
				el(SelectControl, { label: __('Max Image Preview', 'open-growth-seo'), value: getMetaValue('ogs_seo_max_image_preview', ''), help: fieldHelp.ogs_seo_max_image_preview, options: [{ label: __('Inherit', 'open-growth-seo'), value: '' }, { label: 'large', value: 'large' }, { label: 'standard', value: 'standard' }, { label: 'none', value: 'none' }], onChange: function (v) { update('ogs_seo_max_image_preview', v); } }),
				el(TextControl, { label: __('Max Video Preview', 'open-growth-seo'), type: 'number', min: -1, step: 1, placeholder: '-1', help: fieldHelp.ogs_seo_max_video_preview, value: getMetaValue('ogs_seo_max_video_preview', ''), onChange: function (v) { update('ogs_seo_max_video_preview', v); } }),
				el(SelectControl, { label: __('nosnippet', 'open-growth-seo'), value: nosnippetValue, help: fieldHelp.ogs_seo_nosnippet, options: [{ label: __('Inherit', 'open-growth-seo'), value: '' }, { label: __('Enable', 'open-growth-seo'), value: '1' }, { label: __('Disable', 'open-growth-seo'), value: '0' }], onChange: function (v) { update('ogs_seo_nosnippet', v); } }),
				el(SelectControl, { label: __('noarchive', 'open-growth-seo'), value: noarchiveValue, help: fieldHelp.ogs_seo_noarchive, options: [{ label: __('Inherit', 'open-growth-seo'), value: '' }, { label: __('Enable', 'open-growth-seo'), value: '1' }, { label: __('Disable', 'open-growth-seo'), value: '0' }], onChange: function (v) { update('ogs_seo_noarchive', v); } }),
				el(SelectControl, { label: __('notranslate', 'open-growth-seo'), value: notranslateValue, help: fieldHelp.ogs_seo_notranslate, options: [{ label: __('Inherit', 'open-growth-seo'), value: '' }, { label: __('Enable', 'open-growth-seo'), value: '1' }, { label: __('Disable', 'open-growth-seo'), value: '0' }], onChange: function (v) { update('ogs_seo_notranslate', v); } }),
				el(TextControl, { label: __('unavailable_after', 'open-growth-seo'), type: 'datetime-local', value: unavailableAfterValue, help: fieldHelp.ogs_seo_unavailable_after, onChange: function (v) { update('ogs_seo_unavailable_after', v); } }),
				el(TextareaControl, { label: __('data-nosnippet IDs (one per line)', 'open-growth-seo'), value: getMetaValue('ogs_seo_data_nosnippet_ids', ''), placeholder: 'pricing-table', help: fieldHelp.ogs_seo_data_nosnippet_ids, onChange: function (v) { update('ogs_seo_data_nosnippet_ids', v); } }),
				el('p', { className: 'description' }, __('Guardrail: if nosnippet is enabled, max-snippet is ignored.', 'open-growth-seo'))
			),
			el(
				PanelBody,
				{ title: __('Schema', 'open-growth-seo'), initialOpen: !!sectionDefaults.schema },
				simpleMode ? el(Notice, { status: 'info', isDismissible: false }, __('Simple mode keeps schema on the safest defaults. Switch to Advanced mode only when the page clearly matches a more specific schema type.', 'open-growth-seo')) : null,
				el(SelectControl, { label: __('Schema Type Override', 'open-growth-seo'), value: getMetaValue('ogs_seo_schema_type', ''), help: fieldHelp.ogs_seo_schema_type, options: schemaOptions, onChange: function (v) { update('ogs_seo_schema_type', v); } }),
				el('p', { className: 'description' }, schemaHint)
			),
			el(
				PanelBody,
				{ title: __('Final schema preview', 'open-growth-seo'), initialOpen: false },
				el('p', { className: 'description' }, localized.schemaPreviewStaleText || __('This preview reflects the last saved post state. Save the post to refresh final JSON-LD.', 'open-growth-seo')),
				el(
					Flex,
					{ gap: 2, align: 'center', justify: 'space-between' },
					el(
						FlexItem,
						null,
						el('ul', { className: 'ogs-inline-stats ogs-schema-preview-meta' },
							el('li', null, sprintf(__('Nodes: %s', 'open-growth-seo'), String(schemaPreviewSummary.node_count || 0))),
							el('li', null, sprintf(__('Warnings: %s', 'open-growth-seo'), String(schemaPreviewSummary.warning_count || 0))),
							el('li', null, sprintf(__('Errors: %s', 'open-growth-seo'), String(schemaPreviewSummary.error_count || 0)))
						)
					),
					el(
						FlexItem,
						null,
						el(Button, {
							variant: 'secondary',
							isBusy: schemaPreviewLoading,
							onClick: refreshSchemaPreview
						}, localized.schemaPreviewRefreshText || __('Refresh saved preview', 'open-growth-seo'))
					)
				),
				schemaPreviewTypes.length ? el('p', { className: 'description' }, __('Resolved node types:', 'open-growth-seo') + ' ' + schemaPreviewTypes.join(', ')) : null,
				schemaPreviewError ? el(Notice, { status: 'error', isDismissible: false }, schemaPreviewError) : null,
				schemaPreviewIssues.slice(0, 4).map(function (line, idx) {
					return el(Notice, { key: 'schema-preview-issue-' + idx, status: 'warning', isDismissible: false }, line);
				}),
				schemaPreviewLoading ? el(Notice, { status: 'info', isDismissible: false }, localized.schemaPreviewLoadingText || __('Refreshing saved JSON-LD preview...', 'open-growth-seo')) : null,
				schemaPreviewJson
					? el('pre', { className: 'ogs-rest-response ogs-schema-preview-json' }, schemaPreviewJson)
					: el('p', { className: 'description' }, localized.schemaPreviewUnavailableText || __('Schema preview is unavailable for this post.', 'open-growth-seo'))
			),
			el(
				PanelBody,
				{ title: __('AEO hints', 'open-growth-seo'), initialOpen: !!sectionDefaults.aeo_hints },
				el(TextControl, { label: __('Focus keyphrase', 'open-growth-seo'), value: focusKeyphrase, placeholder: __('technical seo audit', 'open-growth-seo'), help: fieldHelp.ogs_seo_focus_keyphrase, onChange: function (v) { update('ogs_seo_focus_keyphrase', v); } }),
				el(SelectControl, { label: __('Cornerstone content', 'open-growth-seo'), value: cornerstoneValue ? '1' : '', help: fieldHelp.ogs_seo_cornerstone, options: [{ label: __('Standard page', 'open-growth-seo'), value: '' }, { label: __('Cornerstone content', 'open-growth-seo'), value: '1' }], onChange: function (v) { update('ogs_seo_cornerstone', v); } }),
				el(Notice, { status: aeo.status === __('Good', 'open-growth-seo') ? 'success' : 'warning', isDismissible: false }, aeo.status),
				el(Notice, { status: aeo.readability === __('Good', 'open-growth-seo') ? 'success' : (aeo.readability === __('Fix this first', 'open-growth-seo') ? 'error' : 'warning'), isDismissible: false }, sprintf(__('Readability: %s', 'open-growth-seo'), aeo.readability)),
				...aeo.checks.slice(0, 3).map(function (line, idx) {
					return el('p', { key: 'c-' + idx, className: 'description' }, line);
				}),
				el('p', { style: { fontWeight: 600, marginBottom: '6px' } }, __('Next best actions', 'open-growth-seo')),
				...aeo.recs.slice(0, 3).map(function (line, idx) {
					return el(Notice, { key: 'r-' + idx, status: 'warning', isDismissible: false }, line);
				}),
				el('p', { className: 'description' }, __('Intent coverage:', 'open-growth-seo') + ' ' + (aeo.intents.length ? aeo.intents.join(', ') : __('none detected', 'open-growth-seo'))),
				outboundSuggestions.length ? el(
					'div',
					null,
					el('p', { style: { fontWeight: 600, marginBottom: '6px' } }, __('Suggested internal links to add from this page', 'open-growth-seo')),
					el(
						'ul',
						{ className: 'ogs-link-suggestions' },
						...outboundSuggestions.slice(0, 4).map(function (item, idx) {
							const confidence = suggestionConfidenceLabel(item.confidence);
							return el(
								'li',
								{ key: 'out-' + idx },
								item.edit_url ? el('a', { href: item.edit_url }, item.title) : item.title,
								item.reason
									? el('span', { className: 'description' }, ' - ' + item.reason + ' | ' + confidence)
									: el('span', { className: 'description' }, ' - ' + confidence)
							);
						})
					)
				) : null,
				cornerstoneValue && inboundSuggestions.length ? el(
					'div',
					null,
					el('p', { style: { fontWeight: 600, marginBottom: '6px' } }, __('Pages to review for a link back to this cornerstone', 'open-growth-seo')),
					el(
						'ul',
						{ className: 'ogs-link-suggestions' },
						...inboundSuggestions.slice(0, 4).map(function (item, idx) {
							const confidence = suggestionConfidenceLabel(item.confidence);
							return el(
								'li',
								{ key: 'in-' + idx },
								item.edit_url ? el('a', { href: item.edit_url }, item.title) : item.title,
								item.reason
									? el('span', { className: 'description' }, ' - ' + item.reason + ' | ' + confidence)
									: el('span', { className: 'description' }, ' - ' + confidence)
							);
						})
					)
				) : null
			),
			mastersPlus.available ? el(
				PanelBody,
				{ title: __('SEO MASTERS PLUS', 'open-growth-seo'), initialOpen: !!sectionDefaults.masters_plus },
				mastersPlus.active ? null : el(Notice, { status: 'info', isDismissible: false }, __('SEO MASTERS PLUS is disabled. Enable it in plugin settings when you need expert controls.', 'open-growth-seo')),
				mastersPlus.active ? el(
					'div',
					null,
					el('p', { className: 'description' }, __('Expert diagnostics are active for this URL.', 'open-growth-seo')),
					el('ul', { className: 'ogs-link-suggestions' },
						el('li', null, __('Orphan confidence:', 'open-growth-seo') + ' ' + (mastersDiagnostics.orphan_confidence || __('Low confidence', 'open-growth-seo'))),
						el('li', null, __('Inbound internal links:', 'open-growth-seo') + ' ' + String(mastersDiagnostics.inbound_links || 0)),
						el('li', null, __('Cluster score:', 'open-growth-seo') + ' ' + String(mastersDiagnostics.cluster_score || 0)),
						el('li', null, __('Canonical state:', 'open-growth-seo') + ' ' + (mastersDiagnostics.canonical_state || __('Default canonical', 'open-growth-seo')))
					),
					Array.isArray(mastersDiagnostics.actions) && mastersDiagnostics.actions.length ? el(
						'div',
						null,
						el('p', { style: { fontWeight: 600, marginBottom: '6px' } }, __('Expert next actions', 'open-growth-seo')),
						el('ol', { className: 'ogs-coaching-actions' },
							...mastersDiagnostics.actions.slice(0, 3).map(function (item, idx) {
								return el('li', { key: 'master-action-' + idx }, item);
							})
						)
					) : null
				) : null,
				!mastersPlus.active && mastersPlus.settingsUrl ? el(
					Button,
					{
						variant: 'secondary',
						href: mastersPlus.settingsUrl
					},
					__('Open SEO MASTERS PLUS settings', 'open-growth-seo')
				) : null
			) : null,
			el(
				PanelBody,
				{ title: __('Preview', 'open-growth-seo'), initialOpen: !!sectionDefaults.preview },
				el('p', { className: 'description' }, __('Indicative preview only. Platforms can rewrite snippets.', 'open-growth-seo')),
				el('div', { className: 'ogs-serp-result' },
					el('p', { className: 'ogs-status ogs-status-good ogs-snippet-title' }, trimForPreview(snippetTitleRaw, 60)),
					el('p', { className: 'description ogs-snippet-url' }, safePermalink),
					el('p', { className: 'ogs-snippet-desc' }, trimForPreview(snippetDescRaw, 160))
				),
				el('p', { style: { fontWeight: 600, marginTop: '12px', marginBottom: '8px' } }, __('Social Card', 'open-growth-seo')),
				el(
					'div',
					{ className: 'ogs-social-card' },
					renderSocialPreviewImage(socialImageRaw),
					el(
						'div',
						{ className: 'ogs-social-card-body' },
						el('p', { className: 'ogs-social-title' }, trimForPreview(socialTitleRaw, 90)),
						el('p', { className: 'ogs-social-desc' }, trimForPreview(socialDescRaw, 200)),
						el('p', { className: 'ogs-social-url' }, socialDomain)
					)
				),
				el('p', { className: 'description', style: { marginTop: '10px' } }, schemaHint)
			)
		);
	};

	registerPlugin('open-growth-seo-panel', { render: Panel });
})(window.wp);
