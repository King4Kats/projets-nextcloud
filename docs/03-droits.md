# 3. Droits

## Qui voit quoi

```
                       ┌─────────────────────────────────┐
                       │            DIRECTION            │
                       │ admins Nextcloud + group_direction│
                       │ → voit et gère TOUS les projets │
                       └────────────────┬────────────────┘
                                        │ choisit les membres de chaque projet
              ┌─────────────────────────┴─────────────────────────┐
              ▼                                                   ▼
     Fête du jardin 2027                                    Jardin partagé
     membres : groupe Salariés,                             membres : groupe Bénévoles,
               groupe Bénévoles, Paul                                 Thomas

  Thomas (salarié) ✔ ✔      Inès (bénévole) ✔ ✔      Paul (partenaire) ✔ ✘      Marie ✘ ✘
```

Un projet dont on n'est pas membre n'apparaît nulle part : ni dans le menu, ni dans le planneur,
ni dans les listes. Ses tâches répondent « accès refusé ».

| Action | Direction | Membre du projet | Non-membre |
|---|---|---|---|
| Voir le projet, ses tâches, son avancement | ✔ | ✔ | ✘ |
| Pointer des heures sur une tâche | ✔ | ✔ | ✘ |
| Changer le statut d'une tâche, commenter, signaler un blocage | ✔ | ✔ | ✘ |
| Proposer une tâche (elle attend la validation de la direction) | ✔ | ✔ | ✘ |
| Créer, modifier, supprimer projets / activités / tâches, choisir les membres | ✔ | ✘ | ✘ |
| Assigner une tâche à quelqu'un | ✔ | ✘ | ✘ |
| Modifier ou supprimer **ses** heures | ✔ | ✔ | — |
| Écran Administration, export Excel | admins Nextcloud | ✘ | ✘ |

## Où sont les règles

- `temonia_pilotage/lib/Service/AccessService.php` : **qui est direction**, **quels projets voit
  une personne** (`accessibleProjetIds`), **une tâche la concerne-t-elle** (`isAssignedTo`).
- Chaque contrôleur de `temonia_saisie` appelle ces fonctions au début de chaque action, et
  répond 403 (« Réservé à la direction » / « Accès refusé à ce projet ») sinon.

## Vérifié automatiquement

`tests/tester-droits.py` se connecte successivement avec chaque compte de la démo et vérifie
qui voit quels projets, qui peut créer, pointer, supprimer, et que les données invalides sont
refusées. Il tourne à chaque envoi sur GitHub (voir [Développer](05-developper.md)).
