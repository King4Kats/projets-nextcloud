# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Test des droits de l'appli Projets, compte par compte, par l'API.

    python tests/tester-droits.py [http://localhost:8160]

À lancer sur la démo remplie (demo/remplir.py) : « Fête du jardin 2027 » (salariés, bénévoles
et Paul) et « Jardin partagé » (bénévoles et Thomas). Lancé automatiquement par scripts/tester.sh.
"""
import base64
import json
import os
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8160") + "/index.php/apps/temonia_saisie/api"
MDP = os.environ.get("PROJETS_MDP", "projets2026")
echecs = 0


def appel(qui, methode, chemin, corps=None):
    """Renvoie (code HTTP, JSON). Basic auth + OCS-APIRequest : pas besoin de jeton CSRF."""
    donnees = json.dumps(corps).encode() if corps is not None else None
    req = urllib.request.Request(BASE + chemin, data=donnees, method=methode)
    req.add_header("Authorization", "Basic " + base64.b64encode(f"{qui}:{MDP}".encode()).decode())
    req.add_header("OCS-APIRequest", "true")
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        texte = e.read()
        try:
            return e.code, json.loads(texte)
        except ValueError:
            return e.code, texte[:200]


def verifier(libelle, condition, detail=""):
    global echecs
    print(("  OK   " if condition else "  ÉCHEC ") + libelle + ("" if condition else f"  → {detail}"))
    if not condition:
        echecs += 1


def projets_vus(qui):
    """Noms des projets que la personne voit (liste de référence de l'écran)."""
    code, r = appel(qui, "GET", "/ref/projets")
    return code, sorted(p["nom"] for p in r) if isinstance(r, list) else r


FETE, JARDIN = "Fête du jardin 2027", "Jardin partagé"

print("Qui voit quels projets")
c, v = projets_vus("claire"); verifier("Claire (direction) voit les 2 projets", v == sorted([FETE, JARDIN]), v)
c, v = projets_vus("thomas"); verifier("Thomas (salarié, membre des 2) voit les 2", v == sorted([FETE, JARDIN]), v)
c, v = projets_vus("ines"); verifier("Inès (bénévole) voit les 2 par son groupe", v == sorted([FETE, JARDIN]), v)
c, v = projets_vus("paul"); verifier("Paul (partenaire) ne voit QUE la fête", v == [FETE], v)
c, v = projets_vus("marie"); verifier("Marie (partenaire sans projet) ne voit rien", v == [], v)

print("Réservé à la direction")
c, projets = appel("claire", "GET", "/manage/projets")
ids = {p["nom"]: p["id"] for p in projets}
c, _ = appel("paul", "POST", "/manage/projets", {"nom": "Projet pirate"})
verifier("Paul ne peut pas créer de projet → 403", c == 403, c)
c, _ = appel("thomas", "GET", "/manage/projets")
verifier("Thomas ne voit pas la gestion des projets → 403", c == 403, c)
c, _ = appel("ines", "GET", "/admin/stats")
verifier("Inès n'a pas accès à l'administration → 403", c == 403, c)
c, _ = appel("admin", "GET", "/admin/stats")
verifier("L'administrateur a accès à l'administration", c == 200, c)

print("Tâches et heures")
c, t_jardin = appel("claire", "GET", f"/ref/taches?projet={ids[JARDIN]}")
tache_jardin = t_jardin[0]["id"]
c, t_paul = appel("paul", "GET", f"/ref/taches?projet={ids[JARDIN]}")
verifier("Paul ne reçoit aucune tâche du jardin (liste vide, rien n'est révélé)", t_paul == [], t_paul)
c, _ = appel("paul", "GET", f"/board/{tache_jardin}")
verifier("Paul ne peut pas ouvrir une tâche du jardin → 403", c == 403, c)
c, _ = appel("paul", "POST", "/heures", {"tache": tache_jardin, "jour": "2026-01-05", "heures": 2})
verifier("Paul ne peut pas pointer sur le jardin → 403", c == 403, c)
c, r = appel("ines", "POST", "/heures", {"tache": tache_jardin, "jour": "2026-01-05", "heures": 2})
verifier("Inès pointe 2 h sur le jardin", c in (200, 201), r)
if isinstance(r, dict) and r.get("id"):
    c, _ = appel("thomas", "DELETE", f"/heures/{r['id']}")
    verifier("Thomas ne peut pas supprimer les heures d'Inès", c in (403, 404), c)
    c, _ = appel("ines", "DELETE", f"/heures/{r['id']}")
    verifier("Inès supprime ses propres heures", c == 200, c)
c, _ = appel("ines", "POST", "/heures", {"tache": tache_jardin, "jour": "2026-01-05", "heures": 0})
verifier("Refuse 0 heure → 400", c == 400, c)
c, _ = appel("ines", "PUT", f"/board/{tache_jardin}/statut", {"statut": "n_importe_quoi"})
verifier("Refuse un statut inconnu → 400", c == 400, c)

print()
print("TOUT EST BON" if not echecs else f"{echecs} ÉCHEC(S)")
sys.exit(1 if echecs else 0)
