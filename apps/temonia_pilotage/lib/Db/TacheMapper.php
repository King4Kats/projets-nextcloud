<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Accès base de données aux tâches. findByProjet renvoie TOUTES les tâches
 * du projet (tous niveaux confondus) ; l'arbre parent/enfant est reconstruit
 * côté appelant via parentId.
 * @extends QBMapper<Tache>
 */
class TacheMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_taches', Tache::class);
    }

    // Même patron de mapper que ProjetMapper (voir ProjetMapper.php pour le détail du query builder).
    // findByProjet renvoie TOUTES les tâches du projet, tous niveaux confondus (racines + sous-tâches) ;
    // c'est l'appelant qui reconstruit l'arbre en regardant le parentId de chacune.

    /** @return Tache[] */
    public function findByProjet(int $projetId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('projet_id', $qb->createNamedParameter($projetId, \PDO::PARAM_INT)))
            ->orderBy('ordre', 'ASC')->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    public function find(int $id): Tache {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }
}
