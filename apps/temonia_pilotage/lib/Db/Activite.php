<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une « phase » / activité d'un projet (table `tp_activites`). Regroupe des tâches.
 * `seuilHeures` est un plafond d'heures purement INFORMATIF (aucune règle bloquante).
 *
 * @method int getProjetId()
 * @method void setProjetId(int $projetId)
 * @method string getNom()
 * @method void setNom(string $nom)
 * @method float|null getSeuilHeures()
 * @method void setSeuilHeures(?float $seuilHeures)
 * @method int getOrdre()
 * @method void setOrdre(int $ordre)
 */
// Même patron d'entité que Projet (voir Projet.php pour Entity / addType / jsonSerialize).
class Activite extends Entity implements JsonSerializable {
    protected $projetId;
    protected $nom;
    protected $seuilHeures;
    protected $ordre;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('projetId', 'integer');
        $this->addType('seuilHeures', 'float');
        $this->addType('ordre', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'projetId' => $this->projetId,
            'nom' => $this->nom,
            'seuilHeures' => $this->seuilHeures,
            'ordre' => $this->ordre,
        ];
    }
}
