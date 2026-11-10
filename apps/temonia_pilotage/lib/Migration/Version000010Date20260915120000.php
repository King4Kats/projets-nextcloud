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
 * Ajoute une colonne `liens` (JSON) aux tâches : la liste des LIENS attachés à la fiche
 * d'une tâche — adresse web, ou fichier / dossier du cloud Nextcloud. Chaque entrée :
 *   { type: 'url' | 'fichier' | 'dossier', label, url, fileId?, by, at }
 * Stockée en texte JSON (comme `membres` sur les projets) : pas de table à part pour
 * une petite liste (20 liens max) qui vit et meurt avec sa tâche.
 */
class Version000010Date20260915120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $t = $schema->getTable('tp_taches');
        if (!$t->hasColumn('liens')) {
            $t->addColumn('liens', 'text', ['notnull' => false]);
        }
        return $schema;
    }
}
