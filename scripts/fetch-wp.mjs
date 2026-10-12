// Extraction du contenu WordPress existant (preprod.tsis.sn) vers docs/site-source/
import { writeFile, mkdir } from 'node:fs/promises';

const BASE = process.env.WP_BASE || 'https://preprod.tsis.sn/wp-json/wp/v2';
const OUT = new URL('../docs/site-source/api/', import.meta.url);
await mkdir(OUT, { recursive: true });

const endpoints = {
  types: 'types',
  pages: 'pages?per_page=100&status=publish&_embed=1',
  services: 'service?per_page=100&status=publish&_embed=1',
  realisations: 'realisation?per_page=100&status=publish&_embed=1',
  posts: 'posts?per_page=100&status=publish&_embed=1',
  media: 'media?per_page=100&per_page=100',
  secteurs: 'secteur?per_page=100',
  categories: 'categories?per_page=100',
  menus: 'menus?per_page=100',
  users: 'users?per_page=100',
};

const summary = {};
for (const [name, path] of Object.entries(endpoints)) {
  const url = `${BASE}/${path}`;
  try {
    const res = await fetch(url, { headers: { 'User-Agent': 'tsis-rebuild/1.0' } });
    const text = await res.text();
    let count = null;
    try {
      const json = JSON.parse(text);
      count = Array.isArray(json) ? json.length : (json && typeof json === 'object' ? Object.keys(json).length : null);
    } catch { /* pas du JSON */ }
    await writeFile(new URL(`${name}.json`, OUT), text, 'utf8');
    summary[name] = { status: res.status, count, bytes: text.length };
  } catch (e) {
    summary[name] = { error: String(e) };
  }
}
console.log(JSON.stringify(summary, null, 2));
