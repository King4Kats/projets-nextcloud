<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Un TYPE de frais (table `tp_frais_types`) : « Frais kilométriques »,
 * « Hébergement / Restauration »… Liste gérée par l'admin. Le `code` est
 * l'identifiant stable stocké sur chaque frais ; `estKm` marque le type
 * kilométrique (montant calculé = km × barème).
 *
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method bool getEstKm()
 * @method void setEstKm(bool $estKm)
 * @method bool getActif()
 * @method void setActif(bool $actif)
 * @method int getOrdre()
 * @method void setOrdre(int $ordre)
 */
class FraisType extends Entity implements JsonSerializable {
    protected $code;
    protected $label;
    protected $estKm;
    protected $actif;
    protected $ordre;

    public function __construct() {
        $this->addType('id', 'integer');
        // 'boolean' : la BD renvoie 0/1 (ou 't'/'f' en PostgreSQL) → Nextcloud convertit en vrai/faux.
        $this->addType('estKm', 'boolean');
        $this->addType('actif', 'boolean');
        $this->addType('ordre', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'estKm' => (bool)$this->estKm,
            'actif' => (bool)$this->actif,
            'ordre' => $this->ordre,
        ];
    }
}
