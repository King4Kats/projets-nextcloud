<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Un projet l'association (table `tp_projets`). C'est la racine de la hiérarchie
 * Projet → Phase → Activité → Tâche → Sous-tâche. Le champ `membres` (JSON)
 * liste les personnes/groupes Nextcloud qui y ont accès côté équipe.
 *
 * Note : les entités Nextcloud utilisent le camelCase en PHP (deckBoardId) et
 * le snake_case en base (deck_board_id) ; la conversion est automatique.
 * Les annotations @method ci-dessous documentent les accesseurs générés par Entity.
 *
 * @method string getNom()
 * @method void setNom(string $nom)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getCouleur()
 * @method void setCouleur(?string $couleur)
 * @method string getStatut()
 * @method void setStatut(string $statut)
 * @method string|null getResponsable()
 * @method void setResponsable(?string $responsable)
 * @method int|null getDeckBoardId()
 * @method void setDeckBoardId(?int $deckBoardId)
 * @method string|null getDateDebut()
 * @method void setDateDebut(?string $dateDebut)
 * @method string|null getDateFin()
 * @method void setDateFin(?string $dateFin)
 * @method int getOrdre()
 * @method void setOrdre(int $ordre)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method array|null getMembres()
 * @method void setMembres(?array $membres)
 */
// « extends Entity » : on hérite de la classe Entity de Nextcloud. C'est elle qui fournit
// magiquement getNom()/setNom()/getCouleur()… à partir des propriétés ci-dessous : pas besoin
// de les écrire à la main (d'où les annotations @method plus haut, juste pour l'auto-complétion).
// « implements JsonSerializable » : on promet une méthode jsonSerialize() → l'objet sait se
// transformer en JSON quand un contrôleur le renvoie dans une DataResponse.
class Projet extends Entity implements JsonSerializable {
    // Chaque « protected $champ » correspond à UNE colonne de la table tp_projets.
    // Rappel : camelCase ici (deckBoardId) ↔ snake_case en base (deck_board_id), converti tout seul.
    protected $nom;
    protected $description;
    protected $couleur;
    protected $statut;
    protected $responsable;
    protected $deckBoardId;
    protected $dateDebut;
    protected $dateFin;
    protected $ordre;
    protected $createdAt;
    protected $membres;

    public function __construct() {
        // addType déclare le TYPE réel d'une colonne pour que la conversion base ↔ PHP soit correcte.
        // Sans ça, tout revient en chaîne de caractères depuis la base. Ici : les ids/compteurs sont
        // des entiers, et « membres » est stocké en JSON dans une seule colonne texte, puis re-décodé
        // automatiquement en tableau PHP à la lecture (et ré-encodé à l'écriture).
        $this->addType('id', 'integer');
        $this->addType('deckBoardId', 'integer');
        $this->addType('ordre', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('membres', 'json');
    }

    // jsonSerialize : ce que devient l'objet une fois transformé en JSON pour le front.
    // On choisit explicitement les champs exposés (ici tous). « membres ?? [] » : si null, on
    // renvoie un tableau vide plutôt que null (le JS reçoit toujours une liste, plus simple à traiter).
    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'couleur' => $this->couleur,
            'statut' => $this->statut,
            'responsable' => $this->responsable,
            'deckBoardId' => $this->deckBoardId,
            'dateDebut' => $this->dateDebut,
            'dateFin' => $this->dateFin,
            'ordre' => $this->ordre,
            'createdAt' => $this->createdAt,
            'membres' => $this->membres ?? [],
        ];
    }
}
