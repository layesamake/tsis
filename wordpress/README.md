# Pont de contenu WordPress → Astro (TSIS)

Ce dossier contient tout ce qu'il faut poser sur le WordPress de TSIS (`preprod.tsis.sn`)
pour que le site Astro récupère son contenu depuis WordPress via une seule route :

```
GET https://preprod.tsis.sn/wp-json/tsis/v1/content?lang=fr
```

Aucune compétence en développement n'est nécessaire : suivez les étapes dans l'ordre.
Le pont est **additif** : tant qu'il n'est pas installé, le site continue de fonctionner.

**Règle de sécurité, valable partout** : ne communiquez jamais de mot de passe, de clé ou
d'identifiant dans une conversation, un chat ou un e-mail. Saisissez vos identifiants
uniquement dans les pages de connexion officielles (hPanel, client FTP, administration WordPress).

## Contenu du dossier

| Fichier | Rôle |
| --- | --- |
| `mu-plugins/tsis-headless-bridge.php` | Le « pont » : expose le contenu à l'API REST. Indispensable. |
| `acf/tsis-content-fields.json` | Groupes de champs ACF (saisie structurée dans l'admin). Recommandé. |
| `import/import-team.php` | Crée les 25 membres de l'équipe à partir de `team.json`. Une fois. |
| `README.md` | Ce document. |

---

## Étape 1 — Déposer le mu-plugin (pont)

Un greffon « must-use » s'active automatiquement, sans passer par l'administration.

1. Ouvrez le **Gestionnaire de fichiers** de hPanel (ou connectez-vous en FTP avec un client comme FileZilla).
2. Rendez-vous dans le dossier WordPress, en général `public_html/` (repérez le dossier qui
   contient `wp-config.php`). Chemin visé : `public_html/wp-content/`.
3. S'il n'existe pas, **créez** le dossier `mu-plugins` à l'intérieur de `wp-content`.
4. Envoyez (téléversez) le fichier `tsis-headless-bridge.php` dans `wp-content/mu-plugins/`.

Résultat : `public_html/wp-content/mu-plugins/tsis-headless-bridge.php`.

Vérification immédiate (dans le navigateur) :
`https://preprod.tsis.sn/wp-json/tsis/v1/content`
→ vous devez voir du JSON commençant par `{"schemaVersion":1,...`. Si vous voyez une page
d'erreur 404, passez à l'étape 6 (dépannage).

---

## Étape 2 — Importer les champs ACF

Les champs ACF donnent à l'éditeur des formulaires clairs dans l'administration.
Sans eux, le pont **fonctionne quand même** (il lit les valeurs brutes), mais la saisie est moins guidée.

> Prérequis : le greffon **ACF** est installé. Les répéteurs, la galerie, la relation et la
> **page d'options** nécessitent **ACF PRO**. Le fichier `tsis-content-fields.json` contient
> 7 groupes (réglages du site, libellés d'interface, page d'accueil, page À propos, services,
> réalisations, équipe).

**Méthode A — en ligne de commande (recommandée, accepte plusieurs groupes d'un coup)**

```
wp acf json import /chemin/vers/wordpress/acf/tsis-content-fields.json
```
Attendu : une ligne `Imported field-group: ...` par groupe puis `Success: Imported 7 item(s).`

**Méthode B — sans ligne de commande (via l'administration)**

1. Ouvrez l'administration WordPress → **ACF** → **Outils** (Tools) → **Import de champs**.
2. Importez le fichier `tsis-content-fields.json`.
3. Si l'interface n'accepte qu'un groupe à la fois, utilisez la méthode **Local JSON** :
   placez le fichier dans un dossier `acf-json/` à la racine du thème (`wp-content/themes/hello-elementor/acf-json/`),
   puis cliquez **Synchroniser** dans ACF → Outils.

Après import, un menu **Contenu TSIS** apparaît dans l'administration (page d'options ACF PRO).

---

## Étape 3 — Déclarer et affecter les menus

1. Administration WordPress → **Apparence** → **Menus**.
2. Créez (ou réutilisez) un menu, par exemple « Navigation principale », et ajoutez-y vos pages.
3. En bas de l'écran, section **« Emplacements des menus »** (*Manage Locations*), affectez :
   - emplacement **« TSIS — Navigation principale »** → votre menu principal ;
   - emplacement **« TSIS — Navigation de pied de page »** → votre menu de pied de page.
4. Enregistrez.
5. Optionnel : dans **Contenu TSIS** → « Bouton principal d'en-tête », renseignez le libellé et
   le lien du bouton d'en-tête (ex. « Demander un devis » → `/contact/`).

Un menu non affecté reste vide dans l'API (`[]`) : rien n'est inventé, le site conserve sa navigation actuelle.

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

**3. Compter les entités (attendus : 9 services, 11 réalisations, 11 secteurs, 25 membres après l'étape 5)**
```
curl -sS "https://preprod.tsis.sn/wp-json/tsis/v1/content" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('services',len(d['services']),'| realisations',len(d['realisations']),'| secteurs',len(d['secteurs']),'| team',len(d['team']))"
```
Attendu : `services 9 | realisations 11 | secteurs 11 | team 25`

**4. Réponse lisible en cas de problème**
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
| `"acf": []` sur `/wp/v2/...` | Champs ACF non créés | Étape 2 (import du JSON ACF). |
| `team` vide | Équipe non amorcée | Étape 5 (`wp eval-file import-team.php`). |
| Menus vides (`nav.primary: []`) | Aucun menu affecté aux emplacements | Étape 3. |

## Notes techniques

- **Aucune authentification** n'est requise en lecture : le contenu est déjà public. L'en-tête
  `X-TSIS-Bridge: 1` permet à Astro de détecter la présence du pont.
- **Cache** : le document est mis en cache 5 minutes côté WordPress (transient invalidé à chaque
  publication, modification d'option ou de terme).
- **Robustesse** : une donnée absente vaut `null` (ou `[]` pour une liste) ; le pont n'invente
  jamais de contenu et ne renvoie jamais de message technique (ni trace, ni SQL).
- **Compatibilité** : ACF **ou** métadonnées natives saisies à la main — les valeurs sont lues de
  la même façon. PHP 7.4 à 8.3, API WordPress uniquement, aucune dépendance externe.
- Les clés du JSON correspondent **exactement** au contrat de contenu (`docs/contrat-contenu-wp.md` §5).