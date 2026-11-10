<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès en lecture au journal d'audit. Résultats triés du plus récent au plus
 * ancien, limités (limit) pour éviter de tout charger. On lit par élément
 * (findByElement, pour la fiche d'une tâche) ou par projet (findByProjet).
 * @extends QBMapper<Historique>
 */
class HistoriqueMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_historique', Historique::class);
    }

    // Même patron de mapper que ProjetMapper (voir ProjetMapper.php pour les explications de base).

    /** @return Historique[] */
    public function findByElement(string $type, int $elementId, int $limit = 100): array {
        // Événements d'UN élément précis (ex. une tâche) : type + id, du plus récent au plus ancien.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('element_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('element_id', $qb->createNamedParameter($elementId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
            // setMaxResults = « LIMIT » SQL : on ne rapatrie qu'au plus $limit lignes (défaut 100),
            // pour ne pas charger un historique énorme d'un coup.
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /** @return Historique[] */
    public function findByProjet(int $projetId, int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('projet_id', $qb->createNamedParameter($projetId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Derniers événements sur PLUSIEURS projets (activité récente du tableau de bord).
     * @param int[] $projetIds
     * @return Historique[]
     */
    public function findByProjets(array $projetIds, int $limit = 30): array {
        if (empty($projetIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('projet_id', $qb->createNamedParameter($projetIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
