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
 * Crée la table `tp_affectations` : elle permet d'affecter UNE tâche à PLUSIEURS
 * personnes ou groupes, chacun avec son propre budget d'heures prévues. C'est ce
 * qui donne à la direction, dans l'Administration, la vue « qui a dépassé ses heures
 * sur telle tâche » (réel de la personne vs heures qui lui étaient prévues).
 *
 * L'ancien champ `assignee` (assigné unique) de tp_taches reste en place : il sert
 * de « responsable principal » ; les affectations viennent EN PLUS, pas à la place.
 */
class Version000007Date20260905120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // « if (!hasTable) » = idempotence : on ne crée la table que si elle n'existe pas déjà.
        if (!$schema->hasTable('tp_affectations')) {
            $t = $schema->createTable('tp_affectations');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('tache_id', 'integer', ['notnull' => true]);
            // principal = uid ("claire") OU "group:<gid>" ("group:Partenaires"). 64 = assez pour un uid/gid.
            $t->addColumn('principal', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('heures_prevues', 'float', ['notnull' => false]); // budget prévu pour ce principal
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            // Index sur tache_id : on interroge presque toujours « les affectations de telle tâche ».
            $t->addIndex(['tache_id'], 'tp_aff_tache_idx');
        }

        return $schema;
    }
}
