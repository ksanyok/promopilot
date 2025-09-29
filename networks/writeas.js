'use strict';

const puppeteer = require('puppeteer');
const { createLogger } = require('./lib/logger');
const { generateArticle, analyzeLinks } = require('./lib/articleGenerator');
const { htmlToMarkdown, htmlToPlainText } = require('./lib/contentFormats');
const { waitForTimeoutSafe } = require('./lib/puppeteerUtils');
const { createVerificationPayload } = require('./lib/verification');

const WRITEAS_COMPOSE_URL = 'https://write.as/new';
const MAX_MARKDOWN_LENGTH = 60000;

function ensureAnchorInMarkdown(markdown, pageUrl, anchorText) {
	let body = String(markdown || '').trim();
	const url = String(pageUrl || '').trim();
	if (!url) {
		return body;
	}
	if (body.includes(url)) {
		return body;
	}
	const anchor = String(anchorText || '').trim() || url;
	const safeAnchor = anchor.replace(/\]\(/g, ')').replace(/\[/g, '').trim();
	const linkLine = `[${safeAnchor || url}](${url})`;
	body = body ? `${body}\n\n${linkLine}\n` : `${linkLine}\n`;
	return body.trim();
}

function buildWriteAsUrlFromResponse(responseJson, origin = 'https://write.as') {
	try {
		const data = responseJson && responseJson.data;
		if (!data) {
			return '';
		}

		const blockedIds = new Set(['spamspamspamspam', 'contentisblocked']);
		let nextId = data.parent_id && data.type === 'submission-draft' ? data.parent_id : data.id;
		let prefix = '';
		if (data.type === 'prompt') {
			prefix = 'p/';
		} else if (data.type === 'submission-draft' && data.parent_id) {
			prefix = 's/';
		}

		if (blockedIds.has(nextId)) {
			return '';
		}

		if (data.collection && data.collection.alias) {
			const slug = data.slug || nextId;
			if (!slug) {
				return '';
			}
			return `${origin.replace(/\/$/, '')}/${data.collection.alias}/${slug}`;
		}

		if (!nextId) {
			return '';
		}

		const suffix = prefix ? `${prefix}${nextId}` : `${nextId}.md`;
		return `${origin.replace(/\/$/, '')}/${suffix}`;
	} catch (_) {
		return '';
	}
}

async function publishToWriteAs(pageUrl, anchorText, language, openaiApiKey, aiProvider, wish, pageMeta, jobOptions = {}) {
	const provider = (aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
	const { LOG_FILE, logLine, logDebug } = createLogger('writeas');
	logLine('Publish start', { pageUrl, anchorText, language, provider, testMode: !!jobOptions.testMode });

	const articleJob = {
		pageUrl,
		anchorText,
		language,
		openaiApiKey,
		aiProvider: provider,
		wish,
		meta: pageMeta,
		testMode: !!jobOptions.testMode
	};

	const article = (jobOptions.preparedArticle && jobOptions.preparedArticle.htmlContent)
		? { ...jobOptions.preparedArticle }
		: await generateArticle(articleJob, logLine);

	const htmlContent = String(article.htmlContent || '').trim();
	if (!htmlContent) {
		throw new Error('EMPTY_ARTICLE_CONTENT');
	}

	const variants = {
		html: htmlContent,
		markdown: htmlToMarkdown(htmlContent),
		plain: htmlToPlainText(htmlContent)
	};

	let markdownBody = variants.markdown || variants.plain || variants.html;
	if (!markdownBody) {
		throw new Error('FAILED_TO_PREPARE_CONTENT');
	}
	markdownBody = ensureAnchorInMarkdown(markdownBody, pageUrl, anchorText);
	if (markdownBody.length > MAX_MARKDOWN_LENGTH) {
		markdownBody = markdownBody.slice(0, MAX_MARKDOWN_LENGTH);
	}

	logDebug('Article link stats', analyzeLinks(htmlContent, pageUrl, anchorText));
	logDebug('Markdown prepared', { length: markdownBody.length });

	const verification = createVerificationPayload({
		pageUrl,
		anchorText,
		article,
		variants: { ...variants, markdown: markdownBody },
		extraTexts: []
	});

	const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox'];
	if (process.env.PUPPETEER_ARGS) {
		launchArgs.push(...String(process.env.PUPPETEER_ARGS).split(/\s+/).filter(Boolean));
	}
	const execPath = process.env.PUPPETEER_EXECUTABLE_PATH || '';
	const launchOpts = { headless: true, args: Array.from(new Set(launchArgs)) };
	if (execPath) {
		launchOpts.executablePath = execPath;
	}

	logLine('Launching browser', { executablePath: execPath || 'default', args: launchOpts.args });
	const browser = await puppeteer.launch(launchOpts);

	let page = null;
	try {
		page = await browser.newPage();
		page.setDefaultTimeout(300000);
		page.setDefaultNavigationTimeout(300000);

		page.on('dialog', async (dialog) => {
			logLine('Dialog detected', { message: dialog.message(), type: dialog.type() });
			try {
				await dialog.dismiss();
			} catch (_) {}
		});

		logLine('Goto Write.as', { url: WRITEAS_COMPOSE_URL });
		await page.goto(WRITEAS_COMPOSE_URL, { waitUntil: 'networkidle2' });

		const editorSelector = 'textarea#writer';
		await page.waitForSelector(editorSelector, { timeout: 20000 });

		logLine('Fill editor (markdown)', { length: markdownBody.length });
		await page.evaluate((value) => {
			const textarea = document.querySelector('textarea#writer');
			if (textarea) {
				textarea.focus();
				textarea.value = value;
				const events = ['input', 'change', 'keyup'];
				events.forEach((evt) => {
					try {
						textarea.dispatchEvent(new Event(evt, { bubbles: true }));
					} catch (_) {}
				});
			}
		}, markdownBody);

		await waitForTimeoutSafe(page, 200);

		await page.evaluate(() => {
			const publishBtn = document.querySelector('button#publish');
			if (publishBtn) {
				publishBtn.disabled = false;
				publishBtn.classList.remove('disabled');
			}
		});

		const apiResponsePromise = page.waitForResponse((response) => {
			const url = response.url();
			return (/\/api\/posts$/i.test(url) || /\/api\/collections\//i.test(url)) && response.request().method() === 'POST';
		}, { timeout: 60000 }).catch((err) => {
			logDebug('API response wait error', { message: err && err.message });
			return null;
		});

		const navigationPromise = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch((err) => {
			logDebug('Navigation wait error', { message: err && err.message });
			return null;
		});

		logLine('Click publish');
		const clicked = await page.evaluate(() => {
			const btn = document.querySelector('button#publish');
			if (btn) {
				btn.click();
				return true;
			}
			return false;
		});
		if (!clicked) {
			throw new Error('PUBLISH_BUTTON_NOT_FOUND');
		}

		const apiResponse = await apiResponsePromise;
		await navigationPromise;
		await waitForTimeoutSafe(page, 500);

		let publishedUrl = '';
		if (apiResponse) {
			try {
				const origin = new URL(apiResponse.url()).origin;
				const json = await apiResponse.json();
				publishedUrl = buildWriteAsUrlFromResponse(json, origin);
				logDebug('API response parsed', { publishedUrlFromApi: publishedUrl });
			} catch (err) {
				logDebug('API parse error', { message: err && err.message });
			}
		}

		if (!publishedUrl) {
			try {
				publishedUrl = await page.url();
			} catch (_) {
				publishedUrl = '';
			}
		}

		if (!publishedUrl || /\/new\b/i.test(publishedUrl)) {
			throw new Error('FAILED_TO_RESOLVE_URL');
		}

		logLine('Publish success', { publishedUrl });

		await browser.close();

		return {
			ok: true,
			network: 'writeas',
			publishedUrl,
			format: 'markdown',
			logFile: LOG_FILE,
			verification
		};
	} catch (error) {
		try {
			await browser.close();
		} catch (_) {}
		logLine('Publish failed', { error: String(error && error.message || error) });
		return {
			ok: false,
			network: 'writeas',
			error: String(error && error.message || error),
			logFile: LOG_FILE
		};
	}
}

module.exports = { publish: publishToWriteAs };

if (require.main === module) {
	(async () => {
		const { createLogger } = require('./lib/logger');
		const { LOG_FILE, logLine } = createLogger('writeas-cli');
		try {
			const raw = process.env.PP_JOB || '{}';
			logLine('PP_JOB raw', { length: raw.length });
			const job = JSON.parse(raw);
			logLine('PP_JOB parsed', { keys: Object.keys(job || {}) });

			const pageUrl = job.url || job.pageUrl || '';
			const anchor = job.anchor || pageUrl;
			const language = job.language || 'ru';
			const apiKey = job.openaiApiKey || process.env.OPENAI_API_KEY || '';
			const provider = (job.aiProvider || process.env.PP_AI_PROVIDER || 'openai').toLowerCase();
			const wish = job.wish || '';
			const model = job.openaiModel || process.env.OPENAI_MODEL || '';
			if (model) process.env.OPENAI_MODEL = String(model);

			if (!pageUrl) {
				const payload = { ok: false, error: 'MISSING_PARAMS', details: 'url missing', network: 'writeas', logFile: LOG_FILE };
				logLine('Run failed (missing params)', payload);
				console.log(JSON.stringify(payload));
				process.exit(1);
			}
			if (provider === 'openai' && !apiKey) {
				const payload = { ok: false, error: 'MISSING_PARAMS', details: 'openaiApiKey missing', network: 'writeas', logFile: LOG_FILE };
				logLine('Run failed (missing api key)', payload);
				console.log(JSON.stringify(payload));
				process.exit(1);
			}

			const res = await publishToWriteAs(pageUrl, anchor, language, apiKey, provider, wish, job.page_meta || job.meta || null, job);
			logLine('Success result', res);
			console.log(JSON.stringify(res));
			process.exit(res.ok ? 0 : 1);
		} catch (e) {
			const payload = { ok: false, error: String(e && e.message || e), network: 'writeas', logFile: LOG_FILE };
			logLine('Run failed', payload);
			console.log(JSON.stringify(payload));
			process.exit(1);
		}
	})();
}
