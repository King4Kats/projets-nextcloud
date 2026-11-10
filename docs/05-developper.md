# 5. Développer

## D'où vient le code

Les deux applis sont développées dans des dépôts de travail (privés, car ils contiennent aussi la
documentation d'exploitation d'une structure). Ce dépôt public en reçoit une **copie fidèle du
code**, par un script :

```
  ../temonia-pilotage/app ─┐
                           ├──▶ ./scripts/importer.sh ──▶ apps/ ──▶ ./scripts/tester.sh ──▶ git push
  ../temonia-saisie/app   ─┘     (version commitée)
```

Un seul code, donc. Une issue ou une proposition de modification ici est la bienvenue : elle est
reportée dans le dépôt de travail, puis réimportée.

Sans les dépôts de travail, on peut tout à fait développer directement dans `apps/`.

## La boucle de travail

1. `docker compose up --build` une fois, et laisser tourner.
2. Modifier les fichiers dans `apps/` (ou `./scripts/importer.sh` après un commit là-bas).
3. `./scripts/deployer.sh` : les deux applis sont recopiées dans le conteneur `projets-nc`.
4. Recharger la page (Ctrl + F5 si le navigateur garde l'ancien JavaScript).
5. Avant d'envoyer : `./scripts/tester.sh` (syntaxe + droits sur une installation neuve, ≈ 3 min).
   GitHub relance les mêmes tests à chaque envoi.

Sous Windows, lancer les scripts dans **Git Bash**.

## Changer la base de données

1. Ajouter une migration dans `apps/temonia_pilotage/lib/Migration/` (copier la dernière).
2. **Monter `<version>`** dans `apps/temonia_pilotage/appinfo/info.xml` : Nextcloud ne lance les
   migrations que si la version augmente.
3. Une classe dans `lib/Db/` (l'entité) et son Mapper (les requêtes, avec paramètres liés).

## Où écrire quoi

| Je veux… | Fichier |
|---|---|
| une adresse d'API | `temonia_saisie/appinfo/routes.php` + une méthode dans un contrôleur |
| une règle d'accès | `temonia_pilotage/lib/Service/AccessService.php` |
| lire ou écrire en base | un Mapper de `temonia_pilotage/lib/Db/` |
| un écran, un bouton | `temonia_saisie/js/main.js` (ou `heures.js` pour l'écran Heures) |
| un mot affiché réglable | `temonia_pilotage/lib/Service/Libelles.php` |
| un texte de notification | `temonia_pilotage/lib/Notification/Notifier.php` |

Et toujours : un cas de plus dans `tests/tester-droits.py` si ça touche aux droits.

## Conventions

- **Tout en français** : variables, commentaires, messages. Le code est commenté paragraphe par
  paragraphe pour qu'un développeur débutant puisse le suivre.
- **Pas d'outil de compilation** : PHP et JavaScript tels quels.
- Les droits se vérifient **côté serveur**, au début de chaque action d'un contrôleur.
- Les identifiants techniques `temonia_…`, `team:temonia` et la catégorie `pompier` (les
  partenaires) ne changent pas : des installations en production en dépendent. Ce qui s'affiche
  passe par `Libelles`.
