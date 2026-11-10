<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès base de données aux commentaires et blocages.
 * @extends QBMapper<Commentaire>
 */
class CommentaireMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_commentaires', Commentaire::class);
    }

    public function find(int $id): Commentaire {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return Commentaire[] */
    public function findByTache(int $tacheId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('tache_id', $qb->createNamedParameter($tacheId, \PDO::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Ids des tâches ayant au moins un blocage non résolu (pour badge).
     * @param int[] $tacheIds
     * @return int[]
     */
    public function tachesBloquees(array $tacheIds): array {
        if (empty($tacheIds)) {
            return []; // pas d'ids → rien à chercher (et « IN () » serait invalide)
        }
        $qb = $this->db->getQueryBuilder();
        // selectDistinct('tache_id') : on ne veut QUE les identifiants de tâches (pas les commentaires
        // entiers), et sans doublon (une tâche avec 3 blocages ne doit apparaître qu'une fois).
        // Conditions cumulées : type = 'blocage' ET non résolu ET dans la liste de tâches fournie.
        $qb->selectDistinct('tache_id')->from($this->getTableName())
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('blocage')))
            ->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->in('tache_id', $qb->createNamedParameter($tacheIds, IQueryBuilder::PARAM_INT_ARRAY)));
        // Ici on ne peut pas utiliser findEntities (qui fabrique des objets Commentaire) : on ne lit
        // qu'UNE colonne. On exécute donc la requête « à la main » et on lit les lignes une par une.
        $out = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {         // fetch() renvoie la ligne suivante, ou false à la fin
            $out[] = (int)$row['tache_id'];    // on empile l'id (converti en entier)
        }
        $res->closeCursor();                    // on libère le curseur (bonne pratique après une lecture manuelle)
        return $out;
    }
}
