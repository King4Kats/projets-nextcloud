<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une saisie d'heures (table `tp_heures`) : « X heures passées par telle
 * personne (`userId`) sur telle tâche (`tacheId`), tel jour (`jour`) ».
 * Le réel d'une tâche = somme de ces lignes.
 *
 * @method int getTacheId()
 * @method void setTacheId(int $tacheId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getJour()
 * @method void setJour(string $jour)
 * @method float getHeures()
 * @method void setHeures(float $heures)
 * @method string|null getCommentaire()
 * @method void setCommentaire(?string $commentaire)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
// Même patron d'entité que Projet (voir Projet.php). Ici, l'addition de toutes les lignes
// d'une même tâche donne le « réel » de cette tâche (heures réellement passées).
class Heure extends Entity implements JsonSerializable {
    protected $tacheId;
    protected $userId;
    protected $jour;
    protected $heures;
    protected $commentaire;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('tacheId', 'integer');
        $this->addType('heures', 'float');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'tacheId' => $this->tacheId,
            'userId' => $this->userId,
            'jour' => $this->jour,
            'heures' => $this->heures,
            'commentaire' => $this->commentaire,
            'createdAt' => $this->createdAt,
        ];
    }
}
