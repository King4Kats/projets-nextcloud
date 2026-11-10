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
 * Membres d'un projet : qui (personnes et/ou groupes Nextcloud) a accès au
 * projet côté équipe. JSON = liste de {type:'user'|'group', id:'...'}.
 * La direction (groupe direction / admin) voit tout, indépendamment.
 */
class Version000003Date20260828180000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $t = $schema->getTable('tp_projets');
        if (!$t->hasColumn('membres')) {
            $t->addColumn('membres', 'json', ['notnull' => false]);
        }
        return $schema;
    }
}
