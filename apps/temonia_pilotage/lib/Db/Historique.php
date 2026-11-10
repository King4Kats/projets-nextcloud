<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une ligne du journal d'audit (table `tp_historique`) : « qui (`userId`) a
 * fait quoi (`action`) sur quel élément (`elementType`/`elementId`), quand
 * (`createdAt`) ». Pour un changement de valeur, `avant`/`apres` gardent
 * l'ancienne et la nouvelle (ex. statut « À faire » → « En cours »).
 *
 * @method int getProjetId()
 * @method void setProjetId(int $projetId)
 * @method string getElementType()
 * @method void setElementType(string $elementType)
 * @method int getElementId()
 * @method void setElementId(int $elementId)
 * @method string|null getElementTitre()
 * @method void setElementTitre(?string $elementTitre)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getAvant()
 * @method void setAvant(?string $avant)
 * @method string|null getApres()
 * @method void setApres(?string $apres)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
// Même patron d'entité que Projet (voir Projet.php). C'est le « journal d'audit » : on n'écrit
// jamais dessus après coup, on ne fait qu'AJOUTER des lignes (trace de qui a fait quoi, quand).
class Historique extends Entity implements JsonSerializable {
    protected $projetId;
    protected $elementType;
    protected $elementId;
    protected $elementTitre;
    protected $userId;
    protected $action;
    protected $avant;
    protected $apres;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('projetId', 'integer');
        $this->addType('elementId', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'projetId' => $this->projetId,
            'elementType' => $this->elementType,
            'elementId' => $this->elementId,
            'elementTitre' => $this->elementTitre,
            'userId' => $this->userId,
            'action' => $this->action,
            'avant' => $this->avant,
            'apres' => $this->apres,
            'createdAt' => $this->createdAt,
        ];
    }
}
