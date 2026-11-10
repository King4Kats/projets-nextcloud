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
 * Historique / audit : journal de tous les changements (statut, création,
 * édition, assignation, suppression, blocage) par élément (phase / activité /
 * tâche / sous-tâche / projet). Qui a fait quoi, quand.
 */
class Version000005Date20260830140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('tp_historique')) {
            $t = $schema->createTable('tp_historique');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('projet_id', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('element_type', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('element_id', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('element_titre', 'string', ['notnull' => false, 'length' => 255]);
            $t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('avant', 'text', ['notnull' => false]);
            $t->addColumn('apres', 'text', ['notnull' => false]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            // Index COMPOSITE (sur 2 colonnes) : optimise la recherche « WHERE element_type = ? AND
            // element_id = ? » (l'historique d'une tâche précise). L'ordre des colonnes compte.
            $t->addIndex(['element_type', 'element_id'], 'tp_hist_elem_idx');
            $t->addIndex(['projet_id'], 'tp_hist_projet_idx'); // et pour lister l'historique d'un projet entier
        }
        return $schema;
    }
}
