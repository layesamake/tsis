#!/usr/bin/env bash
# ============================================================
# Publication du site construit sur la branche `deploy-astro`
# ------------------------------------------------------------
# Pourquoi : le déploiement Git de Hostinger clone une branche du dépôt et publie son contenu
# tel quel dans la racine servie de astro.tsis.sn. La branche `main` contient les SOURCES
# (astro/, docs/, wordpress/, scripts/) : la publier expose le code et efface le site construit.
# La branche `deploy-astro` contient le site CONSTRUIT à sa racine : c'est elle qu'il faut
# déployer côté hPanel (Site web → Tableau de bord → Avancé → Git → branche : deploy-astro).
#
# Usage :
#   bash scripts/publish-deploy.sh          # construit si besoin puis publie la branche
#   bash scripts/publish-deploy.sh --no-build
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/astro/dist"
BRANCH="deploy-astro"
WORK="$ROOT/../.deploy-astro-work"
REPO_URL="$(git -C "$ROOT" remote get-url origin)"

export GH_CONFIG_DIR="${GH_CONFIG_DIR:-/opt/data/home/.config/gh}"

if [[ "${1:-}" != "--no-build" ]]; then
  echo "→ Construction du site"
  ( cd "$ROOT/astro" && npm run build >/dev/null )
fi

[[ -f "$SRC/index.html" ]] || { echo "❌ Build absent : $SRC/index.html introuvable" >&2; exit 1; }

echo "→ Préparation de la branche $BRANCH"
rm -rf "$WORK"
mkdir -p "$WORK"
cd "$WORK"
git init -q
git config --local user.name "layesamake"
git config --local user.email "12765314+layesamake@users.noreply.github.com"
if command -v gh >/dev/null 2>&1; then
  git config --local credential.helper "!$(command -v gh) auth git-credential"
fi
git checkout -q -b "$BRANCH"
cp -a "$SRC"/. .
: > .nojekyll
git add -A
git commit -q -m "Site TSIS construit ($(date -u +%Y-%m-%dT%H:%MZ))"
git remote add origin "$REPO_URL"
git push -q -f origin "$BRANCH"

echo "✅ Branche $BRANCH publiée — $(git ls-tree -r HEAD --name-only | wc -l) fichiers, $(du -sh . | cut -f1)"
echo "   Côté hPanel, le déploiement Git de astro.tsis.sn doit viser la branche $BRANCH."
