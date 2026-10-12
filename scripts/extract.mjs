// Réextraction depuis le HTML déjà crawlé : gère data-src (lazy-load) et background-image
import { readFile, writeFile, readdir } from 'node:fs/promises';

const dir = new URL('../docs/site-source/pages/', import.meta.url);
const decode = (s) => s.replace(/&#8217;|&#039;|&#8216;/g, "'").replace(/&#8220;|&#8221;|&quot;/g, '"')
  .replace(/&#038;|&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/&#8211;/g, '–').replace(/&hellip;/g, '…')
  .replace(/&#215;/g, '×').replace(/&#8594;/g, '→').replace(/\s+/g, ' ').trim();

function extract(html) {
  const out = { title: '', metaDesc: '', ogImage: '', headings: [], paragraphs: [], images: [], links: [] };
  const t = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i); if (t) out.title = decode(t[1]);
  const md = html.match(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']*)["']/i); if (md) out.metaDesc = decode(md[1]);
  const og = html.match(/<meta[^>]+property=["']og:image["'][^>]+content=["']([^"']*)["']/i); if (og) out.ogImage = og[1];

  const seen = new Set();
  for (const m of html.matchAll(/<(h[1-6])[^>]*>([\s\S]*?)<\/\1>/gi)) {
    const txt = decode(m[2].replace(/<[^>]*>/g, ''));
    if (txt && !seen.has(m[1]+txt)) { seen.add(m[1]+txt); out.headings.push({ tag: m[1], text: txt }); }
  }
  for (const m of html.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)) {
    const txt = decode(m[1].replace(/<[^>]*>/g, ''));
    if (txt.length > 25) out.paragraphs.push(txt);
  }
  // images : src + data-src + data-lazy-src + srcset
  const pushImg = (u, alt='') => { if (u && !u.startsWith('data:') && !out.images.find(i=>i.src===u)) out.images.push({ src:u, alt:decode(alt) }); };
  for (const m of html.matchAll(/<img[^>]*>/gi)) {
    const tag = m[0];
    const alt = (tag.match(/alt=["']([^"']*)["']/)||[])[1]||'';
    for (const a of ['data-src','data-lazy-src','src']) { const u=(tag.match(new RegExp(a+'=["\']([^"\']+)["\']'))||[])[1]; if(u && !u.startsWith('data:')){ pushImg(u,alt); break; } }
  }
  // background-image: url(...)
  for (const m of html.matchAll(/background-image\s*:\s*url\((['"]?)([^)'"]+)\1\)/gi)) pushImg(m[2]);
  // liens
  for (const m of html.matchAll(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi)) {
    const txt = decode(m[2].replace(/<[^>]*>/g, ''));
    if (txt && txt.length < 60 && !m[1].startsWith('#')) out.links.push({ href:m[1], text:txt });
  }
  out.paragraphs = [...new Set(out.paragraphs)];
  out.links = out.links.filter((l,i,a)=>a.findIndex(x=>x.href===l.href&&x.text===l.text)===i).slice(0,80);
  return out;
}

const files = (await readdir(dir)).filter(f => f.endsWith('.html'));
const idx = {};
for (const f of files) {
  const name = f.replace(/\.html$/, '');
  const html = await readFile(new URL(f, dir), 'utf8');
  const data = extract(html);
  await writeFile(new URL(`${name}.json`, dir), JSON.stringify(data, null, 2), 'utf8');
  idx[name] = { title: data.title, headings: data.headings.length, paras: data.paragraphs.length, imgs: data.images.length, links: data.links.length };
}
await writeFile(new URL('_index.json', dir), JSON.stringify(idx, null, 2), 'utf8');
console.log(JSON.stringify(idx, null, 2));
