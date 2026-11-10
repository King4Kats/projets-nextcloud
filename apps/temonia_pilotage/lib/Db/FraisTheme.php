<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une THÉMATIQUE interne (table `tp_frais_themes`) : rattachement d'un frais
 * qui ne concerne aucun projet (Assemblée générale, Conseil d'administration,
 * Vie interne…). Liste gérée par l'admin.
 *
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method bool getActif()
 * @method void setActif(bool $actif)
 * @method int getOrdre()
 * @method void setOrdre(int $ordre)
 */
class FraisTheme extends Entity implements JsonSerializable {
    protected $label;
    protected $actif;
    protected $ordre;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('actif', 'boolean');
        $this->addType('ordre', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'actif' => (bool)$this->actif,
            'ordre' => $this->ordre,
        ];
    }
}
