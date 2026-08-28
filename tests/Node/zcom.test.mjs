import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { chromium } from 'playwright';
import { extractDomainLinks, extractNameservers, nameserversEqual, normalizeNameservers } from '../../app/Registrars/Browser/zcom.mjs';

test('normalizes duplicate nameservers', () => {
    assert.deepEqual(normalizeNameservers(['NS1.EXAMPLE.COM.', 'ns1.example.com', 'NS2.EXAMPLE.COM']), ['ns1.example.com', 'ns2.example.com']);
    assert.equal(nameserversEqual(['NS2.EXAMPLE.COM', 'ns1.example.com.'], ['ns1.example.com', 'ns2.example.com']), true);
    assert.equal(nameserversEqual(['ns1.example.com', 'ns2.example.com'], ['ns3.example.com', 'ns4.example.com']), false);
});

test('extracts domains and nameservers from sanitized portal fixtures', async (context) => {
    const browser = await chromium.launch({ headless: true });
    context.after(() => browser.close());
    const page = await browser.newPage();

    await page.setContent(await readFile(new URL('../Fixtures/zcom/domains.html', import.meta.url), 'utf8'));
    assert.deepEqual(await extractDomainLinks(page), [
        {
            href: 'https://my.web.z.com/clientarea.php?action=domaindetails&id=10',
            name: 'example.com',
            status: 'ACTIVE',
        },
        {
            href: 'https://my.web.z.com/clientarea.php?action=domaindetails&id=11',
            name: 'second.example.net',
            status: 'PENDING',
        },
    ]);

    await page.setContent(await readFile(new URL('../Fixtures/zcom/nameservers.html', import.meta.url), 'utf8'));
    assert.deepEqual(await extractNameservers(page), ['ns1.example.com', 'ns2.example.com']);
});
