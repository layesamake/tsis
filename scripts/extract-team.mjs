// Extraction des membres de l'équipe : nom (h3), rôle (p suivant), photo (data-src précédent).
import { readFile, writeFile } from 'node:fs/promises';
const html = await readFile(new URL('../docs/site-source/pages/a-propos.html', import.meta.url), 'utf8');
const decode = (s) => s.replace(/<[^>]*>/g, '').replace(/&#8217;|&#039;/g, "'").replace(/&#038;|&amp;/g, '&')
  .replace(/&#8211;/g, '–').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();

const start = html.indexOf('Des talents engag');
const end = html.indexOf('Parlons de votre projet', start);
const section = html.slice(start, end > start ? end : start + 300000);

const team = [];
const re = /<h3[^>]*>([\s\S]*?)<\/h3>/gi;
let m;
while ((m = re.exec(section))) {
  const name = decode(m[1]);
  if (!name || name.length > 60) continue;
  const after = section.slice(m.index, m.index + 400);
  const roleM = after.match(/<p[^>]*>([\s\S]*?)<\/p>/i);
  const role = roleM ? decode(roleM[1]) : '';
  const before = section.slice(Math.max(0, m.index - 900), m.index);
  const srcs = [...before.matchAll(/data-src="([^"]+)"/g)];
  const img = srcs.length ? srcs[srcs.length - 1][1] : null;
  team.push({ name, role: role.length < 60 ? role : '', img });
}

console.log(JSON.stringify(team, null, 2));
await writeFile(new URL('../docs/site-source/api/team.json', import.meta.url), JSON.stringify(team, null, 2), 'utf8');
console.log('\ntotal:', team.length, '| avec rôle:', team.filter(t=>t.role).length, '| avec photo:', team.filter(t=>t.img).length);
