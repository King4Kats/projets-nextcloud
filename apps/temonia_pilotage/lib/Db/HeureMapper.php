<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Accès base de données aux saisies d'heures.
 * findByTaches (pluriel) charge en une seule requête les heures de plusieurs
 * tâches — pratique pour cumuler le réel d'un projet sans boucle de requêtes.
 * @extends QBMapper<Heure>
 */
class HeureMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_heures', Heure::class);
    }

    // Même patron de mapper que ProjetMapper (voir ProjetMapper.php pour les explications de base).

    /** @return Heure[] */
    public function findByUser(string $uid, ?string $jour = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        // Filtre facultatif : si un jour est fourni, on AJOUTE une condition (andWhere = « ET »).
        if ($jour !== null && $jour !== '') {
            $qb->andWhere($qb->expr()->eq('jour', $qb->createNamedParameter($jour)));
        }
        // Plus récent d'abord (jour décroissant, puis date de création décroissante).
        $qb->orderBy('jour', 'DESC')->addOrderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /** @return Heure[] */
    public function findByTache(int $tacheId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('tache_id', $qb->createNamedParameter($tacheId, \PDO::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * @param int[] $tacheIds
     * @return Heure[]
     */
    public function findByTaches(array $tacheIds): array {
        // Garde-fou : sans ids, la clause « IN () » serait invalide → on renvoie vide tout de suite.
        if (empty($tacheIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        // « WHERE tache_id IN (...) » : récupère en UNE requête les heures de plusieurs tâches à la fois
        // (bien plus efficace qu'une requête par tâche). PARAM_INT_ARRAY = le paramètre est une LISTE
        // d'entiers, que le query builder développe en toute sécurité (toujours pas de valeur collée à la main).
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('tache_id', $qb->createNamedParameter($tacheIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    public function find(int $id): Heure {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }
}
