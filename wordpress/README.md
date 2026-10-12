# Pont de contenu WordPress → Astro (TSIS)

Ce dossier contient tout ce qu'il faut poser sur le WordPress de TSIS (`preprod.tsis.sn`)
pour que le site Astro récupère son contenu depuis WordPress via une seule route :

```
GET https://preprod.tsis.sn/wp-json/tsis/v1/content?lang=fr
```

Aucune compétence en développement n'est nécessaire : suivez les étapes dans l'ordre.
Le pont est **additif** : tant qu'il n'est pas installé, le site continue de fonctionner.

**Nature de ce lot — à lire d'abord.** Le WordPress de TSIS possède **déjà** un modèle de contenu ACF
rempli par les éditeurs (3 groupes : « Réglages TSIS », « Service — contenu », « Solution / Projet —
contenu »). Le pont est un **adaptateur** : il **lit ces champs existants** et les restitue au format
attendu par le site. **Aucun champ existant n'est renommé, remplacé ni supprimé.** Les groupes ACF
fournis ici ne font que **compléter** ce qui manque (identité complémentaire, contenu des pages,
équipe).

**Règle de sécurité, valable partout** : ne communiquez jamais de mot de passe, de clé ou
d'identifiant dans une conversation, un chat ou un e-mail. Saisissez vos identifiants
uniquement dans les pages de connexion officielles (hPanel, client FTP, administration WordPress).

## Contenu du dossier

| Fichier | Rôle |
| --- | --- |
| `mu-plugins/tsis-headless-bridge.php` | Le « pont » : lit le contenu existant et l'expose à l'API REST. Indispensable. |
| `acf/tsis-content-fields.json` | 3 groupes de champs ACF **complémentaires** (identité, contenu des pages, équipe). Recommandé. |
| `import/import-team.php` | Crée les 25 membres de l'équipe à partir de `team.json`. Une fois. |
| `README.md` | Ce document. |

---

## Étape 1 — Déposer le mu-plugin (pont)

Un greffon « must-use » s'active automatiquement, sans passer par l'administration.

**Méthode A — Gestionnaire de fichiers / FTP (sans terminal)**

1. Ouvrez le **Gestionnaire de fichiers** de hPanel (ou connectez-vous en FTP avec un client comme FileZilla).
2. Rendez-vous dans le dossier WordPress, en général `public_html/` (repérez le dossier qui
   contient `wp-config.php`). Chemin visé : `public_html/wp-content/`.
3. S'il n'existe pas, **créez** le dossier `mu-plugins` à l'intérieur de `wp-content`.
4. Envoyez (téléversez) le fichier `tsis-headless-bridge.php` dans `wp-content/mu-plugins/`.

Résultat : `public_html/wp-content/mu-plugins/tsis-headless-bridge.php`.

**Méthode B — SSH / WP-CLI (l'exploitant dispose d'un accès SSH)**

Déposez simplement le fichier dans `wp-content/mu-plugins/` puis vérifiez qu'il est bien chargé :

```
# depuis le dossier WordPress (celui qui contient wp-config.php)
cp /chemin/vers/wordpress/mu-plugins/tsis-headless-bridge.php wp-content/mu-plugins/
wp plugin list --status=must-use        # le fichier doit apparaître
wp eval 'echo tsis_bridge_build_site()["name"];'   # doit afficher le nom du site
```

Aucune activation n'est requise : un mu-plugin est chargé automatiquement.

Vérification immédiate (dans le navigateur ou en ligne de commande) :
`https://preprod.tsis.sn/wp-json/tsis/v1/content`
→ vous devez voir du JSON commençant par `{"schemaVersion":1,...`. Si vous voyez une page
d'erreur 404, passez à l'étape 6 (dépannage).

---

## Étape 2 — Importer les champs ACF complémentaires

> **Important** : ce fichier ne remplace **rien**. Il **complète** les groupes ACF déjà présents
> en préprod. N'importez **jamais** une ancienne version de ce JSON (une première ébauche créait
> 7 groupes et 112 champs qui n'existent pas) : elle créerait un modèle parallèle et des doublons.

Le fichier `tsis-content-fields.json` contient **3 groupes**, tous avec « Show in REST API » activé :

| Groupe | Emplacement | Rôle |
| --- | --- | --- |
| TSIS — Identité (compléments) | page d'options **existante** `tsis-reglages` | `tsis_nom_affiche`, `tsis_sigle`, `tsis_raison_sociale`, `tsis_google_maps` |
| TSIS — Contenu des pages | type de contenu `page` | hero, blocs de l'accueil, page À propos, libellés d'interface (`tsis_hero_*`, `tsis_home_*`, `tsis_about_*`, `tsis_ui_*`) |
| TSIS — Équipe | CPT `tsis_member` | `tsis_member_role`, `tsis_member_photo`, `tsis_member_visible` |

Le groupe d'identité se rattache à la page d'options **déjà existante** (`tsis-reglages`) : il ne crée
pas de seconde page d'options. Les champs d'identité servent à corriger le nom du site (le réglage
général WordPress contient une coquille, « Toubab » au lieu de « Touba ») ; à défaut, le pont utilise
le nom général puis le nom affiché.

> Prérequis : le greffon **ACF** est installé. Les répéteurs, la galerie, la relation et la page
> d'options nécessitent **ACF PRO** (présent en préprod).

**Méthode A — en ligne de commande (recommandée, accepte plusieurs groupes d'un coup)**

```
wp acf json import /chemin/vers/wordpress/acf/tsis-content-fields.json
```
Attendu : une ligne `Imported field-group: ...` par groupe puis `Success: Imported 3 item(s).`

**Méthode B — sans ligne de commande (via l'administration)**

1. Ouvrez l'administration WordPress → **ACF** → **Outils** (Tools) → **Import de champs**.
2. Importez le fichier `tsis-content-fields.json`.
3. Si l'interface n'accepte qu'un groupe à la fois, utilisez la méthode **Local JSON** :
   placez le fichier dans un dossier `acf-json/` à la racine du thème (`wp-content/themes/hello-elementor/acf-json/`),
   puis cliquez **Synchroniser** dans ACF → Outils.

Les champs complémentaires se saisissent **sur la page concernée** (page « Accueil », page
« À propos », en-têtes des pages d'index) ; les quatre champs d'identité se saisissent dans
**Contenu / Réglages TSIS** (page d'options existante).

---

## Étape 3 — Déclarer et affecter les menus

Le pont déclare deux emplacements **sans écraser** ceux déjà fournis par le thème et le greffon
`tsis-footer` (`tsis_footer_navigation`, `tsis_footer_legal`, menu-1, menu-2).

1. Administration WordPress → **Apparence** → **Menus**.
2. Créez (ou réutilisez) un menu, par exemple « Navigation principale », et ajoutez-y vos pages.
3. En bas de l'écran, section **« Emplacements des menus »** (*Manage Locations*), affectez :
   - emplacement **« TSIS — Navigation principale »** → votre menu principal ;
   - emplacement **« TSIS — Navigation de pied de page »** → votre menu de pied de page (facultatif).
4. Enregistrez.

**Repli automatique** : tant que `tsis_primary` n'est pas affecté, le pont utilise le menu **`main`**
déjà présent. Un emplacement non affecté ne casse rien (`footer` = `null`, la navigation actuelle
du site est conservée).

Le bouton principal d'en-tête est alimenté par les réglages **`tsis_cta_libelle`** et **`tsis_cta_url`**
(groupe « Réglages TSIS » existant).

---

## Étape 4 — Exclure la route REST du cache LiteSpeed

LiteSpeed Cache peut renvoyer une réponse mise en cache au lieu du contenu frais.

1. Administration WordPress → **LiteSpeed Cache** → **Cache** → onglet **Exclusions**.
2. Dans **« Ne pas mettre en cache les URI »** (*Do Not Cache URIs*), ajoutez une ligne :
   ```
   /wp-json/tsis/v1/content
   ```
   (ou, plus large, `/wp-json/tsis/`).
3. Dans **« Ne pas mettre en cache les chaînes de requête »** (*Do Not Cache Query Strings*),
   assurez-vous que `rest_route` est pris en compte si votre permalien utilise `?rest_route=`.
4. Videz le cache LiteSpeed (**LiteSpeed Cache** → **Vider tout**).

Le pont renvoie déjà l'en-tête `Cache-Control: public, max-age=300`, qui suffit pour un cache court côté consommation.

---

## Étape 5 — Amorcer l'équipe (25 membres)

Le CPT **Équipe** (`tsis_member`) est créé par le mu-plugin (visible sous le menu **Équipe TSIS**).
Pour le remplir sans saisie manuelle, lancez le script d'amorçage (idempotent : relancer ne crée aucun doublon).

Avec **WP-CLI** (terminal SSH / console hPanel) :

```
wp eval-file /chemin/vers/wordpress/import/import-team.php
```

Si le fichier `team.json` n'est pas trouvé automatiquement, indiquez son chemin :

```
wp eval-file /chemin/vers/wordpress/import/import-team.php /chemin/vers/docs/site-source/api/team.json
```

Attendu (exemple) :
```
Source : .../team.json
Créés : 25 · Déjà présents : 0
Photos téléchargées : 25 · Photos en URL de repli : 0
Success: Amorçage terminé : 25 membre(s) traité(s).
```
- Si le téléchargement d'une photo échoue (réseau, permissions), l'**URL** est enregistrée telle
  quelle : rien n'est inventé, et vous pourrez remplacer la photo depuis l'administration.
- Deuxième exécution : `Créés : 0 · Déjà présents : 25` (preuve de non-duplication).

---

## Étape 6 — Vérifications (curl)

Remplacez le domaine par le vôtre si nécessaire.

**1. La route répond et le pont est bien là**
```
curl -sS -D - -o /dev/null "https://preprod.tsis.sn/wp-json/tsis/v1/content?lang=fr"
```
Attendu (extraits d'en-têtes) :
```
HTTP/2 200
content-type: application/json; charset=UTF-8
x-tsis-bridge: 1
cache-control: public, max-age=300
```

**2. Le document est bien formé**
```
curl -sS "https://preprod.tsis.sn/wp-json/tsis/v1/content?lang=fr" | python3 -m json.tool | head -12
```
Attendu (début) :
```json
{
    "schemaVersion": 1,
    "generatedAt": "2026-10-12T18:00:00+00:00",
    "lang": "fr",
    "source": "wordpress",
    ...
```

**3. Les champs EXISTANTS sont bien lus (réglages, services, réalisations)**
```
curl -sS "https://preprod.tsis.sn/wp-json/tsis/v1/content" | python3 -c "import sys,json; d=json.load(sys.stdin); print('tél:', d['contact']['phones'][0]['label']); print('email:', d['contact']['email']); print('réseaux:', [s['label'] for s in d['social']]); print('service[0].body:', (d['services'][0]['body'] or '')[:60]); print('réa[0].featured:', d['realisations'][0]['featured']); print('stats:', d['pages']['about']['stats'])"
```
Attendu (extrait, valeurs réelles de la préprod) :
```
tél: +221 78 225 40 40
email: contact@preprod.tsis.sn
réseaux: ['Facebook', 'LinkedIn', 'Instagram', 'TikTok']   # YouTube vide → omis
service[0].body: <texte nettoyé issu de service_presentation>
réa[0].featured: True
stats: [{'value': '12 ans', 'label': "D'expérience"}, ...]
```

**4. Compter les entités (attendus : 9 services, 11 réalisations publiées, 11 secteurs, 25 membres après l'étape 5)**
```
curl -sS "https://preprod.tsis.sn/wp-json/tsis/v1/content" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('services',len(d['services']),'| realisations',len(d['realisations']),'| secteurs',len(d['secteurs']),'| team',len(d['team']))"
```
Attendu : `services 9 | realisations 11 | secteurs 11 | team 25`

**5. Réponse lisible en cas de problème**
```
curl -sS "https://preprod.tsis.sn/wp-json/tsis/v1/content?lang=zz"
```
Attendu : `"lang": "fr"` (langue invalide → repli sur le français).
En cas d'erreur interne, la réponse est un JSON lisible (`{"error":true,"code":"tsis_bridge_error",...}`)
avec un code `500`, **jamais** de trace PHP ni de requête SQL.

---

## Dépannage

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| 404 sur `/wp-json/tsis/v1/content` | Permaliens non rafraîchis, ou mu-plugin absent | Régénérer les permaliens (Réglages → Permaliens → Enregistrer) ; vérifier le chemin `wp-content/mu-plugins/tsis-headless-bridge.php`. |
| 401 / 403 | Wordfence ou une règle de sécurité bloque `/wp-json/` | Autoriser les requêtes GET anonymes sur `/wp-json/tsis/v1/content` (Wordfence → Firewall → Allowlist, ou désactiver la restriction REST). |
| Réponse figée / ancienne | Cache LiteSpeed | Étape 4, puis vider le cache. |
| `contact.phones` vide ou `null` | Options non stockées sous le préfixe attendu | Le pont lit `options_tsis_telephone` puis `tsis_telephone` ; vérifier les champs dans **Réglages TSIS**. |
| `social` sans YouTube | Champ `tsis_youtube` vide | Comportement voulu : une valeur vide est omise (aucun libellé ni URL inventés). |
| `nav.primary: []` | Aucun menu affecté à `tsis_primary` **et** menu `main` absent | Étape 3 (affecter un menu, ou renommer le menu existant en `main`). |
| `team` vide | Équipe non amorcée | Étape 5 (`wp eval-file import-team.php`). |

## Notes techniques

- **Adaptateur, pas de modèle parallèle** : le pont lit les métadonnées **existantes**
  (`get_option()` / `get_post_meta()`), que ACF soit actif ou non.
- **Options de la page d'options** : ACF stocke les réglages de la page d'options sous le préfixe
  `options_` (par ex. `options_tsis_telephone`). Le pont lit ce préfixe, puis l'option nue en repli.
- **Nettoyage HTML** : les corps (`service_presentation`, `projet_presentation`) passent par une liste
  blanche (`p, h2-h4, ul, ol, li, strong, em, br, a`) construite avec `DOMDocument` : les wrappers
  Elementor (`div`, classes, styles) sont retirés, les balises non autorisées sont « dépliées »
  (leur texte est conservé), `<script>`/`<style>`/`<iframe>` sont supprimés et les liens internes
  deviennent des chemins relatifs (`/contact/`).
- **Aucune authentification** n'est requise en lecture : le contenu est déjà public. L'en-tête
  `X-TSIS-Bridge: 1` permet à Astro de détecter la présence du pont.
- **Cache** : le document est mis en cache 5 minutes côté WordPress (transient invalidé à chaque
  publication, modification d'option ou de terme, et `acf/save_post`).
- **Robustesse** : une donnée absente vaut `null` (ou `[]` pour une liste) ; le pont n'invente
  jamais de contenu, n'écrit rien, ne coupe jamais le site (aucun `exit()`), et ne renvoie jamais de
  message technique (ni trace, ni SQL).
- **Compatibilité** : ACF **ou** métadonnées natives — les valeurs sont lues de la même façon.
  PHP 7.4 à 8.3, API WordPress uniquement, aucune dépendance externe. Aucun champ existant n'est
  renommé, remplacé ni supprimé ; le greffon `tsis-footer` et ses emplacements ne sont pas touchés.

## Ce qui reste à confirmer à l'installation réelle

- **Emplacement de saisie des libellés d'interface** : les champs `tsis_ui_*` sont proposés sur les
  pages (groupe « Contenu des pages »). Le pont les lit en option **puis** sur les pages ; confirmer
  sur la préprod lequel des deux l'éditeur utilise réellement.
- **Images de l'équipe et des réalisations** : vérifier que `custom_logo`, les images à la une et les
  galeries remontent avec une URL absolue et un `alt` non vide.
- **YouTube** : renseigner `tsis_youtube` (actuellement vide) s'il doit apparaître dans les réseaux.
- **Contentieux de production** : `tsis_email` = `contact@preprod.tsis.sn` et `blogname` = « Toubab … »
  sont des valeurs de préprod à corriger en production.