#!/usr/bin/env node
/**
 * Generates public/integrations/icons/<key>.svg for every integration manifest:
 *   1. full-colour logo from Iconify "logos" (CC0, Gil Barbara)
 *   2. else the Simple Icons brand mark in its brand colour (CC0)
 *   3. else a lettered tile in the category colour
 *   3. else (with --favicons) the app's own favicon/app icon at 256px via Google's favicon service, kept as <key>.png
 *      only when it is at least 48px (smaller favicons look blurry)
 *   4. else a lettered tile in the category colour
 * Near-black single-colour logos are written as <key>.mono.svg so the UI can invert them in dark mode.
 *
 * Run after adding manifests:  node scripts/build-integration-icons.mjs [--favicons]
 * (dev-only: needs the @iconify-json/logos and simple-icons devDependencies; the output is committed)
 */
import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync, statSync } from 'node:fs';
import { join, extname } from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const root = new URL('..', import.meta.url).pathname;
const out = join(root, 'public/integrations/icons');
const logos = require('@iconify-json/logos/icons.json');
const simple = await import('simple-icons');

// Hand-picked matches where names don't line up.
const OVERRIDES = {
    gmail: 'logos:google-gmail', google_sheets: 'logos:google-sheets', google_drive: 'logos:google-drive', google_calendar: 'logos:google-calendar',
    google_docs: 'si:googledocs', google_forms: 'si:googleforms', google_analytics: 'logos:google-analytics', google_maps: 'logos:google-maps',
    google_gemini: 'logos:google-gemini', google_translate: 'si:googletranslate', google_chat: 'si:googlechat', google_tasks: 'si:googletasks',
    google_slides: 'si:googleslides', google_search_console: 'si:googlesearchconsole', google_ads: 'logos:google-ads', google_contacts: 'si:googlecontacts',
    microsoft_teams: 'logos:microsoft-teams', microsoft_outlook: 'logos:microsoft-icon', microsoft_excel: 'si:microsoftexcel',
    x_twitter: 'logos:x', facebook_pages: 'logos:facebook', meta_ads: 'logos:meta-icon', whatsapp_business: 'logos:whatsapp-icon',
    openai: 'logos:openai-icon', anthropic: 'si:anthropic', azure_openai: 'logos:microsoft-azure', mistral: 'logos:mistral-ai-icon',
    hugging_face: 'logos:hugging-face-icon', mailchimp: 'logos:mailchimp-freddie', github: 'logos:github-icon', notion: 'logos:notion-icon',
    slack: 'logos:slack-icon', discord: 'logos:discord-icon', telegram: 'logos:telegram', stripe: 'logos:stripe', airtable: 'logos:airtable',
};

const manifests = [];
(function walk(dir) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) walk(path);
        else if (extname(name) === '.json') manifests.push(JSON.parse(readFileSync(path, 'utf8')));
        else if (/\.ya?ml$/.test(name)) {
            const key = readFileSync(path, 'utf8').match(/^\s+key:\s*([\w.-]+)/m)?.[1];
            if (key) manifests.push({ key, name: key, icon: 'lucide' });
        }
    }
})(join(root, 'resources/integrations'));

const CATEGORY_COLORS = {
    AI: '#a855f7', Communication: '#3b82f6', 'Marketing & Email': '#ec4899', 'CRM & Sales': '#f97316', 'Customer Support': '#06b6d4',
    Productivity: '#22c55e', 'Project Management': '#6366f1', 'Files & Documents': '#0ea5e9', 'Developer Tools': '#475569', Databases: '#14b8a6',
    'E-commerce': '#84cc16', 'Payments & Finance': '#10b981', 'Social Media': '#e11d48', Analytics: '#f59e0b', 'Forms & Surveys': '#d946ef',
    'HR & Recruiting': '#fb7185', 'CMS & Websites': '#0284c7', Utilities: '#78716c', 'Ads & SEO': '#ea580c', 'Video & Media': '#dc2626',
    'Security & IT': '#334155', 'Events & Webinars': '#7c3aed', Education: '#2563eb', 'IoT & Smart Home': '#0d9488', 'Crypto & Web3': '#ca8a04',
};

const luminance = (hex) => {
    const h = hex.replace('#', '');
    const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h.slice(0, 6);
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

function iconify(name) {
    let icon = logos.icons[name];
    if (!icon && logos.aliases?.[name]) icon = logos.icons[logos.aliases[name].parent];
    if (!icon) return null;
    const w = icon.width ?? logos.width ?? 256;
    const h = icon.height ?? logos.height ?? 256;
    const colors = [...icon.body.matchAll(/(?:fill|stop-color)(?:=|:)\s*"?(#[0-9a-fA-F]{3,8})/g)].map((m) => m[1].toLowerCase())
        .filter((c) => !['#fff', '#ffffff', '#ffffffff'].includes(c));
    const mono = colors.length === 0 ? !/fill="(?!none)/.test(icon.body) || true : colors.every((c) => luminance(c) < 0.06);
    return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}">${icon.body}</svg>`, mono: colors.length === 0 ? true : mono, wide: w / h > 1.6 };
}

const simpleBySlug = Object.fromEntries(Object.values(simple).filter((i) => i?.slug).map((i) => [i.slug, i]));
function simpleIcon(slug) {
    const icon = simpleBySlug[slug];
    if (!icon) return null;
    const lum = luminance(icon.hex);
    const fill = lum > 0.8 ? '#333333' : `#${icon.hex}`;
    return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="${fill}" d="${icon.path}"/></svg>`, mono: lum < 0.06 || lum > 0.8 };
}

function monogram(m) {
    const words = (m.name ?? m.key).replace(/[^A-Za-z0-9 ]/g, ' ').trim().split(/\s+/);
    const text = (words.length > 1 ? words[0][0] + words[1][0] : words[0].slice(0, 2)).toUpperCase();
    const color = CATEGORY_COLORS[m.category] ?? '#64748b';
    return { svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="${color}"/><text x="32" y="41" text-anchor="middle" font-family="Inter,Segoe UI,Helvetica,Arial,sans-serif" font-size="25" font-weight="700" fill="#fff">${text}</text></svg>`, mono: false };
}

function find(m) {
    const override = OVERRIDES[m.key];
    const forced = override && (override.startsWith('si:') ? simpleIcon(override.slice(3)) : iconify(override.slice(6)));
    if (forced) return forced;

    const bases = [m.brand, m.key, m.name].filter(Boolean).map((s) => String(s).toLowerCase());
    const kebabs = [...new Set(bases.flatMap((b) => [b.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''), b.replace(/[^a-z0-9]/g, '')]))];
    for (const k of kebabs) {
        const hit = iconify(`${k}-icon`) ?? iconify(k);
        if (hit && !hit.wide) return hit;
    }
    for (const k of kebabs) {
        const hit = simpleIcon(k.replace(/-/g, ''));
        if (hit) return hit;
    }
    for (const k of kebabs) {
        const hit = iconify(k); // wide wordmark as a last brand-accurate resort
        if (hit) return hit;
    }
    return null;
}

const host = (m) => {
    for (const url of [m.website, m.docs_url, m.base_url]) {
        try {
            const h = new URL(String(url).replace(/\{\{[^}]*\}\}\.?/g, '')).hostname.replace(/^(www|api|docs|developers?|developer)\./, '');
            if (h.includes('.')) return h;
        } catch { /* not a URL */ }
    }
    return null;
};

const pngWidth = (buf) => (buf.length > 24 && buf.toString('ascii', 1, 4) === 'PNG' ? buf.readUInt32BE(16) : 0);

async function favicon(m) {
    const domain = host(m);
    if (!domain) return null;
    // Try the exact host, then the registrable root (app.example.com → example.com); accept >= 48px.
    const root = domain.split('.').slice(-2).join('.');
    for (const candidate of [...new Set([domain, root, `www.${root}`])]) {
        try {
            const res = await fetch(`https://www.google.com/s2/favicons?domain=${encodeURIComponent(candidate)}&sz=256`, { redirect: 'follow', signal: AbortSignal.timeout(8000) });
            if (!res.ok) continue;
            const buf = Buffer.from(await res.arrayBuffer());
            if (pngWidth(buf) >= 48) return { buf, ext: 'png' };
        } catch { /* try the next candidate */ }
    }
    // Then the site's own touch icon / SVG icon, read from its homepage.
    for (const site of [...new Set([`https://${domain}`, `https://www.${root}`, `https://${root}`])]) {
        try {
            const res = await fetch(site, { redirect: 'follow', signal: AbortSignal.timeout(8000), headers: { 'User-Agent': 'Mozilla/5.0 (FlowForge icon builder)' } });
            if (!res.ok) continue;
            const html = (await res.text()).slice(0, 300000);
            const links = [...html.matchAll(/<link\b[^>]*>/gi)].map((m) => m[0]);
            const attr = (tag, name) => tag.match(new RegExp(`${name}\\s*=\\s*["']([^"']+)["']`, 'i'))?.[1];
            const ranked = links.map((tag) => ({ rel: (attr(tag, 'rel') ?? '').toLowerCase(), href: attr(tag, 'href'), sizes: parseInt(attr(tag, 'sizes') ?? '0', 10) || 0, type: attr(tag, 'type') ?? '' }))
                .filter((l) => l.href && /icon/.test(l.rel) && !/mask-icon/.test(l.rel))
                .sort((a, b) => (b.type.includes('svg') || b.href.endsWith('.svg') ? 1000 : b.rel.includes('apple') ? 500 + b.sizes : b.sizes) - (a.type.includes('svg') || a.href.endsWith('.svg') ? 1000 : a.rel.includes('apple') ? 500 + a.sizes : a.sizes));
            ranked.push({ href: '/apple-touch-icon.png' });
            for (const link of ranked.slice(0, 4)) {
                try {
                    const url = new URL(link.href.replace(/&amp;/g, '&'), res.url).href;
                    const icon = await fetch(url, { signal: AbortSignal.timeout(8000), headers: { 'User-Agent': 'Mozilla/5.0' } });
                    if (!icon.ok) continue;
                    const buf = Buffer.from(await icon.arrayBuffer());
                    const text = buf.toString('utf8', 0, Math.min(buf.length, 2000));
                    if (/<svg[\s>]/i.test(text) && buf.length < 200000 && !/<script/i.test(buf.toString('utf8'))) return { buf, ext: 'svg' };
                    if (pngWidth(buf) >= 48) return { buf, ext: 'png' };
                } catch { /* next link */ }
            }
        } catch { /* next site */ }
    }
    return null;
}

mkdirSync(out, { recursive: true });
const fetchFavicons = process.argv.includes('--favicons');
const keys = new Set();
const report = { logo: 0, favicon: 0, monogram: [] };
const pending = [];

for (const m of manifests) {
    if (m.icon && !m.icon.startsWith('/') && !m.icon.startsWith('http')) continue; // built-in nodes keep their Lucide icon
    keys.add(m.key);
    const icon = find(m);
    if (icon) {
        report.logo++;
        writeFileSync(join(out, `${m.key}${icon.mono ? '.mono' : ''}.svg`), icon.svg);
    } else {
        pending.push(m);
    }
}

// Favicons are fetched once and kept; re-runs reuse an existing <key>.png.
const existing = new Set(readdirSync(out));
for (let i = 0; i < pending.length; i += 8) {
    await Promise.all(pending.slice(i, i + 8).map(async (m) => {
        if (existing.has(`${m.key}.png`) || existing.has(`${m.key}.site.svg`)) { rmSync(join(out, `${m.key}.svg`), { force: true }); report.favicon++; return; }
        const found = fetchFavicons ? await favicon(m) : null;
        if (found) {
            writeFileSync(join(out, found.ext === 'svg' ? `${m.key}.site.svg` : `${m.key}.png`), found.buf);
            rmSync(join(out, `${m.key}.svg`), { force: true }); // drop the lettered tile from an earlier run
            report.favicon++;
        } else {
            writeFileSync(join(out, `${m.key}.svg`), monogram(m).svg);
            report.monogram.push(m.key);
        }
    }));
}

// Remove stale files (deleted apps, or an app that now resolves to a different format).
for (const file of readdirSync(out)) {
    const key = file.replace(/(\.mono|\.site)?\.(svg|png)$/, '');
    const wanted = !keys.has(key) ? false
        : file.endsWith('.png') || file.endsWith('.site.svg') ? !existsLogo(key) && !report.monogram.includes(key)
        : file.endsWith('.mono.svg') ? true : true;
    if (!wanted) rmSync(join(out, file));
}
function existsLogo(key) {
    return readdirSync(out).some((f) => f === `${key}.svg` && !report.monogram.includes(key) || f === `${key}.mono.svg`);
}

console.log(`${report.logo} library logos, ${report.favicon} app icons (favicon), ${report.monogram.length} lettered tiles.`);
if (report.monogram.length) console.log('Lettered:', report.monogram.join(', '));
process.exit(0); // keep-alive sockets from the favicon fetches would otherwise keep Node running
