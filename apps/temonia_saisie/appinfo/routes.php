<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// TABLE DE ROUTAGE de l'app : elle associe chaque URL à une méthode de contrôleur.
// Lecture d'une ligne :
//   'name' => 'controleur#methode'  → 'board#index' = BoardController::index()
//   'url'  => le chemin appelé       → {id} est un paramètre capturé et passé à la méthode
//   'verb' => la méthode HTTP        → GET (lire), POST (créer), PUT (modifier), DELETE (supprimer)
// Nextcloud lit ce fichier au chargement de l'app et branche tout automatiquement.
return [
    'routes' => [
        // Page principale (le HTML qui charge le JS de l'app).
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        // Écran « Heures » pour le téléphone (2e entrée du menu, icône horloge) : même API, autre page.
        ['name' => 'page#heures', 'url' => '/heures', 'verb' => 'GET'],

        // Données de référence (lecture seule) : projets/activités/tâches accessibles, users, groupes.
        ['name' => 'ref#projets', 'url' => '/api/ref/projets', 'verb' => 'GET'],
        ['name' => 'ref#activites', 'url' => '/api/ref/activites', 'verb' => 'GET'],
        ['name' => 'ref#taches', 'url' => '/api/ref/taches', 'verb' => 'GET'],
        ['name' => 'ref#users', 'url' => '/api/ref/users', 'verb' => 'GET'],
        ['name' => 'ref#groups', 'url' => '/api/ref/groups', 'verb' => 'GET'],

        // Board d'équipe : liste des tâches, fiche détail, changement de statut (le {id} = id de la tâche).
        ['name' => 'board#index', 'url' => '/api/board', 'verb' => 'GET'],
        // Vue Planneur (Gantt) : déclarée AVANT board/{id} pour ne pas être captée par {id}.
        ['name' => 'board#planneur', 'url' => '/api/planneur', 'verb' => 'GET'],
        ['name' => 'board#detail', 'url' => '/api/board/{id}', 'verb' => 'GET'],
        ['name' => 'board#setStatut', 'url' => '/api/board/{id}/statut', 'verb' => 'PUT'],
        // Liens attachés à une fiche (adresse web, fichier ou dossier du cloud) : ajouter / retirer.
        ['name' => 'board#lienAdd', 'url' => '/api/board/{id}/liens', 'verb' => 'POST'],
        ['name' => 'board#lienDelete', 'url' => '/api/board/{id}/liens/{idx}', 'verb' => 'DELETE'],

        // Commentaires & blocages sur une tâche (créer, lister, lever un blocage).
        ['name' => 'comment#index', 'url' => '/api/commentaires', 'verb' => 'GET'],
        ['name' => 'comment#create', 'url' => '/api/commentaires', 'verb' => 'POST'],
        ['name' => 'comment#resolve', 'url' => '/api/commentaires/{id}/resolve', 'verb' => 'PUT'],

        // Saisie des heures de l'utilisateur (lister/créer/modifier/supprimer SES saisies).
        ['name' => 'heure#index', 'url' => '/api/heures', 'verb' => 'GET'],
        ['name' => 'heure#create', 'url' => '/api/heures', 'verb' => 'POST'],
        ['name' => 'heure#update', 'url' => '/api/heures/{id}', 'verb' => 'PUT'],
        ['name' => 'heure#destroy', 'url' => '/api/heures/{id}', 'verb' => 'DELETE'],

        // Administration (direction) : CRUD projets / phases / tâches + méta + avancement + historique.
        // (CRUD = Create/Read/Update/Delete = créer/lire/modifier/supprimer.)
        ['name' => 'manage#projets', 'url' => '/api/manage/projets', 'verb' => 'GET'],
        ['name' => 'manage#projetCreate', 'url' => '/api/manage/projets', 'verb' => 'POST'],
        ['name' => 'manage#projetUpdate', 'url' => '/api/manage/projets/{id}', 'verb' => 'PUT'],
        ['name' => 'manage#projetDestroy', 'url' => '/api/manage/projets/{id}', 'verb' => 'DELETE'],
        ['name' => 'manage#activiteCreate', 'url' => '/api/manage/activites', 'verb' => 'POST'],
        ['name' => 'manage#activiteUpdate', 'url' => '/api/manage/activites/{id}', 'verb' => 'PUT'],
        ['name' => 'manage#activiteDestroy', 'url' => '/api/manage/activites/{id}', 'verb' => 'DELETE'],
        ['name' => 'manage#tacheCreate', 'url' => '/api/manage/taches', 'verb' => 'POST'],
        ['name' => 'manage#tacheUpdate', 'url' => '/api/manage/taches/{id}', 'verb' => 'PUT'],
        ['name' => 'manage#tacheDestroy', 'url' => '/api/manage/taches/{id}', 'verb' => 'DELETE'],
        // Validation des propositions de tâches par la direction (sous-onglet Administration › À valider).
        ['name' => 'manage#pending', 'url' => '/api/manage/pending', 'verb' => 'GET'],
        ['name' => 'manage#tacheValider', 'url' => '/api/manage/taches/{id}/valider', 'verb' => 'POST'],
        ['name' => 'manage#tacheRejeter', 'url' => '/api/manage/taches/{id}/rejeter', 'verb' => 'POST'],
        // Affectations d'une tâche (plusieurs personnes/groupes, heures prévues distinctes) :
        // lister celles d'une tâche, ajouter/mettre à jour un affecté, en retirer un.
        ['name' => 'manage#affectations', 'url' => '/api/manage/taches/{id}/affectations', 'verb' => 'GET'],
        ['name' => 'manage#affectationSave', 'url' => '/api/manage/taches/{id}/affectations', 'verb' => 'POST'],
        ['name' => 'manage#affectationDelete', 'url' => '/api/manage/affectations/{id}', 'verb' => 'DELETE'],
        ['name' => 'manage#principals', 'url' => '/api/manage/principals', 'verb' => 'GET'],
        ['name' => 'manage#avProjet', 'url' => '/api/manage/avancement/projet', 'verb' => 'GET'],
        ['name' => 'manage#histoProjet', 'url' => '/api/manage/historique/projet', 'verb' => 'GET'],

        // Tableau de bord à widgets : données agrégées + disposition par utilisateur.
        ['name' => 'dashboard#stats', 'url' => '/api/dashboard/stats', 'verb' => 'GET'],
        ['name' => 'dashboard#getLayout', 'url' => '/api/dashboard/layout', 'verb' => 'GET'],
        ['name' => 'dashboard#saveLayout', 'url' => '/api/dashboard/layout', 'verb' => 'PUT'],

        // Tableau de bord d'ADMINISTRATION (direction / admin NC uniquement) : détail des heures.
        ['name' => 'admin#stats', 'url' => '/api/admin/stats', 'verb' => 'GET'],
        // Saisie déléguée : lister les heures d'une personne (pour le sous-onglet « Pointer des heures »).
        ['name' => 'admin#heures', 'url' => '/api/admin/heures', 'verb' => 'GET'],
        // Export des saisies : GET = télécharger le CSV (Excel) ; POST = enregistrer dans les Fichiers NC.
        ['name' => 'admin#exportDownload', 'url' => '/api/admin/export', 'verb' => 'GET'],
        ['name' => 'admin#exportNextcloud', 'url' => '/api/admin/export', 'verb' => 'POST'],
    ],
];
