# 1. Démarrer

## Essayer la démo

Il faut **Docker** avec le plugin **Compose** (`docker compose version` doit répondre).

```bash
git clone https://github.com/King4Kats/projets-nextcloud.git
cd projets-nextcloud
docker compose up --build
```

Le premier lancement prend 1 à 3 minutes : installation de Nextcloud, des deux applis, des comptes,
puis le petit service `demo` crée deux projets d'exemple et s'arrête (`✓ Projets d'exemple créés`).
Ouvrir http://localhost:8160/apps/temonia_saisie/.

Mot de passe de tous les comptes : `projets2026`.

| Compte | Groupe | Ce qu'il voit |
|---|---|---|
| `claire` | direction | tous les projets ; crée, modifie, assigne |
| `thomas` | salariés | les deux projets (membre) |
| `ines` | bénévoles | les deux projets (par son groupe) |
| `paul` | partenaires | seulement « Fête du jardin 2027 » |
| `marie` | partenaires | rien : aucun projet ne la compte comme membre |
| `admin` | administrateur Nextcloud | tout, plus l'écran Administration |

L'écran **Heures** (http://localhost:8160/apps/temonia_saisie/heures) est fait pour le
téléphone : essayez-le avec `ines` dans une fenêtre étroite.

Repartir de zéro : `docker compose down -v` puis `docker compose up`.

## Installer sur un vrai Nextcloud

Nextcloud 29 à 34, PHP 8.1 ou plus.

1. Copier `apps/temonia_pilotage` et `apps/temonia_saisie` dans le dossier `custom_apps/`.
2. Activer **le socle d'abord** :
   ```bash
   occ app:enable temonia_pilotage
   occ app:enable temonia_saisie
   ```
3. Créer les groupes et dire à l'appli lesquels utiliser (voir ci-dessous).

## Réglages

Tout se règle par `occ`, sans toucher au code.

**Les groupes.** L'appli distingue quatre profils. Les noms de groupes sont libres :

```bash
occ config:app:set temonia_pilotage group_direction       --value=direction    # voit et gère tout
occ config:app:set temonia_pilotage group_equipe_salarie  --value=salaries
occ config:app:set temonia_pilotage group_equipe_benevole --value=benevoles
occ config:app:set temonia_pilotage group_equipe_pompier  --value=partenaires  # intervenants extérieurs
```

Les administrateurs Nextcloud sont toujours direction. Salariés et bénévoles forment ensemble
« l'Équipe », à qui l'on peut confier une tâche d'un coup.

**Les mots affichés.** Par défaut, le nom de la structure est celui du thème Nextcloud :

```bash
occ config:app:set temonia_pilotage libelles --value='{"organisation":"Mon asso","pompiers":"Intervenants"}'
```

Clés possibles : `organisation`, `equipe`, `salarie(s)`, `benevole(s)`, `pompier(s)` (les
partenaires), `autre`, `dossier_exports`.

## Utiliser l'appli au quotidien

```
  Tableau de bord ───▶ chiffres clés, avancement par projet, mes tâches, blocages, activité
  Projets
     └── un projet ──▶ membres & accès, puis l'arbre : phase › activité › tâche
                        (statut, référent, réel / prévu, avancement)
  Vues
     ├── Mes tâches   ce qui m'est confié, dans tous les projets
     ├── Blocages     ce qui est signalé comme bloqué
     └── Planneur     les tâches sur un calendrier (semaine → 3 ans)
  Heures ────────────▶ pointer ses heures (téléphone) et retrouver les siennes
  Administration ────▶ (direction) heures par personne / équipe / projet, export Excel
```

Partout, un bouton « ? » explique l'élément à côté duquel il se trouve.
