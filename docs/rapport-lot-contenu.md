# Rapport de lot — branchement du contenu WordPress (headless)

> Lot « branchement WordPress » du projet TSIS. Référence : `docs/contrat-contenu-wp.md`.
> Date du rapport : 2026-10-12. Dépôt : `/opt/data/projects/tsis`, branche `main`.
> Exécution : un agent orchestrateur a figé le contrat de contenu, construit la couche de contenu
> Astro, puis confié les livrables WordPress à un sous-agent ; l'orchestrateur a ensuite relu,
> corrigé et **re-vérifié lui-même** chaque livraison (PHP, JSON ACF, harnais de test).

## 1. Fonctionnalités désormais utilisables

### 1.1 Éditable dès maintenant, sans rien installer sur WordPress

Le contenu exposé publiquement par `preprod.tsis.sn` pilote le site :

- les **9 services** (titre, accroche, ordre d'affichage) ;
- les **11 réalisations** (titre, accroche, secteurs, image à la une) ;
- les **11 termes** de la taxonomie `secteur` (nom, compteur) ;
- les **images à la une** des réalisations, rapatriées dans `astro/public/uploads/wp/` pour que le
  site compilé reste autonome.

Ajouter, renommer ou illustrer une réalisation dans WordPress suffit : `npm run content:pull` puis
`npm run build` produisent le site à jour, sans toucher au code.

### 1.2 Éditable après installation du greffon WordPress (112 champs)

- identité et contact (nom, sigle, slogan, description, logo, URL, e-mail, adresse, plan, téléphones,
  WhatsApp, réseaux sociaux) ;
- page d'accueil : 7 blocs (hero, à propos, solutions, services, pourquoi, processus, CTA final) ;
- page À propos : en-tête, piliers, expertise, chiffres, section équipe ;
- **équipe** : nouveau CPT `tsis_member` (nom, fonction, photo, ordre, visibilité) ;
- services et réalisations : corps éditorial, fonctionnalités clés, client, année, résultats, galerie,
  référencement ;
- **24 libellés d'interface** (surtitres, encarts, liens « découvrir », en-têtes des pages d'index) ;
- navigation : menus `tsis_primary` / `tsis_footer`, bouton principal, services du pied de page.

## 2. Données et configuration

- **Contrat** : `docs/contrat-contenu-wp.md` (API `GET /wp-json/tsis/v1/content`, modèle complet,
  règles de repli, conventions d'implémentation, limites). Le constat de départ y est mesuré :
  WordPress n'expose aujourd'hui **aucun champ structuré** (ACF vides, corps en HTML Elementor,
  menus en 401, équipe absente de l'API).
- **Instantané** : `astro/src/content/wp-content.json`, versionné, **seule source du build**.
  `astro/src/content/media-manifest.json` trace les images rapatriées.
- **Couche de contenu** : `astro/src/content/index.ts` (`getContent()`, vues `SERVICES`,
  `REALISATIONS`, `TEAM`, `HERO`, `PAGES`, `UI`, `ui()`) et `astro/src/lib/wp/types.ts`.
- **Pipeline** : `astro/src/lib/wp/pipeline.mjs` (lecture du pont, repli sur le REST public, fusion
  non destructive avec l'instantané, rapatriement des images) et `astro/scripts/pull-content.mjs`
  (`npm run content:pull`, `npm run build:live`).
- **Une seule vérité** : `astro/src/data/*.ts` supprimé ; `astro/src/config/site.ts` ne fait plus que
  réexposer la vue de contenu. 7 pages et 2 composants rebranchés, libellés d'interface externalisés.
- **Livrables WordPress** (`wordpress/`, non encore installés) : `mu-plugins/tsis-headless-bridge.php`
  (1 414 lignes), `acf/tsis-content-fields.json` (7 groupes, 112 champs, tous « Show in REST API »),
  `import/import-team.php` (amorçage idempotent des 25 membres), `README.md` (procédure
  d'installation pas à pas, en français, sans aucun mot de passe).
- **Ordre de résolution d'un champ** : valeur WordPress → valeur de l'instantané → absent (le bloc
  n'est pas rendu). Aucun contenu de remplissage n'est fabriqué.

## 3. Vérifications réellement exécutées

| Contrôle | Commande / moyen | Résultat |
| --- | --- | --- |
| Build hors ligne | `npm run build` | **25 pages**, aucun appel réseau dans le chemin de build (seul `pipeline.mjs`, utilisé par le pull, contient `fetch`) |
| Contenu WordPress réellement rendu | lecture du HTML produit | titre de service, accroche, image rapatriée et en-tête d'index issus de WordPress présents dans `dist/` |
| **Propagation d'une édition** | instantané modifié → rebuild → `content:pull` → rebuild | HTML modifié sans toucher au code, puis **vérité WordPress restaurée** par le pull |
| Pull réel | `npm run content:pull` | 9 services, 11 réalisations, 11 secteurs, 25 membres, 5 images récupérées |
| Routes servies | `astro preview` + `curl` | `/`, `/nos-services/`, `/nos-solutions/`, `/a-propos/`, `/contact/`, une fiche service, une réalisation, `sitemap-index.xml` → **200** ; image servie en `image/avif` |
| Syntaxe PHP | `php -l` (PHP 8.3.14) sur les 2 fichiers PHP | **aucune erreur** |
| Comportement du pont | harnais de test hors WordPress (stubs), **ré-exécuté par l'orchestrateur** | **30 OK / 0 FAIL** + 10 contrôles de la réponse REST (statut, en-têtes, `lang`, erreur 500 JSON sans fuite) |
| Amorçage équipe | harnais `import-team` | 25 créés, **relance = 0 doublon** |
| Alignement des libellés | comparaison gabarits ↔ pont ↔ ACF | 15 libellés utilisés, **15 éditables** ; 24 mappés, **24 présents dans l'ACF** ; aucune clé orpheline |
| Intégrité de l'ACF | relecture du JSON | 7 groupes, 112 champs, clés uniques, `show_in_rest` partout |
| Secrets | recherche dans `wordpress/` | aucune fuite (ni mot de passe, ni clé, ni chemin local) |

Deux écarts trouvés par l'orchestrateur dans la livraison du sous-agent et **corrigés avant
intégration** : 108 champs ACF sans « Show in REST API » alors que le contrat l'exigeait, et 4
libellés lus par les gabarits (`serviceHeroEyebrow`, `heroCtaLabel`, `discoverServiceLabel`,
`discoverSolutionLabel`) absents du pont — donc non éditables. Les deux points sont désormais
conformes, et le harnais a été rejoué après correction.

## 4. Limites et vérifications non réalisées

1. **Le greffon n'a jamais tourné dans un vrai WordPress.** PHP est absent de cette machine (un
   binaire PHP 8.3.14 a été récupéré hors dépôt uniquement pour `php -l` et le harnais à stubs).
   Le pont est donc vérifié par relecture, analyse syntaxique et simulation — pas en conditions
   réelles. Point de vigilance n°1 à l'installation.
2. **Aucun accès administrateur** à `preprod.tsis.sn` : le greffon, les champs et l'amorçage restent
   à installer (voir `wordpress/README.md`).
3. **ACF PRO** est nécessaire pour les répéteurs, galeries et la page d'options ; sans lui, le pont
   lit les métadonnées natives et le site fonctionne, mais les listes ne sont pas éditables
   confortablement.
4. **Cache et pare-feu** : la route doit être exclue du cache LiteSpeed et autorisée en anonyme dans
   Wordfence, sinon réponse vide ou 403.
5. **Le site n'est pas en ligne** : `astro.tsis.sn` répond **403** sur `/` et 404 sur tout fichier —
   son `public_html` est vide, rien n'a jamais été déployé. Une clé SSH dédiée a été générée
   (publique transmise à l'exploitant) ; le déploiement attend `DEPLOY_HOST`, `DEPLOY_USER`,
   `DEPLOY_PORT` et `DEPLOY_PATH`.
6. **Contenu anglais** non fourni (plugin Universally non exposé) ; **7 réalisations sur 11** sans
   photo ; **aucun contenu détaillé** de service ou de réalisation n'existe côté TSIS : les champs
   sont prévus, la saisie éditoriale reste à faire.

## 5. Suite

1. **Déployer** le site dès les accès SSH fournis (test de connexion, `deploy.sh --dry-run`, puis
   envoi réel) — c'est ce qui fait cesser le 403.
2. **Installer le pont** dans `wp-admin` (greffon + champs ACF + menus + amorçage équipe), puis
   vérifier `curl` et une saisie réelle de bout en bout.
3. **Saisir le contenu manquant** (services, réalisations, photos, logo vectoriel).
4. **Automatiser** : webhook WordPress → `npm run build:live` → envoi statique.
5. **Multilingue** : trancher l'outillage EN (W05/W06).
