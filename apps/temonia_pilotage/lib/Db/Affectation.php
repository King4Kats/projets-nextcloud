<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une AFFECTATION (table `tp_affectations`) : « telle personne OU tel groupe est
 * affecté à telle tâche, avec X heures prévues rien que pour lui ».
 *
 * C'est ce qui permet d'affecter UNE même tâche à PLUSIEURS personnes/groupes,
 * chacun avec son propre budget d'heures — contrairement au champ unique
 * `assignee` de la tâche (un seul assigné, une seule estimation globale).
 *
 * `principal` réutilise EXACTEMENT la convention de `assignee` (voir AccessService) :
 *   - "claire"          → une personne (uid Nextcloud) ;
 *   - "group:Partenaires" → un groupe (préfixe AccessService::GROUP_PREFIX).
 * Ainsi principalName() et isAssignedTo() savent déjà lire les deux cas, et le
 * « réel » d'une affectation se calcule pareil pour une personne ou un groupe :
 * on additionne les heures de toutes les personnes concernées par ce principal.
 *
 * @method int getTacheId()
 * @method void setTacheId(int $tacheId)
 * @method string getPrincipal()
 * @method void setPrincipal(string $principal)
 * @method float|null getHeuresPrevues()
 * @method void setHeuresPrevues(?float $heuresPrevues)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
// Même patron d'entité que Heure/Projet (voir Projet.php pour Entity / addType / jsonSerialize).
class Affectation extends Entity implements JsonSerializable {
    protected $tacheId;        // à quelle tâche appartient cette affectation
    protected $principal;      // personne (uid) OU groupe ("group:<gid>")
    protected $heuresPrevues;  // budget d'heures prévu POUR ce principal sur cette tâche
    protected $createdAt;

    public function __construct() {
        // addType force le typage à la lecture depuis la base (0/1 → int, "3.5" → float…).
        $this->addType('id', 'integer');
        $this->addType('tacheId', 'integer');
        $this->addType('heuresPrevues', 'float');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'tacheId' => $this->tacheId,
            'principal' => $this->principal,
            'heuresPrevues' => $this->heuresPrevues,
            'createdAt' => $this->createdAt,
        ];
    }
}
