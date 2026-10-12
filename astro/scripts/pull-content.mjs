/**
 * Rafraîchit l'instantané de contenu depuis WordPress.
 *
 * Usage :
 *   node scripts/pull-content.mjs                 # WP_BASE par défaut (preprod.tsis.sn)
 *   WP_BASE=https://cms.tsis.sn node scripts/pull-content.mjs
 *   node scripts/pull-content.mjs --no-mirror      # ne pas rapatrier les images
 *   node scripts/pull-content.mjs --lang=en
 *
 * Mode « pont » si /wp-json/tsis/v1/content répond (greffon installé), sinon mode « REST public ».
 * Le résultat est fusionné dans src/content/wp-content.json ; les valeurs absentes côté WordPress
 * sont conservées. Aucune erreur n'est fatale : le script sort en code 1 uniquement si rien n'a pu
 * être lu du tout.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import {
  fetchBridgeContent,
  fetchPublicContent,
  mergeContent,
  mirrorMedia,
} from '../src/lib/wp/pipeline.mjs';

const args = process.argv.slice(2);
const hasFlag = (flag) => args.includes(flag);
const option = (name, fallback) => {
  const found = args.find((arg) => arg.startsWith(`--${name}=`));
  return found ? found.slice(name.length + 3) : fallback;
};

const base = (process.env.WP_BASE || 'https://preprod.tsis.sn').replace(/\/+$/, '') + '/wp-json/wp/v2';
const lang = option('lang', process.env.WP_LANG || 'fr');
const mirror = !hasFlag('--no-mirror');

const snapshotPath = fileURLToPath(new URL('../src/content/wp-content.json', import.meta.url));
const publicDir = fileURLToPath(new URL('../public/', import.meta.url));
const manifestPath = fileURLToPath(new URL('../src/content/media-manifest.json', import.meta.url));

const log = (message) => console.log(message);
const previous = existsSync(snapshotPath)
  ? JSON.parse(readFileSync(snapshotPath, 'utf8'))
  : null;

log(`Source WordPress : ${base}`);
if (!previous) {
  log("⚠︎ Aucun instantané existant : seules les données exposées par WordPress seront écrites.");
  log('  Pour repartir du contenu du dépôt : node scripts/init-content.mjs');
}

let incoming = null;
let problems = [];

const bridge = await fetchBridgeContent({ base, lang, log });
if (bridge.ok) {
  incoming = bridge.content;
} else {
  log(`  pont absent (${bridge.reason}) → mode REST public`);
  const publicContent = await fetchPublicContent({ base, log });
  incoming = { ...publicContent.content, source: 'rest', lang };
  problems = publicContent.problems;
}

if (problems.length > 0) {
  log('  points à surveiller :');
  for (const problem of problems) log(`   - ${problem}`);
}

const merged = mergeContent(previous, incoming);

if (mirror) {
  const media = await mirrorMedia(merged, { publicDir, manifestPath, log });
  log(
    `  images : ${media.downloaded} téléchargée(s), ${media.reused} déjà présente(s)` +
      (media.failed > 0 ? `, ${media.failed} échec(s)` : '')
  );
}

writeFileSync(snapshotPath, `${JSON.stringify(merged, null, 2)}\n`, 'utf8');

const counts = [
  ['services', merged.services?.length ?? 0],
  ['réalisations', merged.realisations?.length ?? 0],
  ['secteurs', merged.secteurs?.length ?? 0],
  ['membres', merged.team?.length ?? 0],
];
log(`Instantané mis à jour (source : ${merged.source}) : ${counts.map(([k, v]) => `${v} ${k}`).join(', ')}`);
log(`  ${snapshotPath}`);

const nothingRead =
  !incoming.services && !incoming.realisations && !incoming.secteurs && !incoming.pages;
if (nothingRead) {
  log('⚠︎ Rien n’a pu être lu depuis WordPress : l’instantané précédent est conservé tel quel.');
  process.exitCode = 1;
}
