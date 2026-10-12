// Télécharge les photos de l'équipe et génère astro/src/data/team.ts
import { readFile, writeFile, mkdir } from 'node:fs/promises';
const team = JSON.parse(await readFile(new URL('../docs/site-source/api/team.json', import.meta.url), 'utf8'));
const OUT = new URL('../astro/public/uploads/team/', import.meta.url);
await mkdir(OUT, { recursive: true });

const out = [];
for (const t of team) {
  if (!t.img) { out.push({ name: t.name, role: t.role, photo: null }); continue; }
  const file = t.img.split('/').pop();
  try {
    const res = await fetch(t.img, { headers: { 'User-Agent': 'tsis-rebuild/1.0' } });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const buf = Buffer.from(await res.arrayBuffer());
    await writeFile(new URL(file, OUT), buf);
    console.log('OK', file, buf.length, 'bytes');
    out.push({ name: t.name, role: t.role, photo: `/uploads/team/${file}` });
  } catch (e) {
    console.log('ÉCHEC', file, String(e));
    out.push({ name: t.name, role: t.role, photo: null });
  }
}

const header = '// FICHIER GÉNÉRÉ — ne pas éditer à la main. Source : scripts/fetch-team-photos.mjs\n\n';
await writeFile(
  new URL('../astro/src/data/team.ts', import.meta.url),
  header +
    'export interface TeamMember { name: string; role: string; photo: string | null }\n\n' +
    `export const TEAM: TeamMember[] = ${JSON.stringify(out, null, 2)};\n`,
  'utf8'
);
console.log('\ntotal:', out.length, '| photos:', out.filter((t) => t.photo).length);
