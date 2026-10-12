/**
 * Pipeline de contenu WordPress → Astro.
 *
 * Deux modes :
 *  - « pont »    : GET /wp-json/tsis/v1/content (greffon tsis-headless-bridge.php), contenu complet ;
 *  - « REST public » : ce que WordPress expose déjà sans greffon (services, réalisations, secteurs,
 *    images, titres) ; le reste est conservé depuis l'instantané versionné.
 *
 * Aucune erreur réseau ne doit être fatale : les problèmes sont collectés et signalés, l'instantané
 * précédent sert de repli. Voir docs/contrat-contenu-wp.md.
 *
 * Ce module est du JavaScript (pas de TypeScript) : il est exécuté directement par Node depuis
 * scripts/pull-content.mjs et importé par la couche de contenu Astro. JSDoc pour l'intention.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

export const USER_AGENT = 'tsis-content-pull/1.0 (+https://tsis.sn)';

/* ------------------------------------------------------------------ *
 * Utilitaires
 * ------------------------------------------------------------------ */

/** Vrai si une valeur est réellement renseignée (une chaîne vide ne l'est pas). */
export function isFilled(value) {
  if (value === undefined || value === null) return false;
  if (typeof value === 'string') return value.trim() !== '';
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'object') return Object.keys(value).length > 0;
  return true;
}

const NAMED_ENTITIES = {
  amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00a0', hellip: '…',
  rsquo: '’', lsquo: '‘', ldquo: '“', rdquo: '”', ndash: '–', mdash: '—',
  eacute: 'é', egrave: 'è', agrave: 'à', ccedil: 'ç', ugrave: 'ù', ecirc: 'ê',
  deg: '°', eur: '€', times: '×', laquo: '«', raquo: '»', middot: '·', copy: '©',
};

/** Décode les entités HTML renvoyées par WordPress (titres, termes, extraits). */
export function decodeEntities(input) {
  return String(input ?? '')
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => safeChar(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec) => safeChar(Number(dec)))
    .replace(/&([a-z][a-z0-9]+);/gi, (match, name) => {
      const found = NAMED_ENTITIES[name.toLowerCase()];
      return found === undefined ? match : found;
    });
}

function safeChar(codePoint) {
  try {
    return String.fromCodePoint(codePoint);
  } catch {
    return '';
  }
}

/** Retire les balises HTML et normalise les espaces (extraits WordPress). */
export function stripTags(html) {
  return decodeEntities(String(html ?? '').replace(/<[^>]*>/g, ' '))
    .replace(/\s+/g, ' ')
    .trim();
}

/** Requête JSON avec délai maximum et sans exception sur erreur réseau. */
export async function httpJson(url, { timeoutMs = 20000 } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, {
      headers: { 'User-Agent': USER_AGENT, Accept: 'application/json' },
      signal: controller.signal,
    });
    const text = await res.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {
      /* réponse non JSON : signalée par l'appelant via json === null */
    }
    return { status: res.status, ok: res.ok, json, text };
  } catch (error) {
    return { status: 0, ok: false, json: null, text: '', transportError: String(error?.message ?? error) };
  } finally {
    clearTimeout(timer);
  }
}

/* ------------------------------------------------------------------ *
 * Mode « pont »
 * ------------------------------------------------------------------ */

/** Interroge le greffon. `root` = racine du site WordPress (ex. https://preprod.tsis.sn). */
export async function fetchBridgeContent({ root, base, lang = 'fr', log = () => {} }) {
  const racine = (root || base || '').replace(/\/wp-json(\/wp\/v2)?\/?$/, '').replace(/\/+$/, '');
  const url = `${racine}/wp-json/tsis/v1/content?lang=${encodeURIComponent(lang)}`;
  const res = await httpJson(url);
  if (res.transportError) return { ok: false, reason: `injoignable (${res.transportError})` };
  if (!res.ok) return { ok: false, reason: `HTTP ${res.status}` };
  if (!res.json || typeof res.json !== 'object') return { ok: false, reason: 'réponse illisible' };
  if (!('pages' in res.json) || !('services' in res.json)) {
    return { ok: false, reason: 'réponse inattendue (clés pages/services absentes)' };
  }
  log(`  pont détecté : ${url}`);
  return { ok: true, content: { ...res.json, source: 'wordpress', lang: res.json.lang ?? lang } };
}

/* ------------------------------------------------------------------ *
 * Mode « REST public » (greffon absent)
 * ------------------------------------------------------------------ */

async function fetchMedia(base, id, cache) {
  if (!id) return null;
  if (cache.has(id)) return cache.get(id);
  const res = await httpJson(`${base}/media/${id}`);
  const media =
    res.ok && res.json?.source_url
      ? {
          url: res.json.source_url,
          width: res.json.media_details?.width ?? null,
          height: res.json.media_details?.height ?? null,
          alt: stripTags(res.json.alt_text) || null,
        }
      : null;
  cache.set(id, media);
  return media;
}

/**
 * Récupère ce que WordPress expose publiquement. Ne renvoie que les clés dont la lecture a
 * réellement réussi (les autres restent à null et seront conservées depuis l'instantané).
 */
export async function fetchPublicContent({ base, log = () => {} }) {
  const problems = [];
  const mediaCache = new Map();

  const get = async (path) => {
    const res = await httpJson(`${base}${path}`);
    if (res.transportError) {
      problems.push(`${path} — injoignable (${res.transportError})`);
      return null;
    }
    if (!res.ok) {
      problems.push(`${path} — HTTP ${res.status}`);
      return null;
    }
    return res.json;
  };

  const rawSecteurs = await get('/secteur?per_page=100&_fields=id,slug,name,count,description');
  const secteurSlugById = new Map();
  let secteurs = null;
  if (Array.isArray(rawSecteurs)) {
    for (const terme of rawSecteurs) secteurSlugById.set(terme.id, terme.slug);
    secteurs = rawSecteurs
      .map((terme) => ({
        slug: terme.slug,
        name: decodeEntities(terme.name),
        count: terme.count ?? 0,
        description: stripTags(terme.description) || null,
      }))
      .sort((a, b) => a.name.localeCompare(b.name, 'fr'));
  }

  const rawServices = await get('/service?per_page=100&_fields=id,slug,title,excerpt,featured_media');
  let services = null;
  if (Array.isArray(rawServices)) {
    services = [];
    for (const item of rawServices) {
      services.push({
        slug: item.slug,
        title: stripTags(item.title?.rendered ?? item.title ?? ''),
        excerpt: stripTags(item.excerpt?.rendered ?? ''),
        image: await fetchMedia(base, item.featured_media, mediaCache),
        order: null, // menu_order n'est pas exposé par le CPT : l'ordre est conservé localement
      });
    }
  }

  const rawRealisations = await get('/realisation?per_page=100&_fields=id,slug,title,excerpt,featured_media,secteur');
  let realisations = null;
  if (Array.isArray(rawRealisations)) {
    realisations = [];
    for (const item of rawRealisations) {
      realisations.push({
        slug: item.slug,
        title: stripTags(item.title?.rendered ?? ''),
        excerpt: stripTags(item.excerpt?.rendered ?? ''),
        secteurs: (item.secteur ?? []).map((id) => secteurSlugById.get(id)).filter(Boolean),
        image: await fetchMedia(base, item.featured_media, mediaCache),
      });
    }
  }

  log(
    `  REST public : ${services?.length ?? 0} services, ${realisations?.length ?? 0} réalisations, ` +
      `${secteurs?.length ?? 0} secteurs`
  );
  return { content: { secteurs, services, realisations }, problems };
}

/* ------------------------------------------------------------------ *
 * Fusion avec l'instantané précédent
 * ------------------------------------------------------------------ */

function deepMerge(base, over) {
  if (Array.isArray(over)) return over.length > 0 ? over : Array.isArray(base) ? base : [];
  if (over && typeof over === 'object') {
    const out = base && typeof base === 'object' && !Array.isArray(base) ? { ...base } : {};
    for (const [key, value] of Object.entries(over)) {
      if (value === undefined) continue;
      if (value === null || value === '') {
        if (!(key in out)) out[key] = value; // une valeur absente côté WordPress n'efface rien
        continue;
      }
      if (typeof value === 'object') {
        out[key] = deepMerge(out[key], value);
        continue;
      }
      out[key] = value;
    }
    return out;
  }
  return isFilled(over) ? over : base;
}

/**
 * Fusionne les entités d'une collection : WordPress fait foi sur les champs qu'il expose, les
 * champs non exposés (corps éditorial, fonctionnalités, ordre…) sont conservés depuis l'instantané.
 * L'ordre d'affichage local est préservé ; les nouvelles entités sont ajoutées à la fin.
 */
function mergeCollection(previous = [], incoming = []) {
  const previousByKey = new Map(previous.map((item) => [item.slug ?? item.name, item]));
  const merged = incoming.map((item) => {
    const key = item.slug ?? item.name;
    const before = previousByKey.get(key);
    if (!before) return item;
    const out = { ...before };
    for (const [field, value] of Object.entries(item)) {
      if (isFilled(value) || typeof value === 'boolean' || value === 0) out[field] = value;
    }
    return out;
  });

  const previousOrder = previous
    .map((item) => item.slug ?? item.name)
    .filter((key) => merged.some((item) => (item.slug ?? item.name) === key));
  merged.sort((a, b) => {
    const ra = previousOrder.indexOf(a.slug ?? a.name);
    const rb = previousOrder.indexOf(b.slug ?? b.name);
    if (ra === -1 && rb === -1) return 0;
    if (ra === -1) return 1;
    if (rb === -1) return -1;
    return ra - rb;
  });

  merged.forEach((item, index) => {
    if (item.order === undefined || item.order === null) {
      const before = previousByKey.get(item.slug ?? item.name);
      item.order = before?.order ?? (index + 1) * 10;
    }
  });
  return merged;
}

/** Applique le contenu entrant sur l'instantané précédent. */
export function mergeContent(previous, incoming) {
  const out = previous ? structuredClone(previous) : {};
  out.schemaVersion = incoming.schemaVersion ?? out.schemaVersion ?? 1;
  out.lang = incoming.lang ?? out.lang ?? 'fr';
  out.source = incoming.source ?? out.source ?? 'seed';
  out.generatedAt = new Date().toISOString();

  for (const key of ['site', 'contact', 'nav', 'ui', 'pages']) {
    out[key] = deepMerge(out[key] ?? {}, incoming[key] ?? {});
  }
  if (isFilled(incoming.social)) out.social = incoming.social;
  for (const key of ['secteurs', 'services', 'realisations', 'team']) {
    if (Array.isArray(incoming[key]) && incoming[key].length > 0) {
      out[key] = mergeCollection(out[key] ?? [], incoming[key]);
    }
  }
  return out;
}

/* ------------------------------------------------------------------ *
 * Rapatriement des images (site statique autonome)
 * ------------------------------------------------------------------ */

function localFileName(remoteUrl) {
  const raw = decodeURIComponent(new URL(remoteUrl).pathname.split('/').pop() || 'image');
  const clean = raw
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'image';
  let hash = 0;
  for (const char of remoteUrl) hash = (hash * 31 + char.charCodeAt(0)) % 0xffffffff;
  return `${hash.toString(16).padStart(8, '0').slice(0, 8)}-${clean}`;
}

/**
 * Télécharge les images distantes dans public/uploads/wp et remplace les URL distantes par ces
 * chemins locaux : le site compilé reste autonome et le build reste hors ligne.
 */
export async function mirrorMedia(doc, { publicDir, manifestPath, log = () => {} }) {
  const manifest = existsSync(manifestPath)
    ? JSON.parse(readFileSync(manifestPath, 'utf8'))
    : {};
  const targets = [];
  const collect = (holder, key) => {
    const image = holder?.[key];
    if (image && typeof image.url === 'string' && /^https?:\/\//.test(image.url)) {
      targets.push([holder, key, image]);
    }
  };

  for (const service of doc.services ?? []) collect(service, 'image');
  for (const realisation of doc.realisations ?? []) {
    collect(realisation, 'image');
    (realisation.gallery ?? []).forEach((_, index) => collect(realisation.gallery, String(index)));
  }
  for (const member of doc.team ?? []) collect(member, 'photo');
  collect(doc.pages?.home?.hero, 'image');
  collect(doc.pages?.home?.about, 'image');
  collect(doc.site, 'logo');

  let downloaded = 0;
  let reused = 0;
  let failed = 0;

  for (const [holder, key, image] of targets) {
    const remote = image.url;
    const already = manifest[remote];
    if (already && existsSync(join(publicDir, already))) {
      holder[key] = { ...image, url: already };
      reused += 1;
      continue;
    }
    try {
      const res = await fetch(remote, { headers: { 'User-Agent': USER_AGENT } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const rel = join('uploads', 'wp', localFileName(remote));
      const target = join(publicDir, rel);
      mkdirSync(dirname(target), { recursive: true });
      writeFileSync(target, Buffer.from(await res.arrayBuffer()));
      manifest[remote] = `/${rel.split(/[\\/]/).join('/')}`;
      holder[key] = { ...image, url: manifest[remote] };
      downloaded += 1;
    } catch (error) {
      failed += 1;
      log(`  ⚠︎ image non récupérée : ${remote} (${error.message})`);
    }
  }

  mkdirSync(dirname(manifestPath), { recursive: true });
  writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
  return { downloaded, reused, failed, total: targets.length };
}
