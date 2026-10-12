# TSIS — Refonte du site (Astro + WordPress headless)

Refonte complète du site de **Touba Solution Informatique (TSIS)** :
un front public statique en **Astro**, alimenté par un **WordPress headless**.

## Domaines

| Rôle | Développement | Production |
|------|---------------|------------|
| Site public (Astro statique) | `astro.tsis.sn` | `tsis.sn` |
| CMS WordPress headless | `preprod.tsis.sn` | `cms.tsis.sn` |

## Contenu du dépôt

```
tsis/
├── astro/                  # site public Astro (SSG)
│   ├── src/
│   │   ├── components/     # Header, Footer, Button, Section, PageHero
│   │   ├── config/site.ts  # nom, coordonnées, navigation, SEO
│   │   ├── data/           # contenu (services, réalisations, équipe, pages)
│   │   ├── layouts/        # BaseLayout (head, SEO, header/footer)
│   │   └── pages/          # routes du site
│   └── public/
│       ├── contact.php     # traitement du formulaire de devis (PHP mutualisé)
│       ├── fonts/          # Poppins (hébergée localement)
│       ├── brand/          # logo
│       └── uploads/        # images récupérées du site actuel
├── docs/
│   ├── architecture.md     # architecture technique
│   ├── contexte.md         # contexte entreprise
│   ├── inventaire-contenu.md
│   └── site-source/        # contenu extrait du site WordPress actuel
└── scripts/                # extraction de contenu, génération de données, aperçu
```

## Commandes

Tout se lance depuis `astro/` :

```bash
npm install          # dépendances
npm run dev          # serveur de développement (http://localhost:4321)
npm run build        # génère le site statique dans dist/
npm run preview      # prévisualise le build
```

Depuis la racine du dépôt :

```bash
node scripts/fetch-wp.mjs        # récupère le contenu de l'API WordPress
node scripts/gen-data.mjs        # régénère src/data/{services,realisations,secteurs}.ts
node scripts/make-preview.mjs    # aperçu local ouvrable en file:// (chemins relatifs)
```

## Design system

Tokens repris du design system WordPress d'origine (préfixe `--ts-*`),
traduits en thème Tailwind 4 dans `astro/src/styles/global.css` :

- Couleurs : primaire `#102F40`, accent `#F4B544`, texte `#334E5C`, bordures `#D5E2E9`…
- Typographie : **Poppins** (400/700), échelle fluide en `clamp()`
- Espacements et ombres fluides

> ⚠️ Les tokens d'espacement occupent l'espace de noms `--spacing-*` de Tailwind 4.
> Ne pas utiliser les utilitaires de largeur nommés (`max-w-xl`, `w-md`…) : ils entrent en
> collision avec ces tokens. Utiliser des valeurs explicites (`max-w-[36rem]`).

## État d'avancement

- [x] Inventaire et extraction du contenu de l'ancien site
- [x] Design system (tokens, polices, composants de base)
- [x] Pages : Accueil, Nos services, Nos solutions, À propos, Contact
- [x] Pages de détail : services (9), réalisations (11)
- [x] Formulaire de devis + traitement PHP
- [x] SEO : titres, descriptions, Open Graph, sitemap, robots.txt
- [ ] Branchement WordPress headless (contenu 100 % éditable)
- [ ] Version anglaise (FR/EN)
- [ ] Déploiement Hostinger (preprod puis production)

## Point de configuration

`astro/public/contact.php` → constante `DESTINATAIRE` : adresse email qui reçoit les
demandes de devis (actuellement `contact@tsis.sn`, **à confirmer**).
