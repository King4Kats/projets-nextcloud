<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Accès base de données aux phases/activités d'un projet.
 * @extends QBMapper<Activite>
 */
class ActiviteMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_activites', Activite::class);
    }

    // Même patron de mapper que ProjetMapper (voir ProjetMapper.php pour les explications
    // détaillées : query builder, createNamedParameter anti-injection, findEntity vs findEntities).

    /** @return Activite[] */
    public function findByProjet(int $projetId): array {
        // Toutes les phases d'un projet donné : « WHERE projet_id = ? », triées par ordre puis nom.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('projet_id', $qb->createNamedParameter($projetId, \PDO::PARAM_INT)))
            ->orderBy('ordre', 'ASC')->addOrderBy('nom', 'ASC');
        return $this->findEntities($qb);
    }

    public function find(int $id): Activite {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }
}
