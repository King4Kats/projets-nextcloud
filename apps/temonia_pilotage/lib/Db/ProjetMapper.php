<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper; // « Query Builder Mapper » : classe mère qui parle à la base
use OCP\IDBConnection;

/**
 * Accès base de données aux projets. QBMapper fournit insert/update/delete ;
 * findEntity(ies) exécute la requête et transforme chaque ligne en objet Projet.
 * @extends QBMapper<Projet>
 */
class ProjetMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        // On dit à la classe mère : « ce mapper travaille sur la table tp_projets, et chaque
        // ligne devient un objet Projet ». Grâce à ça, insert()/update()/delete() marchent déjà.
        parent::__construct($db, 'tp_projets', Projet::class);
    }

    /** @return Projet[] */
    public function findAll(): array {
        // getQueryBuilder() = un « constructeur de requête » : on décrit la requête par étapes
        // (select, from, where, orderBy…) au lieu d'écrire du SQL brut. Plus sûr et portable
        // (fonctionne sur MySQL, PostgreSQL, SQLite pareil).
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            // Tri : d'abord par « ordre » croissant, puis par nom en cas d'égalité.
            ->orderBy('ordre', 'ASC')->addOrderBy('nom', 'ASC');
        // findEntities (pluriel) : exécute et renvoie un TABLEAU d'objets Projet.
        return $this->findEntities($qb);
    }

    public function find(int $id): Projet {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            // where(... eq('id', ...)) = « WHERE id = ? ». createNamedParameter INSÈRE la valeur de
            // façon sécurisée (paramètre préparé) : c'est ce qui protège de l'injection SQL — on ne
            // colle JAMAIS une valeur directement dans la requête. PARAM_INT force le type entier.
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        // findEntity (singulier) : renvoie UN objet, ou lève DoesNotExistException si rien trouvé.
        return $this->findEntity($qb);
    }
}
