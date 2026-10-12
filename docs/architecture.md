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
              │  REST API  (/wp-json/wp/v2/…)
              ▼
        Build Astro (SSG)   ← scripts locaux
              │  dist/ (HTML/CSS/JS statiques)
              ▼
   astro.tsis.sn  ──(transfert domaine)──►  tsis.sn
```

Déclenchement du rebuild : manuel au départ, puis webhook WordPress → script de build.

## Stack retenue
- **Astro 5** + TypeScript
- **Tailwind CSS 4**
- Sortie statique (`output: 'static'`)
- Contenu : Content Collections Astro alimentées par l'API REST WordPress
- Multilingue FR/EN (le site actuel expose `/` et `/en/`)

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
├── astro/              # site public Astro (SSG)
├── wordpress/          # config, exports, seeds du CMS WP
├── scripts/            # extraction contenu, build, déploiement
└── docs/               # contexte, architecture, inventaire
```

## Sources de contenu (site actuel)
- 6 pages : Accueil, Nos services, Nos solutions, À propos, Contact, (design-system = démo, non reprise)
- 9 services (CPT `service`)
- 11 réalisations (CPT `realisation`, taxonomie `secteur`)
- 1 article de blog
- 59 médias

Inventaire détaillé : `docs/inventaire-contenu.md`
Contenu extrait : `docs/site-source/`
