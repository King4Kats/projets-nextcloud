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
 * Commentaires & blocages sur les tâches : l'équipe (salariés, bénévoles,
 * partenaires) peut commenter une tâche ou signaler un blocage depuis la fiche.
 * type = 'comment' | 'blocage' ; resolved = blocage levé.
 */
class Version000004Date20260830120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('tp_commentaires')) {
            $t = $schema->createTable('tp_commentaires');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('tache_id', 'bigint', ['notnull' => true]);
            $t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('texte', 'text', ['notnull' => false]);
            $t->addColumn('type', 'string', ['notnull' => true, 'length' => 16, 'default' => 'comment']);
            $t->addColumn('resolved', 'boolean', ['notnull' => false, 'default' => false]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['tache_id'], 'tp_comm_tache_idx');
        }
        return $schema;
    }
}
