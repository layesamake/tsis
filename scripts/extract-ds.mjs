import { readFile, writeFile } from 'node:fs/promises';
const dir = new URL('../docs/site-source/', import.meta.url);
const html = await readFile(new URL('preprod-home.html', dir), 'utf8');

// Extraire le contenu de chaque bloc <style id="..."> ... </style>
const blocks = {};
const re = /<style id="([^"]+)"[^>]*>([\s\S]*?)<\/style>/g;
let m;
while ((m = re.exec(html))) blocks[m[1]] = m[2];

for (const [id, css] of Object.entries(blocks)) {
  await writeFile(new URL(`${id}.css`, dir), css.trim() + '\n', 'utf8');
  console.log(`${id}: ${css.length} chars`);
}

// Le design system seul
const ds = blocks['ev4sk-design-system-inline-css'];
if (ds) {
  await writeFile(new URL('design-system.css', dir), ds.trim() + '\n', 'utf8');
  const vars = [...ds.matchAll(/--ts-[a-z0-9-]+:\s*[^;]+;/g)].map(x => x[0]);
  console.log(`\n--- ${vars.length} variables --ts-* ---`);
  console.log(vars.join('\n'));
}
