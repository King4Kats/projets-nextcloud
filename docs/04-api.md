# 4. API

Toutes les adresses commencent par `/index.php/apps/temonia_saisie` et répondent en JSON. Elles
sont déclarées dans `apps/temonia_saisie/appinfo/routes.php`.

Depuis un script : authentification « Basic » et en-tête `OCS-APIRequest: true` (c'est ce que font
`demo/remplir.py` et `tests/tester-droits.py`) :

```bash
curl -u thomas:projets2026 -H "OCS-APIRequest: true" \
  http://localhost:8160/index.php/apps/temonia_saisie/api/ref/projets
```

Codes d'erreur : **400** donnée invalide (message dans `message`), **403** réservé ou projet non
autorisé, **404** introuvable.

| Verbe | Adresse | Rôle | Qui |
|---|---|---|---|
| GET | `/` · `/heures` | les pages Projets et Heures | tous |
| GET | `/api/ref/projets` | les projets que je vois | tous |
| GET | `/api/ref/activites?projet=` · `/api/ref/taches?projet=` | arbre d'un projet | membres |
| GET | `/api/ref/users` · `/api/ref/groups` | comptes et groupes (pour choisir) | tous |
| GET | `/api/board` · `/api/planneur` | tableau des tâches, planneur | tous (leurs projets) |
| GET | `/api/board/{id}` | détail d'une tâche | membres |
| PUT | `/api/board/{id}/statut` | `a_faire`, `en_cours`, `fait` | membres |
| POST · DELETE | `/api/board/{id}/liens[/{idx}]` | liens attachés (web, fichier, dossier) | membres |
| GET · POST | `/api/commentaires` | commentaires et blocages | membres |
| PUT | `/api/commentaires/{id}/resolve` | lever un blocage | membres |
| GET · POST | `/api/heures` | mes heures / pointer (`tache`, `jour`, `heures`, `commentaire`) | membres |
| PUT · DELETE | `/api/heures/{id}` | modifier / supprimer **mes** heures | auteur |
| GET · POST | `/api/manage/projets` | lister / créer (`nom`, `couleur`, `description`) | direction |
| PUT · DELETE | `/api/manage/projets/{id}` | modifier (dont `membres`) / supprimer | direction |
| POST · PUT · DELETE | `/api/manage/activites[/{id}]` | activités (`projet`, `nom`, `seuilHeures`) | direction |
| POST | `/api/manage/taches` | créer (`projet`, `activite`, `parent`, `titre`, `assignee`, `heuresPrevues`, `dateDebut`, `echeance`) | membres (proposition) |
| PUT · DELETE | `/api/manage/taches/{id}` | modifier / supprimer | direction |
| GET | `/api/manage/pending` | propositions à valider | direction |
| POST | `/api/manage/taches/{id}/valider` · `/rejeter` | valider / rejeter une proposition | direction |
| GET · POST | `/api/manage/taches/{id}/affectations` | répartir une tâche entre plusieurs personnes | direction |
| DELETE | `/api/manage/affectations/{id}` | retirer une affectation | direction |
| GET | `/api/manage/principals` | personnes, groupes et « Équipe » assignables | direction |
| GET | `/api/manage/avancement/projet` · `/historique/projet` | avancement, historique | direction |
| GET · PUT | `/api/dashboard/stats` · `/api/dashboard/layout` | tableau de bord | tous |
| GET | `/api/admin/stats` · `/api/admin/heures` | administration | admins |
| GET · POST | `/api/admin/export` | export Excel (téléchargement / dans Fichiers) | admins |

Les dates sont au format `AAAA-MM-JJ`, les heures en nombre décimal (`1.5` = 1 h 30).
