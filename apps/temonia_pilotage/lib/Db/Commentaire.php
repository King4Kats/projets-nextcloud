<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Un commentaire OU un blocage sur une tâche (table `tp_commentaires`).
 * `type` = 'comment' | 'blocage' ; `resolved` = true quand un blocage a été levé.
 *
 * @method int getTacheId()
 * @method void setTacheId(int $tacheId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getTexte()
 * @method void setTexte(?string $texte)
 * @method string getType()
 * @method void setType(string $type)
 * @method bool getResolved()
 * @method void setResolved(bool $resolved)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Commentaire extends Entity implements JsonSerializable {
    protected $tacheId;
    protected $userId;
    protected $texte;
    protected $type;
    protected $resolved;
    protected $createdAt;

    // Même patron d'entité que Projet (voir Projet.php pour addType / jsonSerialize).
    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('tacheId', 'integer');
        // « boolean » : la base stocke souvent 0/1 ; ce type le reconvertit en vrai true/false PHP.
        $this->addType('resolved', 'boolean');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'tacheId' => $this->tacheId,
            'userId' => $this->userId,
            'texte' => $this->texte,
            'type' => $this->type,
            'resolved' => (bool)$this->resolved,
            'createdAt' => $this->createdAt,
        ];
    }
}
