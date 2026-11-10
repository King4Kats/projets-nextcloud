<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schéma initial : projets → activités → tâches → saisies d'heures.
 * Heures « prévues » et « seuils » sont en NOMBRE D'HEURES (pas de coût €).
 * Le lien vers Deck se fait par deck_board_id / deck_card_id (jamais en écrivant
 * les tables de Deck : on passe par son API REST).
 */
class Version000001Date20260828120000 extends SimpleMigrationStep {

    // Une MIGRATION décrit une évolution de la base. Nextcloud exécute les migrations dans l'ordre
    // de leur numéro (Version000001, 000002…) une seule fois chacune, à l'installation/mise à jour.
    // changeSchema = la partie qui crée/modifie les TABLES et colonnes (pas les données).
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // $schema = une représentation du schéma actuel qu'on va modifier, puis renvoyer à la fin.
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- Projets ---------------------------------------------------------
        // « if (!hasTable) » = idempotence : on ne crée la table que si elle n'existe pas déjà,
        // pour pouvoir rejouer la migration sans erreur.
        if (!$schema->hasTable('tp_projets')) {
            $t = $schema->createTable('tp_projets');
            // addColumn(nom, type, options). autoincrement = l'id se remplit tout seul (1, 2, 3…).
            // notnull = obligatoire ; length = taille max ; default = valeur si non fournie.
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('nom', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('description', 'text', ['notnull' => false]); // 'text' = texte long, sans limite courte
            $t->addColumn('couleur', 'string', ['notnull' => false, 'length' => 9]); // ex. "#C4362A"
            $t->addColumn('statut', 'string', ['notnull' => true, 'length' => 16, 'default' => 'actif']);
            $t->addColumn('responsable', 'string', ['notnull' => false, 'length' => 64]); // uid Nextcloud
            $t->addColumn('deck_board_id', 'integer', ['notnull' => false]);
            $t->addColumn('date_debut', 'date', ['notnull' => false]);
            $t->addColumn('date_fin', 'date', ['notnull' => false]);
            $t->addColumn('ordre', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]); // horodatage (secondes), 'bigint' = grand entier
            // Clé primaire = colonne qui identifie chaque ligne de façon unique.
            $t->setPrimaryKey(['id']);
        }

        // --- Activités (rattachées à un projet) ------------------------------
        if (!$schema->hasTable('tp_activites')) {
            $t = $schema->createTable('tp_activites');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('projet_id', 'integer', ['notnull' => true]);
            $t->addColumn('nom', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('seuil_heures', 'float', ['notnull' => false]); // plafond informatif de l'activité
            $t->addColumn('ordre', 'integer', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            // addIndex = un INDEX : accélère les recherches sur cette colonne (ici « WHERE projet_id = ? »),
            // exactement comme un index de livre évite de lire toutes les pages. Le 2e arg = son nom unique.
            $t->addIndex(['projet_id'], 'tp_act_projet_idx');
        }

        // --- Tâches (rattachées à une activité) ------------------------------
        if (!$schema->hasTable('tp_taches')) {
            $t = $schema->createTable('tp_taches');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('projet_id', 'integer', ['notnull' => true]);        // dénormalisé pour les cumuls
            $t->addColumn('activite_id', 'integer', ['notnull' => false]);
            $t->addColumn('titre', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('description', 'text', ['notnull' => false]);
            $t->addColumn('heures_prevues', 'float', ['notnull' => false]);    // estimation / seuil de la tâche
            $t->addColumn('assignee', 'string', ['notnull' => false, 'length' => 64]); // uid Nextcloud
            $t->addColumn('statut', 'string', ['notnull' => true, 'length' => 16, 'default' => 'a_faire']); // a_faire|en_cours|fait
            $t->addColumn('deck_card_id', 'integer', ['notnull' => false]);
            $t->addColumn('echeance', 'date', ['notnull' => false]);
            $t->addColumn('ordre', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['projet_id'], 'tp_tache_projet_idx');
            $t->addIndex(['activite_id'], 'tp_tache_act_idx');
        }

        // --- Saisies d'heures (une ligne = X h par une personne sur une tâche)
        if (!$schema->hasTable('tp_heures')) {
            $t = $schema->createTable('tp_heures');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('tache_id', 'integer', ['notnull' => true]);
            $t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]); // uid Nextcloud
            $t->addColumn('jour', 'date', ['notnull' => true]);
            $t->addColumn('heures', 'float', ['notnull' => true, 'default' => 0]);
            $t->addColumn('commentaire', 'string', ['notnull' => false, 'length' => 500]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tache_id'], 'tp_h_tache_idx');
            $t->addIndex(['user_id'], 'tp_h_user_idx');
        }

        return $schema;
    }
}
