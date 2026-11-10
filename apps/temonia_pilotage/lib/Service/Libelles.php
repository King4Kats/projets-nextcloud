<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCP\Defaults;
use OCP\IAppConfig;

/**
 * LES MOTS PROPRES À UNE STRUCTURE, au même endroit pour toutes les applis
 * (Projets/Heures, Frais, Droits les lisent ici).
 *
 * Le code est le même pour toutes les associations : seuls ces libellés changent.
 * Ils se règlent par un seul réglage JSON (les clés absentes gardent leur valeur par défaut) :
 *
 *   occ config:app:set temonia_pilotage libelles --value='{"partenaire":"Partenaire","partenaires":"Partenaires"}'
 *
 * Clés : organisation, equipe, salarie/salaries, benevole/benevoles, partenaire/partenaires, autre, dossier_exports.
 * « partenaire » = le 3e groupe d'heures (partenaires extérieurs), réglé par group_equipe_partenaire.
 * Par défaut le nom de l'organisation est celui du thème Nextcloud (Personnaliser l'apparence).
 */
class Libelles {
    public function __construct(private IAppConfig $appConfig, private Defaults $defaults) {
    }

    /** Tous les libellés, valeurs réglées par-dessus les valeurs par défaut. */
    public function tous(): array {
        $regles = json_decode($this->appConfig->getValueString(Application::APP_ID, 'libelles', '{}'), true);
        $regles = is_array($regles) ? array_filter($regles, fn ($v) => is_string($v) && trim($v) !== '') : [];

        $organisation = $regles['organisation'] ?? (trim($this->defaults->getName()) ?: 'Association');
        $base = [
            'organisation' => $organisation,
            'equipe' => 'Équipe ' . $organisation,          // salariés + bénévoles réunis
            'salarie' => 'Salarié', 'salaries' => 'Salariés',
            'benevole' => 'Bénévole', 'benevoles' => 'Bénévoles',
            'pompier' => 'Partenaire', 'pompiers' => 'Partenaires',
            'autre' => 'Autre',
            'dossier_exports' => $organisation . ' - Exports', // dossier créé dans les Fichiers
        ];
        return array_merge($base, array_intersect_key($regles, $base));
    }

    public function get(string $cle): string {
        return $this->tous()[$cle] ?? $cle;
    }
}
