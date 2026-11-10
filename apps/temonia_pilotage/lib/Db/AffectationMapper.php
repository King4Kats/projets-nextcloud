<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Accès base de données aux affectations (table `tp_affectations`).
 * findByTaches (pluriel) charge en une seule requête les affectations de plusieurs
 * tâches — utile pour le board et le dashboard admin (éviter une requête par tâche).
 * @extends QBMapper<Affectation>
 */
class AffectationMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_affectations', Affectation::class);
    }

    // Même patron de mapper que HeureMapper (voir HeureMapper.php pour les explications de base).

    public function find(int $id): Affectation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return Affectation[] toutes les affectations d'UNE tâche */
    public function findByTache(int $tacheId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('tache_id', $qb->createNamedParameter($tacheId, \PDO::PARAM_INT)))
            ->orderBy('created_at', 'ASC'); // ordre d'ajout (plus ancien en premier)
        return $this->findEntities($qb);
    }

    /**
     * @param int[] $tacheIds
     * @return Affectation[] les affectations de PLUSIEURS tâches, en une requête
     */
    public function findByTaches(array $tacheIds): array {
        // Garde-fou : sans ids, la clause « IN () » serait invalide → on renvoie vide tout de suite.
        if (empty($tacheIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        // « WHERE tache_id IN (...) » — PARAM_INT_ARRAY développe la liste d'entiers en toute sécurité.
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('tache_id', $qb->createNamedParameter($tacheIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    /** Cherche l'affectation d'un principal (personne/groupe) sur une tâche, ou null si absente. */
    public function findByTacheEtPrincipal(int $tacheId, string $principal): ?Affectation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('tache_id', $qb->createNamedParameter($tacheId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('principal', $qb->createNamedParameter($principal)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null; // aucune affectation pour ce couple (tâche, principal)
        }
    }

    /** Supprime TOUTES les affectations d'une tâche (utilisé quand on supprime la tâche). */
    public function deleteByTache(int $tacheId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('tache_id', $qb->createNamedParameter($tacheId, \PDO::PARAM_INT)));
        $qb->executeStatement();
    }
}
