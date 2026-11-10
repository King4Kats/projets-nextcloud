# SPDX-FileCopyrightText: 2026 Flavien Auvray
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Crée des projets FICTIFS dans l'appli Projets, par l'API, comme le ferait un utilisateur.

    python demo/remplir.py [http://localhost:8160] [--attendre]

--attendre : patiente jusqu'à ce que Nextcloud réponde (utilisé par docker-compose.yml).
Ne fait rien si des projets existent déjà : on peut le relancer sans créer de doublons.
Comptes : ceux de la démo Docker (mot de passe PROJETS_MDP, par défaut « projets2026 »).
"""
import base64
import datetime
import json
import os
import sys
import time
import urllib.error
import urllib.request

ARGS = [a for a in sys.argv[1:] if not a.startswith("--")]
BASE = (ARGS[0] if ARGS else "http://localhost:8160") + "/index.php/apps/temonia_saisie/api"
MDP = os.environ.get("PROJETS_MDP", "projets2026")


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


def ok(code, rep, quoi):
    """Arrête tout si une création échoue : une démo à moitié remplie trompe plus qu'elle n'aide."""
    if code not in (200, 201):
        sys.exit(f"✗ {quoi} : HTTP {code} {rep}")
    return rep


def jours(n):
    """Date AAAA-MM-JJ à n jours d'aujourd'hui : la démo reste « fraîche » quel que soit le jour."""
    return (datetime.date.today() + datetime.timedelta(days=n)).isoformat()


# ── Attendre Nextcloud (au premier lancement, l'installation prend 1 à 3 minutes) ──
if "--attendre" in sys.argv:
    for _ in range(120):
        try:
            if appel("claire", "GET", "/ref/projets")[0] == 200:
                break
        except (urllib.error.URLError, OSError):
            pass
        time.sleep(5)
    else:
        sys.exit("✗ Nextcloud ne répond pas")

code, existants = appel("claire", "GET", "/manage/projets")
ok(code, existants, "lecture des projets")
if existants:
    print("Des projets existent déjà : rien à faire.")
    sys.exit(0)

# ── Les projets : (nom, couleur, description, membres, activités) ──
# Membres du projet (qui le VOIT) : des comptes (« paul ») et des groupes Nextcloud.
# Une tâche, elle, s'assigne à un compte (« thomas »), un groupe (« group:benevoles ») ou à toute
# l'équipe salariés + bénévoles (« team:temonia », identifiant technique historique).
PROJETS = [
    ("Fête du jardin 2027", "#2E7D6B", "La fête annuelle : stands, ateliers, buvette.", [("group", "salaries"), ("group", "benevoles"), ("user", "paul")], [
        ("Préparation", 40, [
            ("Réserver le matériel (tables, barnums)", "thomas", 4, 10, "en_cours"),
            ("Demander l'autorisation à la mairie", "claire", 2, 5, "fait"),
            ("Contacter les exposants", "paul", 6, 20, "a_faire"),
        ]),
        ("Communication", 20, [
            ("Affiches et flyers", "ines", 8, 25, "a_faire"),
            ("Annonce sur les réseaux", "team:temonia", 3, 30, "a_faire"),
        ]),
    ]),
    ("Jardin partagé", "#6C5CE7", "Entretien des parcelles et ateliers du samedi.", [("group", "benevoles"), ("user", "thomas")], [
        ("Entretien", 60, [
            ("Tailler la haie", "ines", 6, -3, "en_cours"),
            ("Réparer le récupérateur d'eau", "thomas", 3, 12, "a_faire"),
        ]),
        ("Ateliers", 30, [
            ("Atelier semis avec l'école", "group:benevoles", 10, 40, "a_faire"),
        ]),
    ]),
]

par_titre = {}   # titre de tâche → id, pour pointer des heures ensuite
for nom, couleur, description, membres, activites in PROJETS:
    projet = ok(*appel("claire", "POST", "/manage/projets", {"nom": nom, "couleur": couleur, "description": description}), "projet " + nom)
    ok(*appel("claire", "PUT", f"/manage/projets/{projet['id']}", {"membres": [{"type": t, "id": i} for t, i in membres]}), "membres de " + nom)
    for anom, seuil, taches in activites:
        act = ok(*appel("claire", "POST", "/manage/activites", {"projet": projet["id"], "nom": anom, "seuilHeures": seuil}), "activité " + anom)
        for titre, qui, prevues, echeance, statut in taches:
            t = ok(*appel("claire", "POST", "/manage/taches", {
                "projet": projet["id"], "activite": act["id"], "titre": titre, "assignee": qui,
                "heuresPrevues": prevues, "dateDebut": jours(-20), "echeance": jours(echeance)}), "tâche " + titre)
            par_titre[titre] = t["id"]
            if statut != "a_faire":
                ok(*appel("claire", "PUT", f"/board/{t['id']}/statut", {"statut": statut}), "statut " + titre)
    print("OK", nom)

# ── Quelques heures pointées, pour que les tableaux de bord aient quelque chose à montrer ──
for qui, titre, jour, heures in [
    ("thomas", "Réserver le matériel (tables, barnums)", -6, 1.5),
    ("thomas", "Réserver le matériel (tables, barnums)", -2, 1),
    ("ines", "Tailler la haie", -5, 3),
    ("ines", "Tailler la haie", -1, 2.5),
    ("thomas", "Réparer le récupérateur d'eau", -3, 1),
]:
    if titre in par_titre:
        ok(*appel(qui, "POST", "/heures", {"tache": par_titre[titre], "jour": jours(jour), "heures": heures}), "heures " + titre)
print("✓ Projets d'exemple créés")
