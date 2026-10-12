#!/usr/bin/env bash
# ============================================================
# Déploiement du site Astro sur l'hébergement Hostinger
# ------------------------------------------------------------
# Envoie le contenu de astro/dist/ vers le répertoire cible via SSH (scp).
#
# ATTENTION — deux pièges connus sur ce compte :
#  1. La racine réellement servie pour astro.tsis.sn est domains/tsis.sn/public_html/astro,
#     à l'intérieur du dossier de tsis.sn (le WordPress de production : ne jamais y écrire).
#  2. Le déploiement Git de Hostinger clone une branche du dépôt et publie son contenu brut dans
#     cette racine : un push sur `main` EFFACE le site construit. Après tout push sur main,
#     rejouer ce script pour restaurer le site, ou basculer hPanel sur la branche `deploy-astro`
#     (voir scripts/publish-deploy.sh), qui contient le site construit à sa racine.
#
# Variables d'environnement attendues (à placer dans .env.deploy, non versionné) :
#   DEPLOY_HOST       hôte SSH          (ex. 123.45.67.89 ou astro.tsis.sn)
#   DEPLOY_USER       utilisateur SSH   (ex. u123456789)
#   DEPLOY_PORT       port SSH          (défaut : 65002 chez Hostinger)
#   DEPLOY_PATH       répertoire cible distant (ex. /home/u123456789/domains/astro.tsis.sn/public_html)
#   DEPLOY_KEY        chemin de la clé privée SSH (optionnel)
#
# Usage :
#   bash scripts/deploy.sh --dry-run     # affiche ce qui serait envoyé
#   bash scripts/deploy.sh               # envoie réellement
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/astro/dist"
ENVFILE="$ROOT/.env.deploy"

# Charger les variables sans exécuter de contenu arbitraire
if [[ -f "$ENVFILE" ]]; then
  while IFS= read -r line; do
    [[ -z "$line" || "$line" =~ ^# ]] && continue
    key="${line%%=*}"; val="${line#*=}"
    key="$(echo "$key" | tr -d '[:space:]')"
    [[ -z "$key" ]] && continue
    export "$key=$val"
  done < "$ENVFILE"
fi

: "${DEPLOY_HOST:?DEPLOY_HOST manquant}"
: "${DEPLOY_USER:?DEPLOY_USER manquant}"
: "${DEPLOY_PATH:?DEPLOY_PATH manquant}"
DEPLOY_PORT="${DEPLOY_PORT:-65002}"

DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

if [[ ! -d "$DIST" ]]; then
  echo "❌ $DIST introuvable. Lancez d'abord : cd astro && npm run build" >&2
  exit 1
fi

SSH_OPTS=(-p "$DEPLOY_PORT" -o StrictHostKeyChecking=accept-new)
SCP_OPTS=(-P "$DEPLOY_PORT" -o StrictHostKeyChecking=accept-new)
if [[ -n "${DEPLOY_KEY:-}" ]]; then
  SSH_OPTS+=(-i "$DEPLOY_KEY")
  SCP_OPTS+=(-i "$DEPLOY_KEY")
fi

echo "→ Cible : $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH (port $DEPLOY_PORT)"
echo "→ Source : $DIST"

if [[ "$DRY_RUN" == "1" ]]; then
  echo "[dry-run] Fichiers qui seraient envoyés :"
  (cd "$DIST" && find . -type f | sort | sed 's/^/   /')
  echo "[dry-run] Aucun envoi effectué."
  exit 0
fi

# S'assurer que le répertoire cible existe
ssh "${SSH_OPTS[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "mkdir -p '$DEPLOY_PATH'"

# Envoyer le contenu (sans écraser le répertoire lui-même)
scp "${SCP_OPTS[@]}" -r "$DIST"/. "$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"

echo "✅ Fichiers envoyés."
echo "   Vérifiez : curl -sI https://astro.tsis.sn/ | head -1"
