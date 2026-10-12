// Digest compact du contenu des pages services et réalisations.
import { readFile, readdir } from 'node:fs/promises';
const dir = new URL('../docs/site-source/pages/', import.meta.url);
const files = (await readdir(dir)).filter((f) => f.endsWith('.json') && f !== '_index.json');

for (const f of files.sort()) {
  const d = JSON.parse(await readFile(new URL(f, dir), 'utf8'));
  console.log(`\n########## ${f.replace('.json','')} — ${d.title}`);
  console.log('H:', d.headings.map((h) => `${h.tag}:${h.text}`).join(' | '));
  console.log('P:');
  for (const p of d.paragraphs) console.log('   -', p.slice(0, 300));
}
