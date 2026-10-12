// Génère astro/src/data/{services,realisations,secteurs}.ts depuis les exports de l'API WordPress.
import { readFile, writeFile, mkdir } from 'node:fs/promises';

const API = new URL('../docs/site-source/api/', import.meta.url);
const OUT = new URL('../astro/src/data/', import.meta.url);
await mkdir(OUT, { recursive: true });

const load = async (f) => JSON.parse(await readFile(new URL(f, API), 'utf8'));
const strip = (h = '') =>
  h.replace(/<[^>]*>/g, '')
    .replace(/&#8217;|&#039;|&#8216;/g, "'")
    .replace(/&#8220;|&#8221;/g, '"')
    .replace(/&#038;|&amp;/g, '&')
    .replace(/&nbsp;/g, ' ')
    .replace(/&#8230;/g, '…')
    .replace(/\s+/g, ' ')
    .trim();

const services = await load('services.json');
const realisations = await load('realisations.json');
const secteurs = await load('secteurs.json');
const media = await load('media.json');

const mediaUrl = (id) => media.find((m) => m.id === id)?.source_url ?? null;
const mediaAlt = (id) => strip(media.find((m) => m.id === id)?.alt_text ?? '');

const secteursMap = Object.fromEntries(secteurs.map((s) => [s.id, strip(s.name)]));

const svc = services.map((s) => ({
  slug: s.slug,
  title: strip(s.title.rendered),
  excerpt: strip(s.excerpt?.rendered ?? ''),
  order: 100 - (s.menu_order ?? 0),
})).sort((a, b) => a.slug.localeCompare(b.slug));

/** Réécrit une URL d'upload WordPress vers le fichier local /uploads/<fichier>. */
const localUpload = (url) =>
  url ? '/uploads/' + url.split('/').pop().split('?')[0] : null;

const real = realisations.map((r) => ({
  slug: r.slug,
  title: strip(r.title.rendered),
  excerpt: strip(r.excerpt?.rendered ?? ''),
  secteurs: (r.secteur ?? []).map((id) => secteursMap[id]).filter(Boolean),
  image: localUpload(mediaUrl(r.featured_media)),
  imageAlt: mediaAlt(r.featured_media) || strip(r.title.rendered),
}));

const sec = secteurs
  .map((s) => ({ slug: s.slug, name: strip(s.name), count: s.count }))
  .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));

const header = '// FICHIER GÉNÉRÉ — ne pas éditer à la main. Source : scripts/gen-data.mjs\n\n';

await writeFile(
  new URL('services.ts', OUT),
  header +
    'export interface Service { slug: string; title: string; excerpt: string; order: number }\n\n' +
    `export const SERVICES: Service[] = ${JSON.stringify(svc, null, 2)};\n`,
  'utf8'
);

await writeFile(
  new URL('realisations.ts', OUT),
  header +
    'export interface Realisation {\n  slug: string;\n  title: string;\n  excerpt: string;\n  secteurs: string[];\n  image: string | null;\n  imageAlt: string;\n}\n\n' +
    `export const REALISATIONS: Realisation[] = ${JSON.stringify(real, null, 2)};\n`,
  'utf8'
);

await writeFile(
  new URL('secteurs.ts', OUT),
  header +
    'export interface Secteur { slug: string; name: string; count: number }\n\n' +
    `export const SECTEURS: Secteur[] = ${JSON.stringify(sec, null, 2)};\n`,
  'utf8'
);

console.log(`services: ${svc.length}`);
console.log(`realisations: ${real.length} (avec image: ${real.filter((r) => r.image).length})`);
console.log(`secteurs: ${sec.length}`);
console.log('\n--- services ---');
for (const s of svc) console.log(` ${s.slug} :: ${s.title}`);
console.log('\n--- réalisations ---');
for (const r of real) console.log(` ${r.slug} :: ${r.title} [${r.secteurs.join(', ')}] ${r.image ? 'img✓' : 'img✗'}`);
