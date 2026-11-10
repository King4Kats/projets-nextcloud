#!/bin/sh
# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
# Exécuté UNE SEULE FOIS, juste après la toute première installation de Nextcloud (volume vide).
# Langue française, groupes, comptes fictifs. Les projets d'exemple sont créés ensuite par le
# service « demo » de docker-compose.yml (demo/remplir.py), une fois Nextcloud démarré.
set -eu

OCC="php /var/www/html/occ"
MDP="${PROJETS_MDP:-projets2026}"

echo "[projets] Configuration de la démo…"
$OCC config:system:set default_language --value=fr
$OCC config:system:set default_locale --value=fr_FR
$OCC config:system:set default_timezone --value=Europe/Paris
$OCC app:disable firstrunwizard >/dev/null 2>&1 || true

sh /docker-entrypoint-hooks.d/before-starting/10-installer-applis.sh

# Les 4 groupes que l'appli sait distinguer. Leurs identifiants sont libres : on dit à l'appli
# lesquels utiliser avec occ config:app:set temonia_pilotage group_… (voir docs/01-demarrer.md).
groupe() { $OCC group:add --display-name "$2" "$1" >/dev/null 2>&1 || true; }
groupe direction   "Direction"
groupe salaries    "Salariés"
groupe benevoles   "Bénévoles"
groupe partenaires "Partenaires"
$OCC config:app:set temonia_pilotage group_direction       --value=direction   >/dev/null
$OCC config:app:set temonia_pilotage group_equipe_salarie  --value=salaries    >/dev/null
$OCC config:app:set temonia_pilotage group_equipe_benevole --value=benevoles   >/dev/null
$OCC config:app:set temonia_pilotage group_equipe_pompier  --value=partenaires >/dev/null
# Les mots affichés (nom de la structure, « Partenaires »…) : ici ceux d'une association fictive.
$OCC config:app:set temonia_pilotage libelles --value='{"organisation":"Les Jardins du Bourg"}' >/dev/null

# Comptes FICTIFS.
compte() { # identifiant, nom affiché, groupe
  OC_PASS="$MDP" $OCC user:add --password-from-env --display-name "$2" "$1" >/dev/null
  $OCC group:adduser "$3" "$1" >/dev/null
}
compte claire "Claire Dubois" direction     # voit et gère tous les projets
compte thomas "Thomas Petit"  salaries      # membre de l'équipe
compte ines   "Inès Morel"    benevoles     # bénévole
compte paul   "Paul Lefèvre"  partenaires   # partenaire extérieur : un seul projet
compte marie  "Marie Garnier" partenaires   # partenaire sans projet : ne voit rien
echo "[projets] ✓ Nextcloud prêt"
