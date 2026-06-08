#!/bin/bash
# Génère un ZIP installable avec le bon nom de dossier (portail-entreprises-b2b).
# Usage : bash build.sh
# Le ZIP est créé dans le dossier courant.

SLUG="portail-entreprises-b2b"
VERSION=$(grep "define('PE_VERSION'" portail-entreprises.php | grep -oP "'\d+\.\d+\.\d+'" | tr -d "'")
ZIP_NAME="${SLUG}-${VERSION}.zip"
TMP="/tmp/${SLUG}"

echo "→ Version détectée : ${VERSION}"
echo "→ Création du ZIP : ${ZIP_NAME}"

rm -rf "${TMP}"
mkdir -p "${TMP}"

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitignore' \
  --exclude='build.sh' \
  --exclude='*.md' \
  --exclude='node_modules' \
  ./ "${TMP}/"

cd /tmp
zip -rq "${ZIP_NAME}" "${SLUG}/"
mv "/tmp/${ZIP_NAME}" "$OLDPWD/"

echo "✓ ZIP prêt : ${OLDPWD}/${ZIP_NAME}"
echo "  → Installez-le via WordPress > Extensions > Ajouter > Téléverser"
