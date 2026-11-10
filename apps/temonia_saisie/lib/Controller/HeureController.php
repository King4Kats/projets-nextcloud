<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

// Réutilise la couche données de Pilotage (app compagnon).
use OCA\TemoniaPilotage\Db\ActiviteMapper;
use OCA\TemoniaPilotage\Db\Heure;        // l'« entité » Heure = une ligne de saisie d'heures
use OCA\TemoniaPilotage\Db\HeureMapper;  // le mapper = l'accès BD pour ces lignes
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException; // exception levée quand find() ne trouve rien
use OCP\AppFramework\Http;                      // contient les codes HTTP (403, 404…)
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Saisie des heures par l'utilisateur courant : chacun ne voit, modifie et
 * supprime QUE ses propres saisies, et ne peut pointer que sur les projets
 * auxquels il a accès.
 */
class HeureController extends Controller {
    private HeureMapper $heures;
    private TacheMapper $taches;
    private ProjetMapper $projets;
    private ActiviteMapper $activites;
    private AccessService $access;
    private IUserSession $userSession;
    private IUserManager $userManager;

    public function __construct(string $appName, IRequest $request, HeureMapper $heures, TacheMapper $taches, ProjetMapper $projets, ActiviteMapper $activites, AccessService $access, IUserSession $userSession, IUserManager $userManager) {
        parent::__construct($appName, $request);
        $this->heures = $heures;
        $this->taches = $taches;
        $this->projets = $projets;
        $this->activites = $activites;
        $this->access = $access;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
    }

    // Petit raccourci interne : l'identifiant de l'utilisateur connecté ('' si personne).
    // On l'utilise partout pour savoir « à qui » appartient une saisie.
    private function uid(): string {
        $u = $this->userSession->getUser();
        return $u ? $u->getUID() : '';
    }

    // GET /api/heures : liste MES saisies (optionnellement filtrées sur un jour donné).
    // Le « ?string $jour = null » veut dire : paramètre facultatif, null par défaut.
    #[NoAdminRequired]
    public function index(?string $jour = null): DataResponse {
        // Récupère uniquement les saisies de l'utilisateur courant (jamais celles des autres).
        $entries = $this->heures->findByUser($this->uid(), $jour);

        // On prépare une table id_projet → nom_projet, pour afficher un nom lisible plus bas
        // sans refaire un appel BD à chaque ligne.
        $projetsNom = [];
        foreach ($this->projets->findAll() as $p) {
            $projetsNom[$p->getId()] = $p->getNom();
        }

        $out = [];
        foreach ($entries as $h) {
            // jsonSerialize() transforme l'entité Heure en tableau simple (id, heures, jour…).
            $row = $h->jsonSerialize();
            // On ajoute des champs « d'affichage » qu'on va compléter si on retrouve la tâche.
            $row['tacheTitre'] = null;
            $row['projetNom'] = null;
            $row['activiteNom'] = null;
            // find() peut échouer si la tâche a été supprimée depuis : on protège avec try/catch
            // pour que la liste s'affiche quand même (la ligne reste, juste sans son contexte).
            try {
                $t = $this->taches->find($h->getTacheId());
                $row['tacheTitre'] = $t->getTitre();
                // L'opérateur « ?? null » : prend la valeur, ou null si la clé n'existe pas.
                $row['projetNom'] = $projetsNom[$t->getProjetId()] ?? null;
                if ($t->getActiviteId()) {
                    // Second try/catch : l'activité (phase) peut elle aussi avoir disparu.
                    try {
                        $row['activiteNom'] = $this->activites->find($t->getActiviteId())->getNom();
                    } catch (DoesNotExistException $e) {
                    }
                }
            } catch (DoesNotExistException $e) {
            }
            $out[] = $row;
        }
        return new DataResponse($out);
    }

    // POST /api/heures : créer une nouvelle saisie d'heures.
    // « pour » (facultatif) : uid d'une AUTRE personne. Réservé aux administrateurs (direction) —
    // leur permet de déclarer des heures POUR quelqu'un (ex. saisir les heures de un salarié). Ignoré
    // pour un non-admin (chacun ne pointe que pour lui-même).
    #[NoAdminRequired]
    public function create(?int $tache = null, ?string $jour = null, ?float $heures = null, ?string $commentaire = null, ?string $pour = null): DataResponse {
        // Validation des entrées AVANT d'écrire en base. On renvoie une erreur 400
        // (« mauvaise requête ») avec un message clair si quelque chose manque.
        if ($tache === null) {
            return new DataResponse(['message' => 'Tâche obligatoire.'], Http::STATUS_BAD_REQUEST);
        }
        if ($heures === null || $heures <= 0) {
            return new DataResponse(['message' => 'Indiquez un nombre d’heures.'], Http::STATUS_BAD_REQUEST);
        }
        // La tâche visée existe-t-elle ? Sinon 404 (« introuvable »).
        try {
            $t = $this->taches->find($tache);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Tâche introuvable.'], Http::STATUS_NOT_FOUND);
        }
        // On ne peut pointer des heures que sur une tâche d'un projet auquel on a accès.
        // (Contrôle de sécurité côté serveur : ne JAMAIS faire confiance au seul front.)
        $allowed = array_flip($this->access->accessibleProjetIds($this->uid()));
        if (!isset($allowed[$t->getProjetId()])) {
            return new DataResponse(['message' => 'Accès refusé à ce projet.'], Http::STATUS_FORBIDDEN);
        }
        // À QUI attribuer la saisie ? Par défaut soi-même. Un ADMIN peut cibler une autre personne
        // via « pour » (elle doit exister). Un non-admin qui enverrait « pour » est ignoré (sécurité).
        $target = $this->uid();
        if ($pour !== null && $pour !== '' && $this->access->isAdmin($this->uid())) {
            if (!$this->userManager->userExists($pour)) {
                return new DataResponse(['message' => 'Personne introuvable.'], Http::STATUS_BAD_REQUEST);
            }
            $target = $pour;
        }
        // On construit la nouvelle ligne (entité) et on remplit ses champs un par un.
        $h = new Heure();
        $h->setTacheId($tache);
        $h->setUserId($target);                             // propriétaire = soi, ou la personne ciblée (admin)
        // Si aucun jour fourni, on met la date du jour (format AAAA-MM-JJ).
        $h->setJour(($jour !== null && $jour !== '') ? $jour : date('Y-m-d'));
        $h->setHeures($heures);
        $h->setCommentaire($commentaire ?: null);           // '' devient null (champ vide propre)
        $h->setCreatedAt(time());                           // horodatage de création (secondes Unix)
        // insert() écrit la ligne en base et renvoie l'entité complétée (avec son nouvel id).
        // Code 201 = « Created » (créé avec succès).
        return new DataResponse($this->heures->insert($h), Http::STATUS_CREATED);
    }

    // PUT /api/heures/{id} : modifier UNE de mes saisies.
    #[NoAdminRequired]
    public function update(int $id, ?float $heures = null, ?string $jour = null, ?string $commentaire = null): DataResponse {
        try {
            $h = $this->heures->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND);
        }
        // Sécurité : on ne modifie QUE ses propres saisies — sauf un ADMIN, qui peut corriger celles
        // de n'importe qui (saisie déléguée depuis l'écran Administration).
        if ($h->getUserId() !== $this->uid() && !$this->access->isAdmin($this->uid())) {
            return new DataResponse(['message' => 'Vous ne pouvez modifier que vos saisies.'], Http::STATUS_FORBIDDEN);
        }
        // On ne touche QUE les champs réellement envoyés (les autres restent inchangés).
        if ($heures !== null && $heures > 0) {
            $h->setHeures($heures);
        }
        if ($jour !== null && $jour !== '') {
            $h->setJour($jour);
        }
        if ($commentaire !== null) {
            $h->setCommentaire($commentaire ?: null);
        }
        // update() écrit les modifications en base et renvoie l'entité à jour.
        return new DataResponse($this->heures->update($h));
    }

    // DELETE /api/heures/{id} : supprimer UNE de mes saisies.
    #[NoAdminRequired]
    public function destroy(int $id): DataResponse {
        try {
            $h = $this->heures->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND);
        }
        // Même règle : on ne supprime que ses propres saisies — sauf un ADMIN (gestion déléguée).
        if ($h->getUserId() !== $this->uid() && !$this->access->isAdmin($this->uid())) {
            return new DataResponse(['message' => 'Vous ne pouvez supprimer que vos saisies.'], Http::STATUS_FORBIDDEN);
        }
        $this->heures->delete($h);
        // On renvoie l'entité supprimée (pratique pour le front, ex. confirmer quelle ligne retirer).
        return new DataResponse($h);
    }
}
