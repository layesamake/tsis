// Copie dist/ vers .preview/ en réécrivant les chemins absolus en relatifs (profondeur gérée),
// pour ouvrir le site en local via file:// sans serveur.
import { readFile, writeFile, mkdir, readdir, copyFile } from 'node:fs/promises';
import { join, relative } from 'node:path';

const SRC = new URL('../astro/dist/', import.meta.url).pathname;
const DST = new URL('../astro/.preview/', import.meta.url).pathname;

async function walk(dir) {
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const s = join(dir, e.name);
    const rel = relative(SRC, s);
    const d = join(DST, rel);
    if (e.isDirectory()) {
      await mkdir(d, { recursive: true });
      await walk(s);
    } else if (e.name.endsWith('.html')) {
      const depth = rel.split('/').length - 1;
      const up = depth === 0 ? './' : '../'.repeat(depth);
      let html = await readFile(s, 'utf8');
      html = html
        .replaceAll('href="/_astro/', `href="${up}_astro/`)
        .replaceAll('src="/_astro/', `src="${up}_astro/`)
        .replaceAll('href="/fonts/', `href="${up}fonts/`)
        .replaceAll('href="/brand/', `href="${up}brand/`)
        .replaceAll('src="/brand/', `src="${up}brand/`)
        .replaceAll('href="/uploads/', `href="${up}uploads/`)
        .replaceAll('src="/uploads/', `src="${up}uploads/`)
        .replaceAll('href="/favicon', `href="${up}favicon`)
        .replace(/href="\/(?!\/)([^"]*)"/g, `href="${up}$1"`);
      await mkdir(join(d, '..'), { recursive: true });
      await writeFile(d, html, 'utf8');
    } else {
      await mkdir(join(d, '..'), { recursive: true });
      await copyFile(s, d);
    }
  }
}

await mkdir(DST, { recursive: true });
await walk(SRC);
console.log('preview ->', DST);
