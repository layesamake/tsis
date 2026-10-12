# Inventaire du contenu — site TSIS actuel

Source : `https://preprod.tsis.sn/` (WordPress, thème `hello-elementor`, Elementor Pro).
Contenu extrait via l'API REST WordPress et le crawl des pages rendues
(voir `docs/site-source/`).

## Pages statiques (6)

| Page | URL | Remarque |
|------|-----|----------|
| Accueil | `/` | 8 sections |
| Nos services | `/nos-services/` | liste de 9 services |
| Nos solutions | `/nos-solutions/` | liste de 11 réalisations |
| À propos | `/a-propos/` | mission, vision, valeurs, équipe (25 personnes) |
| Contact | `/contact/` | formulaire de devis |
| design system | `/design-system/` | **page de démonstration** (contenu factice) — non reprise |

## Services (CPT `service`, 9)

| Slug | Titre |
|------|-------|
| `reseaux-informatiques-infrastructure-it` | Réseaux informatiques & infrastructure IT |
| `securite-informatique-videosurveillance` | Sécurité informatique & vidéosurveillance |
| `creation-sites-web-vitrine-e-commerce` | Création de sites web vitrine & e-commerce |
| `gestion-files-attente-bornes-interactives` | Gestion de files d'attente & bornes interactives |
| `creation-sites-web-erp-sur-mesure` | Création de sites web & ERP sur mesure |
| `fourniture-materiel-informatique-mobilier-bureau` | Fourniture de matériel informatique & mobilier de bureau |
| `developpement-logiciels-applications` | Développement de logiciels & applications |
| `automatisation-processus-intelligence-artificielle` | Automatisation des processus par IA |
| `marketing-digital-design-graphique` | Marketing digital & design graphique |

## Réalisations (CPT `realisation`, 11)

Taxonomie associée : `secteur` (11 termes).

| Slug | Titre | Secteur | Image à la une |
|------|-------|---------|----------------|
| `assirou-gestion-file-attente` | Assirou Gestion File d'attente | Services aux entreprises | ✗ |
| `bernde` | Berndé | Restauration | ✓ |
| `diaba-commerciale` | Diaba commerciale | Commerce | ✗ |
| `diaba-market-place` | Diaba Market Place | Commerce | ✗ |
| `formation-entreprise` | Formation entreprise | Éducation & formation | ✗ |
| `guides-gestion-hospitaliere` | GUIDES — Gestion hospitalière | Santé | ✗ |
| `khidma-gestion-chantier` | Khidma Gestion Chantier | Construction | ✓ |
| `louma-e-commerce` | LOUMA E-commerce | Commerce | ✗ |
| `securite-pointage` | Sécurité & pointage | Sécurité | ✓ |
| `sunu-flix` | Sunu Flix | Média | ✓ |
| `touba-transfert` | Touba Transfert | Services financiers | ✓ |

## Informations de contact (site actuel)

- Téléphone : +221 78 225 40 40 (WhatsApp), +221 78 698 18 18
- Adresse : Arrêt BRT Scat Urbam, Dakar, Sénégal
- Email : `contact@tsis.sn`
- Réseaux : Facebook, LinkedIn, Instagram, TikTok

## Équipe (25 personnes)

Nom, rôle et photo extraits pour chaque membre (`docs/site-source/api/team.json`).

## Manques de contenu constatés

Ces points nécessitent des informations de TSIS — **aucun contenu n'a été inventé** :

1. **Pages services** : aucun contenu détaillé (la section « Fonctionnalités clés » est vide
   sur le site actuel). Seuls un titre et une phrase de description existent.
2. **Pages réalisations** : idem — titre, phrase de description, puis renvois vers d'autres
   réalisations. Pas de description de projet, pas d'étude de cas.
3. **Images** : 7 réalisations sur 11 n'ont pas d'image à la une (placeholder « Image à
   ajouter » sur le site actuel).
4. **Logo** : version PNG basse résolution récupérée ; demander le logo vectoriel (SVG) si
   disponible.
5. **Accueil — section « À propos »** : l'image d'illustration est un visuel générique.

## Éléments techniques relevés

- Thème : `hello-elementor` (Hello Elementor)
- Constructeur : Elementor + Elementor Pro
- Multilingue : plugin **Universally** (FR par défaut, `/en/` existe)
- Cache : LiteSpeed Cache — à exclure de l'API REST lors du branchement headless
- Sécurité : Wordfence
- Sauvegarde/migration : Duplicator
- Hébergement : Hostinger (`platform: hostinger`, hPanel)
