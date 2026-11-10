#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
# Recopie dans apps/ la version COMMITÉE des deux applis depuis leurs dépôts de travail,
# rangés à côté de celui-ci (../temonia-pilotage et ../temonia-saisie).
#
#   ./scripts/importer.sh
#
# Pourquoi deux endroits ? Les applis sont développées et déployées depuis ces dépôts de travail
# (privés : ils contiennent aussi la documentation d'exploitation d'une structure). Ce dépôt public
# en reçoit une copie fidèle du code, et rien d'autre. Un seul code, donc : on modifie là-bas,
# on importe ici, on teste (./scripts/tester.sh), on publie.
set -euo pipefail
RACINE="$(cd "$(dirname "$0")/.." && pwd)"

for paire in "temonia_pilotage:temonia-pilotage" "temonia_saisie:temonia-saisie"; do
  appid="${paire%%:*}"; depot="$RACINE/../${paire#*:}"
  [[ -d "$depot/.git" ]] || { echo "✗ $depot introuvable"; exit 1; }
  rm -rf "${RACINE:?}/apps/$appid"
  mkdir -p "$RACINE/apps/$appid"
  # git archive : uniquement ce qui est commité dans app/, sans fichiers de travail.
  git -C "$depot" archive HEAD app | tar -x -C "$RACINE/apps/$appid" --strip-components=1
  echo "✓ $appid ← $(git -C "$depot" log -1 --format='%h %s' | cut -c1-70)"
done
