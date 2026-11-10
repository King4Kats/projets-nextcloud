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
 * Ajoute le drapeau `valide` aux tâches. Quand un MEMBRE (bénévole / salarié /
 * partenaire) crée une tâche, elle naît « à valider » (valide = false) : visible mais
 * grisée, en attente de la validation de la direction. Une tâche créée par la direction,
 * ou déjà existante avant cette migration, est validée d'office (default = true).
 */
class Version000006Date20260903120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        // On MODIFIE la table existante tp_taches (getTable), on n'en recrée pas.
        $t = $schema->getTable('tp_taches');
        if (!$t->hasColumn('valide')) {
            // default true : toutes les tâches déjà en base restent visibles/normales.
            $t->addColumn('valide', 'boolean', ['notnull' => false, 'default' => true]);
        }
        return $schema;
    }
}
