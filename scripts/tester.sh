#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
# Tous les tests de l'appli, en une commande :
#   1. syntaxe : chaque fichier PHP (php -l) et le JavaScript (node --check) ;
#   2. droits  : une installation Nextcloud NEUVE et JETABLE (port 8161), remplie avec les projets
#      d'exemple (demo/remplir.py), puis tests/tester-droits.py vérifie compte par compte qui voit
#      et fait quoi. Le conteneur et son volume sont supprimés à la fin : la démo (port 8160)
#      n'est pas touchée.
#
#   ./scripts/tester.sh            → tout (≈ 3 à 5 min, Docker + Python 3 + Node requis)
#   ./scripts/tester.sh --syntaxe  → seulement l'étape 1 (quelques secondes)
#
# C'est aussi ce que lance GitHub à chaque envoi (.github/workflows/tests.yml).
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1   # Git Bash (Windows) : ne pas « traduire » les chemins du conteneur
# Les chemins de NOTRE machine doivent être donnés au format Windows à docker sous Git Bash.
chemin_hote() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
PYTHON="$(command -v python3 || command -v python || true)"

# ── 1. Syntaxe ───────────────────────────────────────────────────────────────
echo "→ Syntaxe PHP"
# PHP de l'image officielle : pas besoin de l'installer sur la machine.
docker run --rm -v "$(chemin_hote "$RACINE"):/w" -w /w php:8.3-cli \
  sh -c 'for f in $(find apps -name "*.php" -not -path "*/vendor/*"); do php -l "$f" >/dev/null || exit 1; done'
echo "→ Syntaxe JavaScript"
for f in "$RACINE"/apps/temonia_saisie/js/*.js; do node --check "$(chemin_hote "$f")"; done
echo "✓ Syntaxe OK"
[[ "${1:-}" == "--syntaxe" ]] && exit 0

# ── 2. Droits, sur une installation neuve ────────────────────────────────────
[ -n "$PYTHON" ] || { echo "✗ Python 3 est nécessaire pour le test des droits."; exit 1; }
CONTENEUR="projets-test"
PORT="${PORT:-8161}"
IMAGE="projets-nextcloud-demo:test"

echo "→ Construction de l'image…"
docker build -q -f "$(chemin_hote "$RACINE/docker/Dockerfile")" -t "$IMAGE" "$(chemin_hote "$RACINE")" >/dev/null

# Nettoyage garanti à la fin, même si un test échoue (trap … EXIT).
nettoyer() {
  docker rm -f "$CONTENEUR" >/dev/null 2>&1 || true
  docker volume rm "$CONTENEUR-data" >/dev/null 2>&1 || true
}
nettoyer
trap nettoyer EXIT

echo "→ Installation neuve dans un conteneur jetable (1 à 3 min)…"
docker run -d --name "$CONTENEUR" -p "$PORT:80" \
  -e SQLITE_DATABASE=nextcloud \
  -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=projets2026 \
  -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
  -v "$CONTENEUR-data:/var/www/html" "$IMAGE" >/dev/null

# On attend la fin du hook post-installation, au plus 5 minutes.
for _ in $(seq 1 100); do
  if [[ "$(docker inspect -f '{{.State.Running}}' "$CONTENEUR")" != "true" ]]; then
    echo "✗ Le conteneur s'est arrêté pendant l'installation :"; docker logs "$CONTENEUR" 2>&1 | tail -40; exit 1
  fi
  docker logs "$CONTENEUR" 2>&1 | grep -q "Nextcloud prêt" && break
  sleep 3
done
docker logs "$CONTENEUR" 2>&1 | grep -q "Nextcloud prêt" || { echo "✗ Installation trop longue"; docker logs "$CONTENEUR" 2>&1 | tail -40; exit 1; }
# Apache démarre juste après les hooks : on attend qu'il réponde.
for _ in $(seq 1 30); do curl -fs "http://localhost:$PORT/status.php" >/dev/null && break; sleep 2; done

echo "→ Projets d'exemple…"
PYTHONIOENCODING=utf-8 "$PYTHON" "$(chemin_hote "$RACINE/demo/remplir.py")" "http://localhost:$PORT" --attendre
echo "→ Test des droits, compte par compte…"
PYTHONIOENCODING=utf-8 "$PYTHON" "$(chemin_hote "$RACINE/tests/tester-droits.py")" "http://localhost:$PORT"
