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
 * Ajoute une DATE DE DÉBUT (`date_debut`) aux tâches. Combinée à l'échéance
 * (`echeance`, date de fin déjà existante), elle donne à chaque tâche/sous-tâche/
 * « grande activité » (tâche parente) un intervalle [début → fin] — la base de la
 * future « Vue Planneur » (diagramme de Gantt). Nullable : une tâche peut n'avoir
 * aucune date (elle ira alors dans la section « non planifiées » du planneur).
 */
class Version000008Date20260906120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        // On MODIFIE la table existante tp_taches (on n'en recrée pas).
        $t = $schema->getTable('tp_taches');
        if (!$t->hasColumn('date_debut')) {
            $t->addColumn('date_debut', 'date', ['notnull' => false]);
        }
        return $schema;
    }
}
