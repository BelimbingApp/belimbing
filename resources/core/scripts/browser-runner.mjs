/**
 * Belimbing — Playwright browser runner.
 *
 * Accepts a JSON command via stdin, launches Chromium (headful or headless),
 * executes the requested browser action, and writes a JSON result to stdout.
 *
 * Designed for per-command invocation from PHP via Symfony Process.
 * Each run launches a fresh browser context — no session persistence.
 *
 * If headful launch fails (e.g. X server unavailable after sleep/resume),
 * the runner automatically retries in headless mode and flags the fallback
 * in the result so the caller knows visibility was lost.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 * (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
 */

import { chromium } from 'playwright';
import { readFileSync, writeFileSync, renameSync, unlinkSync } from 'node:fs';

// ── Constants ───────────────────────────────────────────────────────

const APP_NAME = 'Belimbing';

// ── I/O helpers ─────────────────────────────────────────────────────

/**
 * Read input from file (BLB_INPUT_FILE env var) or stdin.
 *
 * File-based input is used for detached headful mode where the PHP
 * process launches the runner as a background process via exec().
 */
async function readInput() {
    if (process.env.BLB_INPUT_FILE) {
        const data = readFileSync(process.env.BLB_INPUT_FILE, 'utf8');
        try { unlinkSync(process.env.BLB_INPUT_FILE); } catch {}
        return data;
    }

    return new Promise((resolve, reject) => {
        let data = '';
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => (data += chunk));
        process.stdin.on('end', () => resolve(data));
        process.stdin.on('error', reject);
    });
}

/**
 * Write result to file (BLB_OUTPUT_FILE env var) or stdout.
 *
 * File-based output uses atomic rename (write .tmp, then rename) so
 * the PHP-side poller never reads partial JSON.
 */
function writeOutput(result) {
    if (process.env.BLB_OUTPUT_FILE) {
        const tmpFile = process.env.BLB_OUTPUT_FILE + '.tmp';
        writeFileSync(tmpFile, result);
        renameSync(tmpFile, process.env.BLB_OUTPUT_FILE);
    } else {
        process.stdout.write(result);
    }
}

// ── Result helpers ──────────────────────────────────────────────────

function success(action, payload) {
    return JSON.stringify({ ok: true, action, ...payload });
}

function error(action, message, code = 'browser_error') {
    return JSON.stringify({ ok: false, action, error: code, message });
}

// ── Browser launch ──────────────────────────────────────────────────

/**
 * Launch Chromium with automatic headless fallback.
 *
 * On headful launch failure (typically "Missing X server"), retries in
 * headless mode so the action still completes. Returns a flag indicating
 * whether the fallback was used.
 */
async function launchBrowser(headless, executablePath) {
    const launchArgs = [`--class=${APP_NAME}`];

    const launchOptions = {
        headless,
        args: launchArgs,
    };

    if (executablePath) {
        launchOptions.executablePath = executablePath;
    }

    try {
        const browser = await chromium.launch(launchOptions);
        return { browser, headlessFallback: false };
    } catch (err) {
        // If headful failed due to missing display, retry headless.
        if (!headless && isDisplayError(err)) {
            const fallbackOptions = { ...launchOptions, headless: true };
            const browser = await chromium.launch(fallbackOptions);
            return { browser, headlessFallback: true };
        }

        throw err;
    }
}

/**
 * Detect whether a launch error is caused by a missing X/Wayland display.
 */
function isDisplayError(err) {
    const msg = err.message || '';
    return (
        msg.includes('Missing X server') ||
        msg.includes('XServer') ||
        msg.includes('Cannot open display') ||
        msg.includes('DISPLAY')
    );
}

// ── Action handlers ─────────────────────────────────────────────────

async function handleNavigate(page, args) {
    const url = args.url;
    if (!url) return error('navigate', 'Missing required parameter: url', 'missing_param');

    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });

    return success('navigate', {
        url: page.url(),
        title: await page.title(),
        status: 'navigated',
        httpStatus: response?.status() ?? null,
    });
}

async function handleSnapshot(page, args) {
    // Navigate first if URL provided (per-command model has no prior state)
    if (args.url) {
        await page.goto(args.url, { waitUntil: 'domcontentloaded', timeout: 15000 });
    }

    const format = args.format || 'ai';
    let content;

    if (format === 'aria') {
        // Accessibility tree via Playwright's ariaSnapshot
        content = await page.locator('body').ariaSnapshot();
    } else {
        // AI-optimized: readable text content
        content = await page.innerText('body');
    }

    return success('snapshot', {
        format,
        content,
        url: page.url(),
        title: await page.title(),
        status: 'captured',
    });
}

async function handleScreenshot(page, args) {
    if (args.url) {
        await page.goto(args.url, { waitUntil: 'domcontentloaded', timeout: 15000 });
    }

    const options = { type: 'png' };

    if (args.full_page) options.fullPage = true;

    if (args.selector) {
        const element = page.locator(args.selector);
        const buffer = await element.screenshot(options);
        return success('screenshot', {
            image_base64: buffer.toString('base64'),
            selector: args.selector,
            status: 'captured',
        });
    }

    const buffer = await page.screenshot(options);
    return success('screenshot', {
        image_base64: buffer.toString('base64'),
        full_page: args.full_page || false,
        status: 'captured',
    });
}

async function handlePdf(page, args) {
    if (args.url) {
        await page.goto(args.url, { waitUntil: 'domcontentloaded', timeout: 15000 });
    }

    // PDF generation requires headless mode in Chromium
    const buffer = await page.pdf({ format: 'A4' });
    return success('pdf', {
        pdf_base64: buffer.toString('base64'),
        size_bytes: buffer.length,
        status: 'exported',
    });
}

async function handleEvaluate(page, args) {
    if (!args.script) return error('evaluate', 'Missing required parameter: script', 'missing_param');

    if (args.url) {
        await page.goto(args.url, { waitUntil: 'domcontentloaded', timeout: 15000 });
    }

    const result = await page.evaluate(args.script);
    return success('evaluate', {
        result,
        status: 'evaluated',
    });
}

async function handleCookies(page, context, args) {
    const cookieAction = args.cookie_action;
    if (!cookieAction) return error('cookies', 'Missing required parameter: cookie_action', 'missing_param');

    if (args.url) {
        await page.goto(args.url, { waitUntil: 'domcontentloaded', timeout: 15000 });
    }

    if (cookieAction === 'get') {
        const cookies = await context.cookies();
        const filtered = args.cookie_name
            ? cookies.filter((c) => c.name === args.cookie_name)
            : cookies;
        return success('cookies', { cookie_action: 'get', cookies: filtered, status: 'retrieved' });
    }

    if (cookieAction === 'set') {
        if (!args.cookie_name) return error('cookies', 'Missing cookie_name for set', 'missing_param');
        await context.addCookies([
            {
                name: args.cookie_name,
                value: args.cookie_value || '',
                url: args.cookie_url || page.url(),
            },
        ]);
        return success('cookies', { cookie_action: 'set', cookie_name: args.cookie_name, status: 'set' });
    }

    if (cookieAction === 'clear') {
        await context.clearCookies();
        return success('cookies', { cookie_action: 'clear', status: 'cleared' });
    }

    return error('cookies', `Unknown cookie_action: ${cookieAction}`, 'invalid_param');
}

async function handleWait(page, args) {
    const timeout = args.timeout_ms || 5000;

    if (args.text) {
        await page.waitForSelector(`text=${args.text}`, { timeout });
        return success('wait', { text: args.text, status: 'matched' });
    }

    if (args.selector) {
        await page.waitForSelector(args.selector, { timeout });
        return success('wait', { selector: args.selector, status: 'matched' });
    }

    if (args.url) {
        await page.waitForURL(args.url, { timeout });
        return success('wait', { url: args.url, status: 'matched' });
    }

    return error('wait', 'At least one of text, selector, or url is required', 'missing_param');
}

// ── Session-dependent actions (require persistent process) ──────────

function sessionRequired(action) {
    return error(
        action,
        `The "${action}" action requires an active browser session. ` +
            'Per-command execution does not support session-dependent actions yet.',
        'session_required',
    );
}

// ── Main ────────────────────────────────────────────────────────────

async function main() {
    let input;
    try {
        const raw = await readInput();
        input = JSON.parse(raw);
    } catch {
        writeOutput(error('unknown', 'Failed to parse JSON input', 'parse_error'));
        process.exit(1);
    }

    const { action, headless = true, keepOpen = false, keepOpenTimeout, executablePath, ...args } = input;

    let browser;
    let headlessFallback = false;
    try {
        ({ browser, headlessFallback } = await launchBrowser(headless, executablePath));
    } catch (err) {
        writeOutput(
            error(action, `Failed to launch browser: ${err.message}`, 'launch_failed'),
        );
        process.exit(1);
    }

    let keepBrowserOpen = false;
    try {
        const context = await browser.newContext();
        const page = await context.newPage();

        let result;
        switch (action) {
            case 'navigate':
                result = await handleNavigate(page, args);
                break;
            case 'snapshot':
                result = await handleSnapshot(page, args);
                break;
            case 'screenshot':
                result = await handleScreenshot(page, args);
                break;
            case 'pdf':
                result = await handlePdf(page, args);
                break;
            case 'evaluate':
                result = await handleEvaluate(page, args);
                break;
            case 'cookies':
                result = await handleCookies(page, context, args);
                break;
            case 'wait':
                result = await handleWait(page, args);
                break;
            // Session-dependent: need persistent process
            case 'act':
            case 'tabs':
            case 'open':
            case 'close':
                result = sessionRequired(action);
                break;
            default:
                result = error(action, `Unknown action: ${action}`, 'unknown_action');
        }

        // Inject headless fallback notice into successful results so the
        // caller knows the action completed but without a visible window.
        if (headlessFallback) {
            const parsed = JSON.parse(result);
            if (parsed.ok) {
                parsed.headlessFallback = true;
                parsed.notice =
                    'Headful mode was requested but the display server was unavailable. ' +
                    'Fell back to headless mode — the action completed without a visible browser window.';
                result = JSON.stringify(parsed);
            }
        }

        // In headful mode, keep the browser window open after the action
        // so the user can inspect the page. The result is written first,
        // then the browser enters a keep-alive loop.
        if (keepOpen && !headlessFallback) {
            const parsed = JSON.parse(result);
            if (parsed.ok) {
                parsed.browserOpen = true;
                parsed.notice = 'Browser window is open. Close it when done inspecting the page.';
                result = JSON.stringify(parsed);
                keepBrowserOpen = true;
            }
        }

        writeOutput(result);
    } catch (err) {
        writeOutput(error(action, err.message, 'action_failed'));
    } finally {
        if (!keepBrowserOpen) {
            await browser.close();
        }
    }

    if (keepBrowserOpen) {
        await keepAlive(browser, keepOpenTimeout);
    }
}

/**
 * Keep browser alive until the user closes the window or timeout expires.
 *
 * Listens for the Playwright browser disconnected event (fires when
 * the user closes the Chromium window) and handles SIGTERM for graceful
 * shutdown from external process managers.
 */
async function keepAlive(browser, timeoutMs) {
    const maxTimeout = timeoutMs || 5 * 60 * 1000; // 5 minutes default

    return new Promise((resolve) => {
        const timeout = setTimeout(async () => {
            try { await browser.close(); } catch {}
            resolve();
        }, maxTimeout);

        browser.on('disconnected', () => {
            clearTimeout(timeout);
            resolve();
        });

        process.on('SIGTERM', async () => {
            clearTimeout(timeout);
            try { await browser.close(); } catch {}
            resolve();
        });
    });
}

main();
