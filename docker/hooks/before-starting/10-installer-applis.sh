#!/bin/sh
# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
# Exécuté à CHAQUE démarrage du conteneur (par l'image Nextcloud officielle, en www-data).
#
# Copie les deux applis depuis l'image (/opt/apps-projets) vers custom_apps, SEULEMENT si la
# version de l'image est plus récente que celle installée : une version de travail déposée avec
# scripts/deployer.sh n'est donc pas écrasée par un simple redémarrage.
set -eu

SOURCE=/opt/apps-projets
CIBLE=/var/www/html/custom_apps
OCC="php /var/www/html/occ"

# Lit <version>…</version> dans un info.xml (vide si l'appli n'est pas là).
version_de() {
  [ -f "$1/appinfo/info.xml" ] || { echo ""; return; }
  sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$1/appinfo/info.xml" | head -n 1
}

# Ordre imposé : le socle (pilotage) d'abord, l'interface (saisie) s'appuie dessus.
for app in temonia_pilotage temonia_saisie; do
  image=$(version_de "$SOURCE/$app")
  installee=$(version_de "$CIBLE/$app")
  # version_compare de PHP renvoie 1 si la version de l'image est plus récente.
  if [ -z "$installee" ] || [ "$(php -r "echo (int) version_compare('$image', '$installee', '>');")" = "1" ]; then
    echo "[projets] $app : installation de la version $image (installée : ${installee:-aucune})"
    rm -rf "${CIBLE:?}/$app"
    cp -R "$SOURCE/$app" "$CIBLE/$app"
  fi
  $OCC app:enable "$app" >/dev/null
done

# Migrations si une version a changé. Pas de « || true » : si une migration échoue, on préfère
# que le conteneur s'arrête avec un message clair.
if ! sortie=$($OCC upgrade 2>&1); then
  echo "[projets] ÉCHEC de « occ upgrade » : le conteneur s'arrête."
  echo "$sortie"
  exit 1
fi
