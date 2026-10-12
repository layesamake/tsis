import { readFile } from 'node:fs/promises';
const dir = new URL('../docs/site-source/api/', import.meta.url);
const load = async (f) => JSON.parse(await readFile(new URL(f, dir), 'utf8'));
const strip = (h='') => h.replace(/<[^>]*>/g,'').replace(/&#8217;|&#039;/g,"'").replace(/&#038;/g,'&').replace(/&amp;/g,'&').trim();

const services = await load('services.json');
const real = await load('realisations.json');
const pages = await load('pages.json');
const secteurs = await load('secteurs.json');

console.log('=== SERVICES (champs ACF) ===');
for (const s of services) {
  console.log(`#${s.id} ${s.slug} :: ${strip(s.title.rendered)}`);
  console.log('  acf:', JSON.stringify(s.acf));
}
console.log('\n=== RÉALISATIONS (champs ACF) ===');
for (const r of real) {
  console.log(`#${r.id} ${r.slug} :: ${strip(r.title.rendered)} | secteurs=[${(r.secteur||[]).join(',')}]`);
  console.log('  acf:', JSON.stringify(r.acf));
}
console.log('\n=== SECTEURS ===');
for (const s of secteurs) console.log(`#${s.id} ${s.slug} :: ${strip(s.name)} (${s.count})`);
console.log('\n=== PAGES: acf présent ? ===');
for (const p of pages) console.log(`${p.slug}: ${p.acf ? JSON.stringify(p.acf).slice(0,200) : 'PAS D\'ACF'}`);
