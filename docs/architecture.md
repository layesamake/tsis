# Architecture — Refonte du site TSIS

## Objectif
Refonte complète (from scratch) du site de **Touba Solution Informatique (TSIS)** :
front public statique en **Astro**, contenu géré dans un **WordPress headless**.

## Domaines

| Rôle | Développement (travail) | Production (officiel) |
|------|--------------------------|------------------------|
| Site public — Astro statique | `astro.tsis.sn` | `tsis.sn` |
| CMS WordPress headless | `preprod.tsis.sn` | `cms.tsis.sn` |

Hostinger « WordPress Pro » = hébergement mutualisé (PHP + MySQL, pas de Node en runtime)
→ **Astro doit être 100 % statique (SSG)** : build local/CI, upload des fichiers générés.

## Flux
```
Éditeur → WordPress (preprod.tsis.sn)
              │  REST  /wp-json/tsis/v1/content   (greffon « pont », wordpress/mu-plugins)
              │  repli : /wp-json/wp/v2/…          (REST public, sans greffon)
              ▼
   scripts/pull-content.mjs ──► astro/src/content/wp-content.json  (instantané versionné)
              │
              ▼
        Build Astro (SSG)   ← hors ligne, lit uniquement l'instantané
              │  dist/ (HTML/CSS/JS statiques)
              ▼
   astro.tsis.sn  ──(transfert domaine)──►  tsis.sn
```

Contrat de contenu détaillé : `docs/contrat-contenu-wp.md`.
Déclenchement du rebuild : manuel (`npm run build:live`), puis webhook WordPress → script de build.

## Stack retenue
- **Astro 7** + TypeScript
- **Tailwind CSS 4**
- Sortie statique (`output: 'static'`)
- Contenu : **instantané JSON versionné** alimenté par l'API REST WordPress
  (`docs/contrat-contenu-wp.md`) ; le build ne fait **aucun appel réseau**
- Multilingue FR/EN (le site actuel expose `/` et `/en/`) : structure prévue, contenu anglais encore
  à fournir (plugin Universally non exposé publiquement)

Cohérent avec les autres projets de l'utilisateur (Next.js/React/TS/Vitest) sans introduire
de dépendance à un runtime Node côté hébergeur.

## Identité de marque (reprise du design system existant)
Le thème WordPress actuel (`hello-elementor` + plugin design-system `ev4sk`) définit :

### Couleurs
| Token | Valeur | Usage |
|-------|--------|-------|
| primary | `#102F40` | titres, fonds sombres |
| accent | `#F4B544` | CTA, accents |
| heading | `#102F40` | titres |
| text | `#334E5C` | corps de texte |
| muted | `#526875` | texte secondaire |
| footer-text | `#C5D9E3` | texte pied de page |
| border | `#D5E2E9` | bordures |
| form-field | `#526875` | champs |
| white | `#FFFFFF` | fonds |

### Typographie
- Police : **Poppins** (400, 700) — à héberger en local (woff2)
- Échelle fluide en `clamp()` : display, h1→h6, body-lg, body, body-sm, caption

### Espacements / ombres
- `--ts-space-xs` → `--ts-space-2xl` (clamp)
- `--ts-shadow-sm/md/lg`

Source extraite : `docs/site-source/design-system.css` (31 tokens).

## Structure du dépôt
```
tsis/
├── astro/                    # site public Astro (SSG)
│   ├── src/content/          # instantané de contenu WordPress + couche de contenu
│   ├── src/lib/wp/           # pipeline de lecture (pont / REST public), types
│   ├── scripts/              # rafraîchissement du contenu
│   └── public/uploads/wp/    # images rapatriées depuis le CMS
├── wordpress/                # greffon « pont », champs ACF, amorçage, procédure d'installation
├── scripts/                  # extraction du contenu d'origine, build, déploiement
└── docs/                     # contexte, architecture, inventaire, contrat de contenu
```

## Sources de contenu (site actuel)
- 6 pages : Accueil, Nos services, Nos solutions, À propos, Contact, (design-system = démo, non reprise)
- 9 services (CPT `service`)
- 11 réalisations (CPT `realisation`, taxonomie `secteur`)
- 1 article de blog
- 59 médias

Inventaire détaillé : `docs/inventaire-contenu.md`
Contenu extrait : `docs/site-source/`
