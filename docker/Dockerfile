# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
# Image de démonstration « tout compris » : Nextcloud 34 + l'appli Projets (ses deux parties).
#
# Utilisée par docker-compose.yml (« docker compose up --build ») et par scripts/tester.sh.
# Rien à compiler : PHP + JavaScript tels quels, la bibliothèque Excel (PhpSpreadsheet) est déjà
# dans apps/temonia_saisie/vendor.
#
# REPRODUCTIBILITÉ : image de base désignée par son tag (pour les humains) ET son empreinte
# @sha256 (c'est elle qui fait foi).
FROM nextcloud:34.0.5-apache@sha256:b89746a4febc93de82141921417f43b0d5ce69c3746feb485372559e9fbeec3f

# Les applis sont déposées À CÔTÉ de Nextcloud ; le hook before-starting les copie dans
# custom_apps au démarrage (jamais de dossier du PC « monté » dans custom_apps : ça casse
# l'installation initiale de Nextcloud).
COPY apps/temonia_pilotage /opt/apps-projets/temonia_pilotage
COPY apps/temonia_saisie /opt/apps-projets/temonia_saisie

# Hooks de l'image officielle : post-installation = une fois, après la 1re installation ;
# before-starting = à chaque démarrage.
COPY docker/hooks/post-installation/ /docker-entrypoint-hooks.d/post-installation/
COPY docker/hooks/before-starting/ /docker-entrypoint-hooks.d/before-starting/
RUN chmod +x /docker-entrypoint-hooks.d/*/*.sh \
    && chown -R www-data:www-data /opt/apps-projets
