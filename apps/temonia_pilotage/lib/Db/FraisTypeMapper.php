<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès base de données aux types de frais (table `tp_frais_types`).
 * @extends QBMapper<FraisType>
 */
class FraisTypeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_frais_types', FraisType::class);
    }

    /**
     * Tous les types, triés par ordre puis label. onlyActive = true → seulement ceux
     * proposables pour un nouveau frais.
     * @return FraisType[]
     */
    public function findAll(bool $onlyActive = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        if ($onlyActive) {
            $qb->where($qb->expr()->eq('actif', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        $qb->orderBy('ordre', 'ASC')->addOrderBy('label', 'ASC');
        return $this->findEntities($qb);
    }

    public function find(int $id): FraisType {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** Type par son code stable (ex. "km"). Lève DoesNotExistException s'il n'existe pas. */
    public function findByCode(string $code): FraisType {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        return $this->findEntity($qb);
    }
}
