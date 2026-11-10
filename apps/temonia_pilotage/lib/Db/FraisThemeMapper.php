<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès base de données aux thématiques internes de frais (table `tp_frais_themes`).
 * @extends QBMapper<FraisTheme>
 */
class FraisThemeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_frais_themes', FraisTheme::class);
    }

    /** @return FraisTheme[] */
    public function findAll(bool $onlyActive = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        if ($onlyActive) {
            $qb->where($qb->expr()->eq('actif', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        $qb->orderBy('ordre', 'ASC')->addOrderBy('label', 'ASC');
        return $this->findEntities($qb);
    }

    public function find(int $id): FraisTheme {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }
}
