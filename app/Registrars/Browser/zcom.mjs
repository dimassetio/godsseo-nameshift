import { mkdir, readdir, stat, unlink } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

const ErrorCategory = Object.freeze({
    ActionRequired: 'ACTION_REQUIRED',
    Authentication: 'AUTHENTICATION',
    Conflict: 'CONFLICT',
    DomainNotFound: 'DOMAIN_NOT_FOUND',
    Network: 'NETWORK',
    ProviderChanged: 'PROVIDER_CHANGED',
    ProviderTemporary: 'PROVIDER_TEMPORARY',
    Validation: 'VALIDATION',
});

class ZComAutomationError extends Error {
    constructor(category, message, providerCode = null) {
        super(message);
        this.category = category;
        this.providerCode = providerCode;
    }
}

export function normalizeDomain(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/\.$/, '');
}

export function normalizeNameservers(values) {
    return [...new Set(values.map(normalizeDomain).filter(Boolean))];
}

export function nameserversEqual(left, right) {
    return JSON.stringify([...normalizeNameservers(left)].sort()) === JSON.stringify([...normalizeNameservers(right)].sort());
}

export async function extractDomainLinks(page) {
    const records = await page.locator('a[href*="action=domaindetails"]').evaluateAll((links) =>
        links.map((link) => {
            const rowText = link.closest('tr')?.textContent ?? '';
            const text = link.textContent?.trim() ?? '';
            const title = link.getAttribute('title')?.trim() ?? '';
            const domainMatch = `${text} ${title} ${rowText}`.match(/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}/i);
            const statusMatch = rowText.match(/\b(active|expired|pending|transferred away|cancelled|fraud)\b/i);

            return {
                href: link.href,
                name: domainMatch?.[0] ?? '',
                status: statusMatch?.[0]?.toUpperCase() ?? null,
            };
        }),
    );

    const unique = new Map();
    for (const record of records) {
        const name = normalizeDomain(record.name);
        if (name && !unique.has(name)) {
            unique.set(name, { ...record, name });
        }
    }

    return [...unique.values()];
}

export async function extractNameservers(page) {
    const values = await page
        .locator('input[name="ns1"], input[name="ns2"], input[name="ns3"], input[name="ns4"], input[name="ns5"]')
        .evaluateAll((inputs) => inputs.map((input) => input.value));

    return normalizeNameservers(values);
}

export async function extractDomainMetadata(page) {
    const text = await page.locator('body').innerText().catch(() => '');
    const readDate = (labels) => {
        const match = text.match(
            new RegExp(`(?:${labels})\\s*(?:date)?\\s*[:\\-]?\\s*([A-Za-z]{3,9}\\s+\\d{1,2},?\\s+\\d{4}|\\d{4}-\\d{1,2}-\\d{1,2}|\\d{1,2}[\\/-]\\d{1,2}[\\/-]\\d{4})`, 'i'),
        );

        return match?.[1] ?? null;
    };
    const readBoolean = async (selectors, labels) => {
        for (const selector of selectors) {
            const input = page.locator(selector).first();
            if ((await input.count()) > 0) {
                return await input.isChecked().catch(() => null);
            }
        }

        const match = text.match(new RegExp(`(?:${labels})\\s*[:\\-]?\\s*(enabled|active|yes|on|disabled|inactive|no|off)`, 'i'));
        if (!match) {
            return null;
        }

        return /^(enabled|active|yes|on)$/i.test(match[1]);
    };
    const renewalMatch = text.match(/renewal(?:\s+(?:price|cost))?\s*[:\-]?\s*(?:USD\s*)?\$\s*([\d,.]+)/i);

    return {
        renewal_price: renewalMatch ? Number(renewalMatch[1].replaceAll(',', '')) : null,
        registered_at: readDate('registration|registered|creation|created'),
        expires_at: readDate('expiry|expiration|expires|due'),
        is_locked: await readBoolean(['input[name*="lock" i]'], 'registrar lock|domain lock|locked'),
        privacy_enabled: await readBoolean(['input[name*="privacy" i]', 'input[name*="idprotect" i]'], 'privacy|id protection|whois protection'),
        auto_renew: await readBoolean(['input[name*="autorenew" i]', 'input[name*="auto_renew" i]'], 'auto[ -]?renew'),
    };
}

async function firstVisible(page, selectors) {
    for (const selector of selectors) {
        const locator = page.locator(selector);
        const count = await locator.count();
        for (let index = 0; index < count; index++) {
            const candidate = locator.nth(index);
            if (await candidate.isVisible()) {
                return candidate;
            }
        }
    }

    return null;
}

async function bodyText(page) {
    return (
        await page
            .locator('body')
            .innerText()
            .catch(() => '')
    ).toLowerCase();
}

async function hasAuthenticationChallenge(page) {
    const text = await bodyText(page);
    const recaptchaCount = await page.locator('iframe[src*="recaptcha"], iframe[title*="reCAPTCHA"]').count();

    return recaptchaCount > 0 || /two[- ]factor|verification code|one[- ]time|authenticator|captcha/.test(text);
}

async function isLoginPage(page) {
    if (/\/login|rp=%2flogin|rp=\/login/i.test(page.url())) {
        return true;
    }

    const password = await firstVisible(page, ['#inputPassword', 'input[name="password"]', 'input[type="password"]']);
    const email = await firstVisible(page, ['#inputEmail', 'input[name="email"]', 'input[type="email"]']);

    return password !== null && email !== null;
}

async function authenticate(page, input) {
    await page.goto(input.config.domains_url, { waitUntil: 'domcontentloaded' });
    if (!(await isLoginPage(page))) {
        return;
    }

    if (!input.account.email || !input.account.password) {
        throw new ZComAutomationError(ErrorCategory.Authentication, 'Z.com credentials are missing or the saved session has expired.');
    }

    await page.goto(input.config.login_url, { waitUntil: 'domcontentloaded' });
    if (await hasAuthenticationChallenge(page)) {
        throw new ZComAutomationError(ErrorCategory.ActionRequired, 'Z.com requires an interactive authentication step.');
    }

    const email = await firstVisible(page, ['#inputEmail', 'input[name="email"]', 'input[type="email"]']);
    const password = await firstVisible(page, ['#inputPassword', 'input[name="password"]', 'input[type="password"]']);
    const submit = await firstVisible(page, ['form button[type="submit"]', 'form input[type="submit"]']);

    if (!email || !password || !submit) {
        throw new ZComAutomationError(ErrorCategory.ProviderChanged, 'The Z.com login form could not be recognized.');
    }

    await email.fill(input.account.email);
    await password.fill(input.account.password);
    await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => null), submit.click()]);

    if (await hasAuthenticationChallenge(page)) {
        throw new ZComAutomationError(ErrorCategory.ActionRequired, 'Z.com requires an OTP, CAPTCHA, or another interactive authentication step.');
    }

    if (await isLoginPage(page)) {
        throw new ZComAutomationError(ErrorCategory.Authentication, 'Z.com rejected the email, password, or saved session.');
    }
}

async function collectAllDomainLinks(page, domainsUrl) {
    await page.goto(domainsUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);

    const domains = new Map();
    for (let pageNumber = 1; pageNumber <= 20; pageNumber++) {
        for (const record of await extractDomainLinks(page)) {
            domains.set(record.name, record);
        }

        const next = await firstVisible(page, [
            '#tableDomains_next:not(.disabled) a',
            '.dataTables_paginate .next:not(.disabled) a',
            'a[aria-label="Next"]:not([aria-disabled="true"])',
        ]);
        if (!next) {
            break;
        }

        await next.click();
        await page.waitForTimeout(300);
    }

    if (domains.size === 0) {
        const text = await bodyText(page);
        if (!/no domains|no records|you do not currently have any domains/.test(text)) {
            throw new ZComAutomationError(ErrorCategory.ProviderChanged, 'The Z.com domain list could not be recognized.');
        }
    }

    return [...domains.values()];
}

async function findDomain(page, input, requestedDomain) {
    const domain = normalizeDomain(requestedDomain);
    const links = await collectAllDomainLinks(page, input.config.domains_url);
    const match = links.find((record) => record.name === domain);

    if (!match) {
        throw new ZComAutomationError(ErrorCategory.DomainNotFound, `${domain} was not found in the Z.com account.`);
    }

    return match;
}

async function openNameserverPanel(page, detailUrl) {
    await page.goto(detailUrl, { waitUntil: 'domcontentloaded' });

    if (await isLoginPage(page)) {
        throw new ZComAutomationError(ErrorCategory.Authentication, 'The Z.com session expired while opening the domain.');
    }

    let nameservers = await extractNameservers(page);
    if (nameservers.length > 0) {
        return;
    }

    const tab = await firstVisible(page, [
        'a[href="#tabNameservers"]',
        '[data-target="#tabNameservers"]',
        'a[href*="modop=custom&a=management&domainid="]',
    ]);
    if (tab) {
        await tab.click();
        await page.waitForTimeout(250);
        nameservers = await extractNameservers(page);
    }

    if (nameservers.length === 0) {
        throw new ZComAutomationError(ErrorCategory.ProviderChanged, 'The Z.com nameserver form could not be recognized.');
    }
}

async function getNameservers(page, input, domain) {
    const record = await findDomain(page, input, domain);
    await openNameserverPanel(page, record.href);

    return { record, nameservers: await extractNameservers(page) };
}

async function setNameservers(page, input) {
    const target = normalizeNameservers(input.payload.nameservers ?? []);
    if (target.length < 2 || target.length > 5) {
        throw new ZComAutomationError(ErrorCategory.Validation, 'Z.com requires between two and five unique nameservers.');
    }

    const current = await getNameservers(page, input, input.payload.domain);
    if (nameserversEqual(current.nameservers, target)) {
        return { accepted: true, nameservers: current.nameservers, skipped: true };
    }

    const customOption = await firstVisible(page, ['input[name="nstype"][value="custom"]']);
    if (customOption && !(await customOption.isChecked())) {
        await customOption.check();
    }

    const fields = page.locator('input[name="ns1"], input[name="ns2"], input[name="ns3"], input[name="ns4"], input[name="ns5"]');
    const fieldCount = await fields.count();
    if (fieldCount < target.length) {
        throw new ZComAutomationError(ErrorCategory.ProviderChanged, 'The Z.com nameserver form has fewer fields than expected.');
    }

    for (let index = 0; index < fieldCount; index++) {
        await fields.nth(index).fill(target[index] ?? '');
    }

    const form = fields.nth(0).locator('xpath=ancestor::form[1]');
    const submit = await firstVisible(form, ['button[type="submit"]', 'input[type="submit"]']);
    if (!submit) {
        throw new ZComAutomationError(ErrorCategory.ProviderChanged, 'The Z.com nameserver save control could not be recognized.');
    }

    await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => null), submit.click()]);

    const errorAlert = await firstVisible(page, ['.alert-danger', '.errorbox', '[role="alert"].alert-danger']);
    if (errorAlert) {
        throw new ZComAutomationError(ErrorCategory.Validation, (await errorAlert.innerText()).trim().slice(0, 500));
    }

    await openNameserverPanel(page, current.record.href);
    const observed = await extractNameservers(page);
    if (!nameserversEqual(observed, target)) {
        throw new ZComAutomationError(ErrorCategory.Conflict, 'Z.com did not retain the requested nameservers after saving.');
    }

    return { accepted: true, nameservers: observed, skipped: false };
}

async function listDomains(page, input) {
    const links = await collectAllDomainLinks(page, input.config.domains_url);
    const domains = [];

    for (const record of links) {
        await openNameserverPanel(page, record.href);
        const metadata = await extractDomainMetadata(page);
        domains.push({
            name: record.name,
            nameservers: await extractNameservers(page),
            status: record.status,
            ...metadata,
        });
    }

    return { domains, next_page: null };
}

async function saveDiagnostic(page, input) {
    if (!input.config.diagnostics_path) {
        return;
    }

    await page
        .locator('input')
        .evaluateAll((inputs) => {
            for (const input of inputs) {
                input.value = '[redacted]';
            }
        })
        .catch(() => null);
    await page
        .locator('.client-details, .contact-info, [class*="contact"]')
        .evaluateAll((elements) => {
            for (const element of elements) {
                element.style.visibility = 'hidden';
            }
        })
        .catch(() => null);

    await mkdir(input.config.diagnostics_path, { recursive: true });
    const cutoff = Date.now() - 7 * 24 * 60 * 60 * 1000;
    for (const entry of await readdir(input.config.diagnostics_path)) {
        const diagnosticPath = path.join(input.config.diagnostics_path, entry);
        const metadata = await stat(diagnosticPath);
        if (metadata.isFile() && metadata.mtimeMs < cutoff) {
            await unlink(diagnosticPath);
        }
    }
    const filename = `${input.operation}-${Date.now()}.png`;
    await page.screenshot({ path: path.join(input.config.diagnostics_path, filename), fullPage: true });
}

function validateInput(input) {
    if (!input || typeof input !== 'object' || typeof input.operation !== 'string') {
        throw new ZComAutomationError(ErrorCategory.Validation, 'The browser operation is missing.');
    }
    if (!input.account || typeof input.account !== 'object' || !input.config || typeof input.config !== 'object') {
        throw new ZComAutomationError(ErrorCategory.Validation, 'The browser request is incomplete.');
    }
    if (!input.config.login_url || !input.config.domains_url) {
        throw new ZComAutomationError(ErrorCategory.Validation, 'The Z.com portal URLs are not configured.');
    }
}

export async function runZComAutomation(input) {
    validateInput(input);

    const launchOptions = { headless: input.config.headless !== false };
    if (input.config.browser_executable_path) {
        launchOptions.executablePath = input.config.browser_executable_path;
    }

    let browser;
    let page;
    try {
        browser = await chromium.launch(launchOptions);
        const contextOptions = input.account.storage_state ? { storageState: input.account.storage_state } : {};
        const context = await browser.newContext(contextOptions);
        page = await context.newPage();
        page.setDefaultTimeout(Number(input.config.navigation_timeout_ms ?? 45000));
        page.setDefaultNavigationTimeout(Number(input.config.navigation_timeout_ms ?? 45000));

        await authenticate(page, input);

        let data;
        switch (input.operation) {
            case 'test_connection':
                data = { message: 'Connection successful.' };
                break;
            case 'list_domains':
                data = await listDomains(page, input);
                break;
            case 'get_nameservers':
                data = { nameservers: (await getNameservers(page, input, input.payload.domain)).nameservers };
                break;
            case 'set_nameservers':
                data = await setNameservers(page, input);
                break;
            default:
                throw new ZComAutomationError(ErrorCategory.Validation, 'The requested browser operation is not supported.');
        }

        return {
            successful: true,
            data,
            storage_state: await context.storageState(),
        };
    } catch (error) {
        const automationError =
            error instanceof ZComAutomationError
                ? error
                : new ZComAutomationError(
                      /timeout|navigation|net::/i.test(error?.message ?? '') ? ErrorCategory.Network : ErrorCategory.ProviderTemporary,
                      /timeout|navigation|net::/i.test(error?.message ?? '')
                          ? 'Unable to reach the Z.com portal.'
                          : 'The Z.com browser operation failed unexpectedly.',
                  );

        if (page && ![ErrorCategory.Authentication, ErrorCategory.ActionRequired].includes(automationError.category)) {
            await saveDiagnostic(page, input).catch(() => null);
        }

        return {
            successful: false,
            error: {
                category: automationError.category,
                message: automationError.message,
                provider_code: automationError.providerCode,
            },
        };
    } finally {
        await browser?.close();
    }
}

async function readStandardInput() {
    let input = '';
    process.stdin.setEncoding('utf8');
    for await (const chunk of process.stdin) {
        input += chunk;
    }

    return JSON.parse(input);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        process.stdout.write(JSON.stringify(await runZComAutomation(await readStandardInput())));
    } catch {
        process.stdout.write(
            JSON.stringify({
                successful: false,
                error: {
                    category: ErrorCategory.Validation,
                    message: 'The browser request was not valid JSON.',
                    provider_code: null,
                },
            }),
        );
    }
}
