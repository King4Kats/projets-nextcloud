# 2. Architecture

## Deux applis, un seul outil

L'appli est en **deux parties**, installées ensemble :

```
  ┌──────────────────────────────────────┐        ┌──────────────────────────────────────┐
  │  temonia_saisie  « Projets »         │        │  temonia_pilotage  « Projets (socle) »│
  │  ce qu'on VOIT                       │ ─────▶ │  ce qu'on GARDE                       │
  │  écrans (js/), adresses de l'API,    │ utilise│  tables, entités, règles d'accès,     │
  │  contrôleurs, widget d'accueil       │        │  historique, notifications, activité  │
  └──────────────────────────────────────┘        └──────────────────────────────────────┘
        menu « Projets » et « Heures »                  aucune page, aucune adresse
```

Pourquoi ? Le socle porte les données et les règles ; d'autres applis peuvent s'appuyer dessus
sans dupliquer quoi que ce soit (chez Témonia, les notes de frais y rangent aussi leurs tables).
C'est pour cela que le socle contient des tables `tp_frais*` : elles ne servent pas à Projets,
elles restent vides si l'on n'installe pas d'appli de frais.

## Le chemin d'une requête

Exemple : Inès pointe 2 heures sur « Tailler la haie ».

```
 Navigateur (temonia_saisie/js/heures.js)          Serveur Nextcloud (PHP)
 ─────────────────────────────────────────         ────────────────────────────────────────────
 POST /apps/temonia_saisie/api/heures ──────────▶ temonia_saisie/appinfo/routes.php
   {tache: 12, jour: "2026-11-10", heures: 2}       « /api/heures » POST → heure#create
   + jeton CSRF de Nextcloud                               │
                                                           ▼
                                                  Controller/HeureController::create()
                                                    1. contrôle des données (400 sinon)
                                                    2. la tâche existe ? (404 sinon)
                                                    3. AccessService (socle) : Inès voit-elle
                                                       ce projet ? (403 sinon)
                                                    4. HeureMapper (socle) : écrit la ligne
                                                           │
                                                           ▼
                                                  Base de données : oc_tp_heures
        ◀──────────────── JSON (la ligne créée) ──
 l'écran se met à jour
```

**Règle d'or** : le JavaScript n'est qu'une vitrine. Chaque droit est revérifié côté serveur,
à chaque requête.

## Qui fait quoi dans le code

```
apps/temonia_pilotage/                  LE SOCLE
├── appinfo/info.xml                    identité, version, flux Activité
├── lib/Db/                             une classe par table (Entity) + son Mapper (requêtes)
├── lib/Migration/                      création et évolution des tables (10 étapes)
├── lib/Service/
│   ├── AccessService.php               ★ qui voit quel projet, qui est direction
│   ├── Libelles.php                    les mots affichés, réglables
│   ├── HistoService.php                l'historique de chaque projet
│   ├── NotifyService.php               les notifications (cloche Nextcloud)
│   ├── ActivityService.php             le flux « Activité » de Nextcloud
│   └── AffectationService.php          répartition d'une tâche entre plusieurs personnes
└── lib/Activity/, lib/Notification/    textes du flux Activité et des notifications

apps/temonia_saisie/                    L'INTERFACE
├── appinfo/routes.php                  toutes les adresses de l'API → méthode d'un contrôleur
├── lib/Controller/
│   ├── PageController.php              les deux pages (Projets, Heures)
│   ├── RefController.php               listes de référence (projets, tâches, comptes…)
│   ├── BoardController.php             tableau des tâches, planneur, statut, liens
│   ├── ManageController.php            créer / modifier projets, activités, tâches, membres
│   ├── HeureController.php             pointer, modifier, supprimer des heures
│   ├── CommentController.php           commentaires et blocages
│   ├── DashboardController.php         chiffres et disposition du tableau de bord
│   └── AdminController.php             administration (direction) et export Excel
├── lib/Dashboard/ProjetsWidget.php     le widget de l'accueil Nextcloud
├── js/main.js, css/main.css            l'écran Projets
├── js/heures.js, css/heures.css        l'écran Heures (téléphone)
└── vendor/                             PhpSpreadsheet, pour l'export Excel (déjà inclus)
```

## Les données

Dix tables, préfixées `oc_tp_` :

```
  tp_projets ──┬──▶ tp_activites ──▶ tp_taches ──┬──▶ tp_heures          heures pointées
  nom, couleur │    nom, seuil       titre,       ├──▶ tp_affectations    qui fait combien
  statut,      │    d'heures         statut,      ├──▶ tp_commentaires    commentaires, blocages
  membres      │                     assigné,     │
  (JSON)       │                     prévu,       │
               │                     échéance     │
               └──────────────────────────────────┴──▶ tp_historique     qui a fait quoi, quand

  tp_frais, tp_frais_types, tp_frais_themes : utilisées par une appli de notes de frais, à part.
```

- **Membres d'un projet** (`membres`) : une liste JSON de `{type: "user"|"group", id}`.
- **Assigné d'une tâche** (`assignee`) : un compte (`thomas`), un groupe (`group:benevoles`) ou
  toute l'équipe salariés + bénévoles (`team:temonia`, identifiant historique).
- Les comptes ne sont jamais copiés : on garde l'identifiant Nextcloud, le nom est demandé à
  Nextcloud à l'affichage.
