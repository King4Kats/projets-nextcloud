#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
# Pendant le développement : dépose les applis de apps/ dans la démo qui tourne
# (conteneur projets-nc, lancé par « docker compose up »), sans reconstruire l'image.
# D'habitude : ./scripts/importer.sh d'abord, pour reprendre la dernière version commitée.
#
#   ./scripts/deployer.sh
#
# Pensez à monter <version> dans appinfo/info.xml quand la base change (nouvelle migration) :
# Nextcloud ne lance les migrations que si la version augmente.
set -euo pipefail
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1
CONTENEUR="${CONTENEUR:-projets-nc}"

# On envoie chaque appli en flux tar (fonctionne partout, même sous Windows). Socle d'abord.
for app in temonia_pilotage temonia_saisie; do
  docker exec "$CONTENEUR" rm -rf "/var/www/html/custom_apps/$app"
  tar -C "$RACINE/apps/$app" -cf - . | docker exec -i "$CONTENEUR" sh -c     "mkdir -p /var/www/html/custom_apps/$app && tar -xf - -C /var/www/html/custom_apps/$app && chown -R www-data:www-data /var/www/html/custom_apps/$app"
  docker exec -u www-data "$CONTENEUR" php occ app:enable "$app" >/dev/null
done
docker exec -u www-data "$CONTENEUR" php occ upgrade >/dev/null
echo "✓ Applis déposées dans $CONTENEUR : rechargez la page."
