// Crawl du site rendu (FR) : sauvegarde HTML brut + extraction texte/structure
import { writeFile, mkdir } from 'node:fs/promises';

const urls = [
  ['accueil', 'https://preprod.tsis.sn/'],
  ['nos-services', 'https://preprod.tsis.sn/nos-services/'],
  ['nos-solutions', 'https://preprod.tsis.sn/nos-solutions/'],
  ['a-propos', 'https://preprod.tsis.sn/a-propos/'],
  ['contact', 'https://preprod.tsis.sn/contact/'],
  // services
  ['service-reseaux', 'https://preprod.tsis.sn/services/reseaux-informatiques-infrastructure-it/'],
  ['service-securite', 'https://preprod.tsis.sn/services/securite-informatique-videosurveillance/'],
  ['service-sites-vitrine', 'https://preprod.tsis.sn/services/creation-sites-web-vitrine-e-commerce/'],
  ['service-files-attente', 'https://preprod.tsis.sn/services/gestion-files-attente-bornes-interactives/'],
  ['service-erp', 'https://preprod.tsis.sn/services/creation-sites-web-erp-sur-mesure/'],
  ['service-materiel', 'https://preprod.tsis.sn/services/fourniture-materiel-informatique-mobilier-bureau/'],
  ['service-logiciels', 'https://preprod.tsis.sn/services/developpement-logiciels-applications/'],
  ['service-ia', 'https://preprod.tsis.sn/services/automatisation-processus-intelligence-artificielle/'],
  ['service-marketing', 'https://preprod.tsis.sn/services/marketing-digital-design-graphique/'],
  // réalisations
  ['real-formation', 'https://preprod.tsis.sn/realisations/formation-entreprise/'],
  ['real-securite-pointage', 'https://preprod.tsis.sn/realisations/securite-pointage/'],
  ['real-sunuflix', 'https://preprod.tsis.sn/realisations/sunu-flix/'],
  ['real-khidma', 'https://preprod.tsis.sn/realisations/khidma-gestion-chantier/'],
  ['real-guides', 'https://preprod.tsis.sn/realisations/guides-gestion-hospitaliere/'],
  ['real-diaba-commercial', 'https://preprod.tsis.sn/realisations/diaba-commerciale/'],
  ['real-touba-transfert', 'https://preprod.tsis.sn/realisations/touba-transfert/'],
  ['real-diaba-marketplace', 'https://preprod.tsis.sn/realisations/diaba-market-place/'],
  ['real-assirou', 'https://preprod.tsis.sn/realisations/assirou-gestion-file-attente/'],
  ['real-bernde', 'https://preprod.tsis.sn/realisations/bernde/'],
  ['real-louma', 'https://preprod.tsis.sn/realisations/louma-e-commerce/'],
];

const dir = new URL('../docs/site-source/pages/', import.meta.url);
await mkdir(dir, { recursive: true });

const decode = (s) => s.replace(/&#8217;|&#039;|&#8216;/g, "'").replace(/&#8220;|&#8221;|&quot;/g, '"')
  .replace(/&#038;|&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/&#8211;/g, '–').replace(/&hellip;/g, '…')
  .replace(/&#215;/g, '×').replace(/&#8594;/g, '→').replace(/\s+/g, ' ').trim();

function extract(html) {
  const out = { title: '', metaDesc: '', headings: [], paragraphs: [], images: [], links: [], buttons: [] };
  const t = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
  if (t) out.title = decode(t[1]);
  const md = html.match(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']*)["']/i);
  if (md) out.metaDesc = decode(md[1]);

  const seen = new Set();
  for (const m of html.matchAll(/<(h[1-6])[^>]*>([\s\S]*?)<\/\1>/gi)) {
    const txt = decode(m[2].replace(/<[^>]*>/g, ''));
    if (txt && !seen.has(m[1]+txt)) { seen.add(m[1]+txt); out.headings.push({ tag: m[1], text: txt }); }
  }
  for (const m of html.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)) {
    const txt = decode(m[1].replace(/<[^>]*>/g, ''));
    if (txt.length > 25) out.paragraphs.push(txt);
  }
  for (const m of html.matchAll(/<img[^>]*>/gi)) {
    const src = (m[0].match(/src=["']([^"']+)["']/) || [])[1];
    const alt = (m[0].match(/alt=["']([^"']*)["']/) || [])[1] || '';
    if (src && !src.includes('data:') && !out.images.find(i => i.src === src)) out.images.push({ src, alt: decode(alt) });
  }
  for (const m of html.matchAll(/<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi)) {
    const txt = decode(m[2].replace(/<[^>]*>/g, ''));
    if (txt && txt.length < 60 && !m[1].startsWith('#')) out.links.push({ href: m[1], text: txt });
  }
  // dédup contenu principal : ne garder que les paragraphes uniques
  out.paragraphs = [...new Set(out.paragraphs)];
  out.links = out.links.filter((l, i, a) => a.findIndex(x => x.href === l.href && x.text === l.text) === i).slice(0, 60);
  return out;
}

const summary = {};
for (const [name, url] of urls) {
  try {
    const res = await fetch(url, { headers: { 'User-Agent': 'tsis-rebuild/1.0' } });
    const html = await res.text();
    await writeFile(new URL(`${name}.html`, dir), html, 'utf8');
    const data = extract(html);
    await writeFile(new URL(`${name}.json`, dir), JSON.stringify(data, null, 2), 'utf8');
    summary[name] = { status: res.status, bytes: html.length, headings: data.headings.length, paras: data.paragraphs.length, imgs: data.images.length };
  } catch (e) { summary[name] = { error: String(e) }; }
}
console.log(JSON.stringify(summary, null, 2));
