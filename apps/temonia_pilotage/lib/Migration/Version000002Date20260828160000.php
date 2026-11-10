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
 * Sous-tâches : une tâche peut avoir un parent (tâche mère). parent_id null =
 * tâche de 1er niveau ; non null = sous-tâche (avec ses propres heures prévues).
 */
class Version000002Date20260828160000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        // Ici on MODIFIE une table existante : getTable (et non createTable) récupère tp_taches,
        // puis « if (!hasColumn) » n'ajoute la colonne que si elle manque (rejouable sans erreur).
        $t = $schema->getTable('tp_taches');
        if (!$t->hasColumn('parent_id')) {
            $t->addColumn('parent_id', 'integer', ['notnull' => false]); // null = tâche de 1er niveau
            $t->addIndex(['parent_id'], 'tp_tache_parent_idx');          // index : retrouver vite les enfants d'une tâche
        }
        return $schema;
    }
}
