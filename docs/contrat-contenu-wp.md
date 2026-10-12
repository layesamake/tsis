# Contrat de contenu — branchement WordPress headless

> Lot « branchement WordPress » du projet TSIS. Autorité de référence pour la source de contenu du
> site Astro. À lire avec `docs/architecture.md` (§ flux, domaines) et `docs/inventaire-contenu.md`.
> Date : 2026-10-12. Dépôt : `/opt/data/projects/tsis`.

## 1. Objet et objectif

Rendre le contenu du site Astro **intégralement éditable depuis le WordPress de TSIS**, sans
intervention dans le code, tout en conservant le design system de la refonte (le rendu Elementor du
site actuel n'est pas repris).

Deux livrables complémentaires :

1. **Côté WordPress** — un « pont » (`wordpress/`) qui expose le contenu sous forme structurée et
   donne à l'éditeur des champs de saisie clairs.
2. **Côté Astro** — une couche de contenu qui consomme ce pont au moment du build, avec instantané
   versionné et repli automatique, sans jamais faire échouer le build.

## 2. Constat de départ — ce que WordPress expose réellement aujourd'hui

Mesuré le 2026-10-12 sur `https://preprod.tsis.sn` (WordPress + `hello-elementor` + Elementor Pro,
cache LiteSpeed, Wordfence, Universally pour le multilingue).

| Ressource | Endpoint public | Résultat réel |
| --- | --- | --- |
| Types de contenu | `/wp-json/wp/v2/types` | `post`, `page`, `attachment`, `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part` |
| Pages (6) | `/wp-json/wp/v2/pages` | 200 — `accueil`, `nos-services`, `nos-solutions`, `a-propos`, `contact`, `design-system` |
| CPT `service` (9) | `/wp-json/wp/v2/service` | 200 — `id`, `slug`, `title`, `excerpt`, `featured_media`, `modified` |
| CPT `realisation` (11) | `/wp-json/wp/v2/realisation` | 200 — idem |
| Taxonomie `secteur` (11) | `/wp-json/wp/v2/secteur` | 200 |
| Médias | `/wp-json/wp/v2/media` | 200 |
| Menus | `/wp-json/wp/v2/menus`, `/menu-items` | **401** — non lisibles publiquement |
| Utilisateurs | `/wp-json/wp/v2/users` | 200 mais **1 seul compte technique** (`etiennesamake`) |
| Champs ACF | clé `acf` de chaque objet | **`[]`** — aucun champ structuré exposé |
| Corps de contenu | `content.rendered` | HTML **Elementor** brut (66 Ko pour la page `accueil`) |

Conséquences à assumer explicitement :

- **Aucun contenu structuré n'existe côté WordPress.** Tout le texte éditorial (hero, sections de
  l'accueil, page À propos, 25 membres de l'équipe, libellés des gabarits) vit dans du HTML
  Elementor, ou n'est pas exposé (menus, équipe).
- Le contenu **détaillé des services et réalisations n'existe pas non plus** dans WordPress : le
  site actuel n'affiche que titre + une phrase (voir `docs/inventaire-contenu.md` § « Manques de
  contenu constatés »). Le contrat ci-dessous prévoit donc des champs **optionnels** : leur absence
  ne doit jamais produire de contenu inventé ni d'erreur.
- Les menus étant sous authentification, la navigation éditable passe par le pont (§5), pas par
  `/wp/v2/menus`.

## 3. Stratégie retenue

Un **document de contenu unique**, assemblé par WordPress et consommé par Astro au build :

```
Éditeur → WordPress (preprod.tsis.sn)
              │  GET /wp-json/tsis/v1/content?lang=fr
              ▼
   scripts/pull-content.mjs  →  astro/src/content/wp-content.json   (instantané versionné)
                                          │
                                          ▼
                            astro build (SSG, 100 % hors ligne)
```

Trois règles non négociables :

1. **Le build ne dépend jamais du réseau.** Il lit l'instantané versionné. `npm run content:pull`
   (ou `build:live`) rafraîchit l'instantané depuis WordPress ; c'est ce point d'entrée que le
   webhook de publication appellera plus tard.
2. **Aucune régression, aucun contenu inventé.** Ordre de résolution d'un champ :
   valeur WordPress → valeur de repli enregistrée → absence (le bloc n'est pas rendu). Jamais de
   valeur par défaut fabriquée par le code.
3. **Le pont est additif.** Tant qu'il n'est pas installé (il exige un accès administrateur), la
   couche Astro fonctionne en **mode REST public** : tout ce que WordPress expose déjà
   (services, réalisations, secteurs, images, titres) devient éditable immédiatement ; le reste
   reste servi par l'instantané.

## 4. Contrat d'API (côté WordPress)

`GET /wp-json/tsis/v1/content`

| Paramètre | Type | Défaut | Rôle |
| --- | --- | --- | --- |
| `lang` | `fr` \| `en` | `fr` | Langue demandée (voir §11) |

Réponse `200` — `application/json` :

```jsonc
{
  "schemaVersion": 1,
  "generatedAt": "2026-10-12T18:00:00+00:00",
  "lang": "fr",
  "source": "wordpress",
  "site":    { /* §5.1  */ },
  "contact": { /* §5.1  */ },
  "social":  [ /* §5.1  */ ],
  "nav":     { /* §5.2  */ },
  "ui":      { /* §5.9  */ },
  "pages":   { "home": {…}, "about": {…}, "servicesIndex": {…}, "solutionsIndex": {…}, "contact": {…} },
  "services":     [ /* §5.5 */ ],
  "realisations": [ /* §5.6 */ ],
  "secteurs":     [ /* §5.7 */ ],
  "team":         [ /* §5.8 */ ]
}
```

Contraintes de forme :

- Toute image est un objet `{ "url": "...", "width": 0, "height": 0, "alt": "..." }` ou `null`.
  `url` est **absolue** (domaine du CMS), `alt` provient du média WordPress et n'est jamais vide
  par défaut.
- Tout CTA est `{ "label": "...", "href": "..." }`. Un `href` relatif au site est rendu
  **chemin seul** (`/contact/`) ; une URL externe est rendue absolue.
- Toute liste ordonnée est déjà triée par WordPress (`menu_order`, puis titre).
- Un champ vide est `null` (ou `[]` pour une liste) — jamais une chaîne vide silencieuse.
- Le document est **mis en cache côté WordPress** (transient invalidé à la publication) et exclu du
  cache de page LiteSpeed. En-tête `Cache-Control: public, max-age=300` accepté.

## 5. Modèle de contenu

Convention de nommage : les clés de métadonnées WordPress sont préfixées `tsis_` et **identiques
aux noms de champs ACF** (ACF stocke une valeur dans `postmeta`/`options` sous le nom du champ, ce
qui rend le pont compatible avec ou sans ACF). Les répéteurs ACF sont aplatis par le pont (§6.2).

### 5.1 Identité, contact, réseaux (options)

| Clé | Type | Obligatoire | Alimente |
| --- | --- | --- | --- |
| `tsis_site_name` | texte | oui | `SITE.name`, SEO |
| `tsis_site_short_name` | texte | oui | `SITE.shortName` |
| `tsis_site_legal_name` | texte | non | `SITE.legalName` |
| `tsis_site_tagline` | texte | oui | `SITE.tagline`, bandeau |
| `tsis_site_description` | texte long | oui | `SITE.description`, meta description par défaut |
| `tsis_site_logo` | média | oui | en-tête, pied de page, JSON-LD |
| `tsis_site_url` | url | oui | canoniques, sitemap, `SITE.url` |
| `tsis_contact_email` | email | oui | `SITE.email`, page Contact |
| `tsis_contact_address` | texte | oui | `SITE.address`, pied de page |
| `tsis_contact_maps_url` | url | non | lien « itinéraire » |
| `tsis_contact_phones` | répéteur `{label, href, whatsapp}` | oui | `CONTACT.phones` |
| `tsis_contact_whatsapp` | url | non | bouton WhatsApp flottant / hero |
| `tsis_social` | répéteur `{label, href}` | non | `SOCIAL` |

### 5.2 Navigation (options)

Menus WordPress déclarés (`register_nav_menus`) : `tsis_primary`, `tsis_footer`.

| Clé | Type | Alimente |
| --- | --- | --- |
| `nav.primary` | `[{label, href, children[]}]` | `Header.astro` |
| `nav.footer` | idem | `Footer.astro` (colonne « Explorer ») |
| `nav.footerServices` | idem | `Footer.astro` (colonne « Nos services ») |
| `nav.primaryCta` | CTA | bouton principal d'en-tête |

Fallback si un menu est vide : `/wp-json/wp/v2/menus` étant fermé, l'instantané conserve la
navigation actuelle tant que le pont n'est pas installé.

### 5.3 Page d'accueil — page WordPress de slug `accueil`

| Bloc | Clés | Type |
| --- | --- | --- |
| Hero | `tsis_hero_eyebrow`, `_title`, `_text`, `_chips` (rép. texte), `_image` (média), `_image_alt`, `_primary_cta_label`, `_primary_cta_href`, `_secondary_cta_label`, `_secondary_cta_href` | — |
| À propos | `tsis_home_about_eyebrow`, `_title`, `_text`, `_note`, `_highlights` (rép. texte), `_image`, `_image_alt`, `_cta_label`, `_cta_href` | — |
| Solutions | `tsis_home_solutions_eyebrow`, `_title`, `_intro`, `_featured` (relation → `realisation`, slugs), `_cta_label`, `_cta_href` | — |
| Services | `tsis_home_services_eyebrow`, `_title`, `_intro`, `_cta_label`, `_cta_href` | — |
| Pourquoi | `tsis_home_why_eyebrow`, `_title`, `_intro`, `_items` (rép. `{title, text}`), `_cta_label`, `_cta_href` | — |
| Processus | `tsis_home_process_eyebrow`, `_title`, `_intro`, `_steps` (rép. `{num, title, text}`) | — |
| CTA final | `tsis_home_final_eyebrow`, `_title`, `_text`, `_cta_label`, `_cta_href` | — |

Correspondance directe avec `astro/src/data/home.ts` (mêmes noms de sections) : le branchement
change la source, pas les composants.

### 5.4 Page À propos — page WordPress de slug `a-propos`

| Bloc | Clés |
| --- | --- |
| En-tête | `tsis_about_eyebrow`, `_title`, `_intro`, `_badge` |
| Piliers | `tsis_about_pillars` (rép. `{title, text}`) |
| Expertise | `tsis_about_expertise_eyebrow`, `_title`, `_paragraphs` (rép. texte), `_countries` (rép. texte) |
| Chiffres | `tsis_about_stats` (rép. `{value, label}`) |
| Équipe (titre de section) | `tsis_about_team_eyebrow`, `_title`, `_intro` |

L'équipe elle-même est décrite en §5.8.

### 5.5 CPT `service` (9 entrées existantes)

| Champ | Clé / source | Obligatoire |
| --- | --- | --- |
| Titre | `title` | oui |
| Slug | `slug` | oui |
| Accroche | `excerpt` (existant) | oui |
| Ordre | `menu_order` | oui (défaut 100) |
| Image | `featured_media` (existant) | non |
| Icône | `tsis_service_icon` | non |
| Corps détaillé | `tsis_service_body` (éditeur riche) | **non — n'existe pas aujourd'hui** |
| Fonctionnalités clés | `tsis_service_features` (rép. `{title, text}`) | **non** |
| SEO | `tsis_service_seo_title`, `_seo_description` | non |

### 5.6 CPT `realisation` (11 entrées existantes)

| Champ | Clé / source | Obligatoire |
| --- | --- | --- |
| Titre / slug / accroche | `title`, `slug`, `excerpt` | oui |
| Secteurs | taxonomie `secteur` | non |
| Image à la une | `featured_media` | non (7/11 sont vides aujourd'hui) |
| Client | `tsis_realisation_client` | non |
| Année | `tsis_realisation_year` | non |
| Corps / étude de cas | `tsis_realisation_body` (éditeur riche) | **non** |
| Résultats | `tsis_realisation_results` (rép. `{label, value}`) | **non** |
| Galerie | `tsis_realisation_gallery` (galerie de médias) | non |
| SEO | `tsis_realisation_seo_title`, `_seo_description` | non |

### 5.7 Taxonomie `secteur` (11 termes)

`{ slug, name, count, description }` — sert aux filtres de `/nos-solutions/` et aux étiquettes des
cartes. Le `count` est recalculé par WordPress (jamais figé dans le code).

### 5.8 Équipe — **nouveau** CPT `tsis_member`

Aujourd'hui les 25 membres sont du HTML Elementor dans `/a-propos`, sans source structurée ni API
utilisateurs exploitable (1 seul compte technique). Le pont déclare donc un CPT `tsis_member` :

| Champ | Clé | Obligatoire |
| --- | --- | --- |
| Nom | `title` | oui |
| Fonction | `tsis_member_role` | oui |
| Photo | `tsis_member_photo` (média) | non |
| Ordre d'affichage | `menu_order` | oui |
| Publié sur le site | `tsis_member_visible` (booléen, défaut vrai) | oui |

Le pont fournit une **commande d'amorçage** (§6.4) qui crée ces 25 entrées à partir de
`docs/site-source/api/team.json` (nom, rôle, photo) — aucune saisie manuelle initiale.

### 5.9 Libellés d'interface (`ui`) — options

Textes aujourd'hui codés en dur dans les gabarits Astro et qui doivent devenir éditables :

| Clé | Emplacement actuel |
| --- | --- |
| `tsis_ui_project_eyebrow` | « Le projet » — `services/[slug]`, `realisations/[slug]` |
| `tsis_ui_project_title` | « Une réponse pensée pour les besoins réels. » |
| `tsis_ui_service_aside_title` / `_text` / `_cta_label` | encart latéral des fiches service |
| `tsis_ui_realisation_aside_title` / `_text` / `_cta_label` | encart latéral des fiches réalisation |
| `tsis_ui_related_services_title` | « D'autres services TSIS » |
| `tsis_ui_related_realisations_title` | « D'autres solutions conçues par TSIS » |
| `tsis_ui_discover_label` | « Découvrir → » |
| `tsis_ui_contact_hero_eyebrow` / `_title` / `_intro` | en-tête de `/contact/` |
| `tsis_ui_services_index_eyebrow` / `_title` / `_intro` | en-tête de `/nos-services/` |
| `tsis_ui_solutions_index_eyebrow` / `_title` / `_intro` | en-tête de `/nos-solutions/` |

Sans valeur WordPress, le libellé de repli actuel est utilisé : aucun trou dans l'interface.

## 6. Côté WordPress — livrables à installer

Répertoire `wordpress/` du dépôt (voir `wordpress/README.md` pour la procédure) :

1. **`wordpress/mu-plugins/tsis-headless-bridge.php`** — greffon *must-use* :
   - route REST `tsis/v1/content` (§4) ;
   - `register_post_type('tsis_member')` + `register_nav_menus(['tsis_primary','tsis_footer'])` ;
   - enregistrement des métadonnées en REST (`register_post_meta`, `show_in_rest`) ;
   - aplatissement des répéteurs et galeries ACF en listes JSON propres ;
   - résolution des médias en URL absolues + `alt` ;
   - transformation des liens internes en chemins relatifs ;
   - mise en cache par transient + purge à la publication ;
   - aucune authentification requise en lecture (contenu déjà public), corroborée par l'en-tête
     `X-TSIS-Bridge` pour permettre à Astro de détecter le pont.
2. **`wordpress/acf/tsis-content-fields.json`** — groupes de champs ACF (un par page/CPT + options)
   avec l'option « Show in REST API » activée, importables en un clic pour donner à l'éditeur une
   saisie structurée.
3. **`wordpress/README.md`** — installation (mu-plugin par FTP/Gestionnaire de fichiers, import ACF,
   déclaration des menus, exclusion du cache LiteSpeed, vérification par `curl`), sans jamais
   demander de mot de passe.
4. **`wordpress/import/import-team.php`** (ou commande WP-CLI) — amorçage des 25 membres (§5.8).

**Le pont s'installe par l'exploitant** : aucun accès administrateur à `preprod.tsis.sn` n'est
disponible depuis cet environnement. Le code est fourni, relu et documenté, mais **non exécuté** —
cette limite est reportée telle quelle dans le rapport de lot.

## 7. Côté Astro — couche de contenu

Nouveaux fichiers (le reste ne bouge pas) :

| Fichier | Rôle |
| --- | --- |
| `astro/src/lib/wp/types.ts` | types du document de contenu et des blocs éditoriaux |
| `astro/src/lib/wp/pipeline.mjs` | lecture du pont, lecture du REST public, fusion, rapatriement des images |
| `astro/src/content/wp-content.json` | **instantané versionné** — seule source du build |
| `astro/src/content/media-manifest.json` | correspondance image distante → fichier local (idempotence du rapatriement) |
| `astro/src/content/index.ts` | `getContent()` et vues par page (`SERVICES`, `HERO`, `UI`, `PAGES`…) |
| `astro/scripts/pull-content.mjs` | rafraîchit l'instantané (`npm run content:pull`) |
| `astro/src/config/site.ts` | **vue** du contenu (réexporte `SITE`, `CONTACT`, `SOCIAL`, `NAV`, `PRIMARY_CTA`) |
| `astro/public/uploads/wp/` | images rapatriées depuis le CMS (site statique autonome) |

Le contenu initial provient des modules historiques `astro/src/data/*.ts` (conservés dans
l'historique git) ; `astro/src/data/` a été supprimé pour éviter deux sources de vérité.

Règles d'exécution :

- `npm run build` → lit l'instantané, **aucun appel réseau**, build déterministe.
- `npm run content:pull` → interroge `WP_BASE` (défaut `https://preprod.tsis.sn`) ; si
  `/wp-json/tsis/v1/content` répond, mode **pont** ; sinon mode **REST public** ; le résultat est
  fusionné dans l'instantané (les valeurs déjà présentes et absentes de WordPress sont conservées).
- `npm run build:live` → `pull` puis `build`.
- Toute anomalie de WordPress est **signalée, jamais fatale** : le build continue avec
  l'instantané.

## 8. Vérifications exigées

1. `npm run build` hors ligne (réseau coupé) → 25 pages, contenu identique à l'instantané.
2. `npm run content:pull` contre le vrai `preprod.tsis.sn` → services, réalisations, secteurs et
   images réellement récupérés ; le journal indique le mode utilisé et le nombre d'entités.
3. Preuve d'éditabilité : modifier une donnée côté WordPress (ou simuler une réponse du pont) →
   le contenu change dans le HTML produit, sans modification de code.
4. Aucun contenu inventé : un champ absent ne produit ni texte de remplissage ni bloc vide affiché.
5. Routes publiques servies (`/`, `/nos-services/`, `/nos-solutions/`, `/a-propos/`, `/contact/`,
   un service, une réalisation) avec le contenu réel de WordPress.

## 9. Hors périmètre de ce lot

- **Rendu du contenu Elementor existant** : il n'est pas repris (c'est le principe même de la
  refonte). Aucun rapprochement automatique des textes Elementor vers les nouveaux champs.
- **Multilingue EN** : le plugin Universally n'expose pas ses traductions publiquement. Le contrat
  prévoit le paramètre `lang` et la structure, mais la fourniture du contenu anglais dépend d'une
  décision d'outillage côté WordPress (§11).
- **Formulaire de contact** : `contact.php` actuel conservé en l'état ; son branchement à un service
  d'envoi reste une décision produit distincte.
- **Déclenchement automatique du rebuild** (webhook WordPress → build) : à poser avec
  l'hébergement.
- **Upload des fichiers statiques sur Hostinger** : dépend des accès SSH (§`scripts/deploy.sh`).

## 10. Limites et décisions en attente

| # | Sujet | Statut |
| --- | --- | --- |
| W01 | Installation du pont sur `preprod.tsis.sn` | dépend d'un accès administrateur — **à fournir par l'exploitant** |
| W02 | Contenu détaillé des services et réalisations (corps, fonctionnalités, résultats) | inexistant aujourd'hui — champs prévus, **saisie éditoriale à faire** |
| W03 | Photos manquantes (7 réalisations sur 11) | à fournir par TSIS |
| W04 | Logo vectoriel SVG | à fournir par TSIS |
| W05 | Contenu anglais | outillage Universally non exposé — décision à prendre |
| W06 | Validation du rendu EN et du routage `/en/` | dépend de W05 |
