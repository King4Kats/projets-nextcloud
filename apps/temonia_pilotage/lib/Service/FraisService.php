<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCA\TemoniaPilotage\Db\Frais;
use OCA\TemoniaPilotage\Db\FraisMapper;
use OCA\TemoniaPilotage\Db\FraisTheme;
use OCA\TemoniaPilotage\Db\FraisThemeMapper;
use OCA\TemoniaPilotage\Db\FraisType;
use OCA\TemoniaPilotage\Db\FraisTypeMapper;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * Règles MÉTIER des notes de frais : validation des saisies, calcul des montants,
 * numérotation, droits de modification et changements de statut. Les contrôleurs
 * (dans temonia_saisie) ne font que parler HTTP et déléguer ici.
 */
class FraisService {
    public const STATUTS = ['soumis', 'valide', 'refuse', 'rembourse'];
    // Transitions autorisées : action → [statut de départ → statut d'arrivée].
    public const TRANSITIONS = [
        'valider' => ['soumis' => 'valide'],
        'refuser' => ['soumis' => 'refuse'],
        'rembourser' => ['valide' => 'rembourse'],
        'reouvrir' => ['refuse' => 'soumis', 'valide' => 'soumis'],
    ];
    private const BAREME_DEFAUT = 0.321; // €/km (valeur provisoire, modifiable par l'admin)

    private FraisMapper $frais;
    private FraisTypeMapper $types;
    private FraisThemeMapper $themes;
    private ProjetMapper $projets;
    private AccessService $access;
    private NotifyService $notify;
    private JustificatifService $justif;
    private IConfig $config;
    private IUserManager $userManager;

    public function __construct(FraisMapper $frais, FraisTypeMapper $types, FraisThemeMapper $themes, ProjetMapper $projets, AccessService $access, NotifyService $notify, JustificatifService $justif, IConfig $config, IUserManager $userManager) {
        $this->frais = $frais;
        $this->types = $types;
        $this->themes = $themes;
        $this->projets = $projets;
        $this->access = $access;
        $this->notify = $notify;
        $this->justif = $justif;
        $this->config = $config;
        $this->userManager = $userManager;
    }

    // ---------- Réglages ----------

    /** Barème kilométrique (€/km) en vigueur. */
    public function bareme(): float {
        $v = (float)$this->config->getAppValue(Application::APP_ID, 'frais_bareme_km', (string)self::BAREME_DEFAUT);
        return $v > 0 ? $v : self::BAREME_DEFAUT;
    }

    public function setBareme(float $v): void {
        $this->config->setAppValue(Application::APP_ID, 'frais_bareme_km', (string)$v);
    }

    /**
     * Données de référence pour le formulaire : types et thématiques ACTIFS, projets ACTIFS
     * (tous, pas seulement ceux dont on est membre : un frais peut concerner un projet
     * auquel on donne un coup de main ponctuel), et le barème.
     */
    public function refs(): array {
        $projets = [];
        foreach ($this->projets->findAll() as $p) {
            if ($p->getStatut() === 'actif') {
                $projets[] = ['id' => $p->getId(), 'nom' => $p->getNom(), 'couleur' => $p->getCouleur()];
            }
        }
        return [
            'types' => $this->types->findAll(true),
            'themes' => $this->themes->findAll(true),
            'projets' => $projets,
            'bareme' => $this->bareme(),
        ];
    }

    // ---------- Droits ----------

    /** L'auteur ne modifie/supprime que tant que c'est « soumis » ; l'admin peut toujours. */
    public function canEdit(Frais $f, string $uid, bool $isAdmin): bool {
        if ($isAdmin) {
            return true;
        }
        return $f->getUserId() === $uid && $f->getStatut() === 'soumis';
    }

    // ---------- Validation d'une saisie ----------

    /**
     * Vérifie les champs envoyés par le formulaire. Renvoie
     *   ['errors' => [champ => message, …], 'clean' => [valeurs nettoyées]]
     * (errors vide = tout est bon). $isAdmin assouplit certaines règles (ex. rattacher à un
     * projet en pause, ou à une thématique désactivée, lors d'une correction).
     */
    public function validate(array $in, bool $isAdmin): array {
        $errors = [];
        $clean = [];

        // Date : format AAAA-MM-JJ, réelle, pas dans le futur.
        $date = trim((string)($in['dateFrais'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $errors['dateFrais'] = 'Date invalide.';
        } elseif ($date > date('Y-m-d')) {
            $errors['dateFrais'] = 'La date ne peut pas être dans le futur.';
        } else {
            $clean['dateFrais'] = $date;
        }

        // Rattachement : exactement UN des deux (projet OU thématique).
        $projetId = (int)($in['projetId'] ?? 0);
        $themeId = (int)($in['themeId'] ?? 0);
        if (($projetId > 0) === ($themeId > 0)) {
            $errors['rattachement'] = 'Choisissez un projet OU une thématique.';
        } elseif ($projetId > 0) {
            try {
                $p = $this->projets->find($projetId);
                if ($p->getStatut() !== 'actif' && !$isAdmin) {
                    $errors['rattachement'] = 'Ce projet n’est plus actif.';
                }
                $clean['projetId'] = $projetId;
                $clean['themeId'] = null;
            } catch (DoesNotExistException $e) {
                $errors['rattachement'] = 'Projet introuvable.';
            }
        } else {
            try {
                $t = $this->themes->find($themeId);
                if (!$t->getActif() && !$isAdmin) {
                    $errors['rattachement'] = 'Cette thématique n’est plus proposée.';
                }
                $clean['themeId'] = $themeId;
                $clean['projetId'] = null;
            } catch (DoesNotExistException $e) {
                $errors['rattachement'] = 'Thématique introuvable.';
            }
        }

        // Type de frais.
        $typeCode = trim((string)($in['typeCode'] ?? ''));
        $type = null;
        try {
            $type = $this->types->findByCode($typeCode);
            if (!$type->getActif() && !$isAdmin && empty($in['_keepType'])) {
                $errors['typeCode'] = 'Ce type de frais n’est plus proposé.';
            }
            $clean['typeCode'] = $type->getCode();
        } catch (DoesNotExistException $e) {
            $errors['typeCode'] = 'Type de frais obligatoire.';
        }

        // Montants. Deux modes : KILOMÉTRIQUE (on saisit des km) ou NORMAL (on saisit un TTC).
        $num = function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            $s = str_replace(',', '.', (string)$v);
            return is_numeric($s) ? round((float)$s, 2) : null;
        };
        if ($type !== null && $type->getEstKm()) {
            $km = $num($in['km'] ?? null);
            if ($km === null || $km <= 0 || $km > 5000) {
                $errors['km'] = 'Indiquez la distance en km (entre 0 et 5000).';
            } else {
                $clean['km'] = $km;
            }
            $clean['montantTtc'] = null; // recalculé par computeMontants()
            $clean['montantHt'] = null;
            $clean['tva'] = null;
        } else {
            $ttc = $num($in['montantTtc'] ?? null);
            $tva = $num($in['tva'] ?? null);
            $ht = $num($in['montantHt'] ?? null);
            if ($ttc === null || $ttc <= 0 || $ttc > 100000) {
                $errors['montantTtc'] = 'Indiquez le montant TTC payé.';
            } else {
                $clean['montantTtc'] = $ttc;
                if ($tva !== null && ($tva < 0 || $tva > $ttc)) {
                    $errors['tva'] = 'La TVA doit être comprise entre 0 et le TTC.';
                }
                if ($ht !== null && ($ht < 0 || $ht > $ttc)) {
                    $errors['montantHt'] = 'Le HT doit être compris entre 0 et le TTC.';
                }
                $clean['tva'] = $tva;
                $clean['montantHt'] = $ht;
            }
            $clean['km'] = null;
        }

        // Textes.
        $objet = trim((string)($in['objet'] ?? ''));
        if (mb_strlen($objet) < 3) {
            $errors['objet'] = 'Précisez l’objet de la dépense (3 caractères minimum).';
        } else {
            $clean['objet'] = mb_substr($objet, 0, 500);
        }
        $txt = function ($v, int $max): ?string {
            $s = trim((string)($v ?? ''));
            return $s === '' ? null : mb_substr($s, 0, $max);
        };
        $clean['lieu'] = $txt($in['lieu'] ?? null, 255);
        $clean['fournisseur'] = $txt($in['fournisseur'] ?? null, 255);
        $clean['commentaire'] = $txt($in['commentaire'] ?? null, 2000);

        return ['errors' => $errors, 'clean' => $clean];
    }

    /**
     * Finalise les montants selon le type :
     *  - kilométrique : TTC = km × barème (arrondi au centime), HT = TTC, TVA = 0, barème mémorisé ;
     *  - normal       : HT déduit (TTC − TVA) s'il n'a pas été saisi.
     */
    public function computeMontants(array $clean, FraisType $type): array {
        if ($type->getEstKm()) {
            $bareme = $this->bareme();
            $clean['baremeKm'] = $bareme;
            $clean['montantTtc'] = round((float)$clean['km'] * $bareme, 2);
            $clean['montantHt'] = $clean['montantTtc'];
            $clean['tva'] = 0.0;
        } else {
            $clean['baremeKm'] = null;
            if ($clean['montantHt'] === null && $clean['tva'] !== null) {
                $clean['montantHt'] = round((float)$clean['montantTtc'] - (float)$clean['tva'], 2);
            }
        }
        return $clean;
    }

    // Recopie les valeurs nettoyées dans l'entité (utilisé à la création ET à la modification).
    private function apply(Frais $f, array $clean): void {
        $f->setDateFrais($clean['dateFrais']);
        $f->setProjetId($clean['projetId']);
        $f->setThemeId($clean['themeId']);
        $f->setTypeCode($clean['typeCode']);
        $f->setMontantTtc((float)$clean['montantTtc']);
        $f->setMontantHt($clean['montantHt']);
        $f->setTva($clean['tva']);
        $f->setKm($clean['km']);
        $f->setBaremeKm($clean['baremeKm']);
        $f->setLieu($clean['lieu']);
        $f->setFournisseur($clean['fournisseur']);
        $f->setObjet($clean['objet']);
        $f->setCommentaire($clean['commentaire']);
        $f->setUpdatedAt(time());
    }

    // ---------- Numérotation ----------

    /** Prochain numéro de l'année : "2026-001", "2026-002"… (3 chiffres, puis 4 si on dépasse 999). */
    public function nextNumero(int $annee): string {
        $max = $this->frais->maxNumeroForYear($annee);
        $n = 0;
        if ($max !== null && preg_match('/-(\d+)$/', $max, $m)) {
            $n = (int)$m[1];
        }
        return $annee . '-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
    }

    // ---------- Création / modification / suppression ----------

    /**
     * Crée un frais pour $uid à partir des valeurs NETTOYÉES (voir validate()). $tempId = fichier
     * justificatif envoyé au préalable (facultatif). $scanJson = réponse brute de l'IA (facultatif).
     * Prévient les admins qu'une note est à valider.
     */
    public function create(string $uid, array $clean, ?string $tempId, ?string $scanJson): Frais {
        $type = $this->types->findByCode($clean['typeCode']);
        $clean = $this->computeMontants($clean, $type);
        $f = new Frais();
        $f->setUserId($uid);
        $f->setStatut('soumis');
        $f->setCreatedAt(time());
        $f->setScanJson($scanJson !== null && $scanJson !== '' ? mb_substr($scanJson, 0, 10000) : null);
        $this->apply($f, $clean);

        // Numéro de l'année de CRÉATION (immuable ensuite). L'index unique sur `numero` garantit
        // l'absence de doublon : si deux personnes enregistrent au même instant, la 2e insertion
        // est refusée par la BD → on recalcule le numéro et on réessaie (3 tentatives max).
        $annee = (int)date('Y');
        $saved = null;
        for ($i = 0; $i < 3; $i++) {
            $f->setNumero($this->nextNumero($annee));
            try {
                $saved = $this->frais->insert($f);
                break;
            } catch (DBException $e) {
                if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $i === 2) {
                    throw $e;
                }
            }
        }

        // Le justificatif temporaire devient définitif (il faut l'id du frais pour le nommer).
        if ($tempId !== null && $tempId !== '') {
            $res = $this->justif->promote($uid, $tempId, $saved->getId());
            if ($res !== null) {
                $saved->setPhotoName($res['name']);
                $saved->setPhotoMime($res['mime']);
                $saved = $this->frais->update($saved);
            }
        }

        $this->notify->fraisNouveau($saved->getId(), $saved->getNumero(), $uid, $this->displayName($uid), $saved->getMontantTtc());
        return $saved;
    }

    /** Applique une modification (valeurs nettoyées) et renvoie le frais à jour. */
    public function update(Frais $f, array $clean): Frais {
        $type = $this->types->findByCode($clean['typeCode']);
        $clean = $this->computeMontants($clean, $type);
        $this->apply($f, $clean);
        return $this->frais->update($f);
    }

    /** Supprime le frais ET son justificatif, et retire la notification « à valider » éventuelle. */
    public function delete(Frais $f): void {
        $this->justif->delete($f->getId());
        $this->notify->clearFraisNouveau($f->getId());
        $this->frais->delete($f);
    }

    /** Attache (ou remplace) un justificatif déjà lu en mémoire. */
    public function attachPhoto(Frais $f, string $bytes, string $mime): Frais {
        $res = $this->justif->store($f->getId(), $bytes, $mime);
        $f->setPhotoName($res['name']);
        $f->setPhotoMime($res['mime']);
        $f->setUpdatedAt(time());
        return $this->frais->update($f);
    }

    // ---------- Statuts (admin) ----------

    /**
     * Change le statut selon une ACTION ('valider', 'refuser', 'rembourser', 'reouvrir').
     * $opts : 'motif' (refuser, obligatoire), 'date' + 'mode' (rembourser, date obligatoire).
     * Lève \InvalidArgumentException si la transition n'est pas permise depuis le statut actuel.
     */
    public function transition(Frais $f, string $action, string $adminUid, array $opts = []): Frais {
        $map = self::TRANSITIONS[$action] ?? null;
        if ($map === null || !isset($map[$f->getStatut()])) {
            throw new \InvalidArgumentException('Action impossible depuis le statut « ' . $f->getStatut() . ' ».');
        }
        $to = $map[$f->getStatut()];
        if ($action === 'refuser') {
            $motif = trim((string)($opts['motif'] ?? ''));
            if ($motif === '') {
                throw new \InvalidArgumentException('Indiquez le motif du refus.');
            }
            $f->setCommentaireAdmin(mb_substr($motif, 0, 500));
        }
        if ($action === 'rembourser') {
            $date = trim((string)($opts['date'] ?? ''));
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new \InvalidArgumentException('Indiquez la date du remboursement.');
            }
            $f->setDateRemboursement($date);
            $mode = trim((string)($opts['mode'] ?? ''));
            $f->setRemboursementMode($mode === '' ? null : mb_substr($mode, 0, 32));
        }
        if ($action === 'reouvrir') {
            // Retour en « soumis » : on efface les traces de la décision précédente.
            $f->setCommentaireAdmin(null);
            $f->setDateRemboursement(null);
            $f->setRemboursementMode(null);
            $f->setValidatedAt(null);
            $f->setValidatedBy(null);
        } else {
            $f->setValidatedAt(time());
            $f->setValidatedBy($adminUid);
        }
        $f->setStatut($to);
        $f->setUpdatedAt(time());
        $saved = $this->frais->update($f);

        // Cloche : la note n'est plus « à valider » ; et on prévient l'auteur de la décision.
        $this->notify->clearFraisNouveau($saved->getId());
        if ($action !== 'reouvrir') {
            $this->notify->fraisStatut($saved->getId(), $saved->getNumero(), $saved->getUserId(), $to, $saved->getCommentaireAdmin());
        }
        return $saved;
    }

    // ---------- Affichage ----------

    private function displayName(string $uid): string {
        $u = $this->userManager->get($uid);
        return $u ? $u->getDisplayName() : $uid;
    }

    /**
     * Complète un frais avec des infos LISIBLES pour l'écran : nom de la personne et son équipe,
     * nom/couleur du projet ou libellé de la thématique, libellé du type, droit de modification.
     * Les tables de correspondance ($projetsMap…) sont passées en paramètre pour éviter une
     * requête par ligne quand on enrichit une liste (voir refsMaps()).
     */
    public function enrich(Frais $f, string $viewerUid, bool $isAdmin, array $maps): array {
        $row = $f->jsonSerialize();
        $row['personne'] = $maps['users'][$f->getUserId()] ?? $this->displayName($f->getUserId());
        $row['equipe'] = $maps['equipes'][$f->getUserId()] ?? $this->access->teamOf($f->getUserId());
        $row['projetNom'] = null;
        $row['projetCouleur'] = null;
        $row['themeLabel'] = null;
        if ($f->getProjetId()) {
            $p = $maps['projets'][$f->getProjetId()] ?? null;
            $row['projetNom'] = $p ? $p['nom'] : ('Projet #' . $f->getProjetId() . ' (supprimé)');
            $row['projetCouleur'] = $p ? $p['couleur'] : null;
        }
        if ($f->getThemeId()) {
            $row['themeLabel'] = $maps['themes'][$f->getThemeId()] ?? ('Thématique #' . $f->getThemeId() . ' (supprimée)');
        }
        $t = $maps['types'][$f->getTypeCode()] ?? null;
        $row['typeLabel'] = $t ? $t['label'] : $f->getTypeCode();
        $row['estKm'] = $t ? $t['estKm'] : false;
        $row['peutModifier'] = $this->canEdit($f, $viewerUid, $isAdmin);
        if ($f->getValidatedBy()) {
            $row['validatedByNom'] = $maps['users'][$f->getValidatedBy()] ?? $this->displayName($f->getValidatedBy());
        }
        return $row;
    }

    /** Tables de correspondance id → infos, calculées UNE fois pour enrichir une liste entière. */
    public function refsMaps(array $fraisList): array {
        $projets = [];
        foreach ($this->projets->findAll() as $p) {
            $projets[$p->getId()] = ['nom' => $p->getNom(), 'couleur' => $p->getCouleur()];
        }
        $themes = [];
        foreach ($this->themes->findAll(false) as $t) {
            $themes[$t->getId()] = $t->getLabel();
        }
        $types = [];
        foreach ($this->types->findAll(false) as $t) {
            $types[$t->getCode()] = ['label' => $t->getLabel(), 'estKm' => $t->getEstKm()];
        }
        // Noms et équipes : seulement pour les personnes présentes dans la liste (pas tout l'annuaire).
        $users = [];
        $equipes = [];
        foreach ($fraisList as $f) {
            foreach ([$f->getUserId(), $f->getValidatedBy()] as $uid) {
                if ($uid && !isset($users[$uid])) {
                    $users[$uid] = $this->displayName($uid);
                    $equipes[$uid] = $this->access->teamOf($uid);
                }
            }
        }
        return ['projets' => $projets, 'themes' => $themes, 'types' => $types, 'users' => $users, 'equipes' => $equipes];
    }

    /**
     * Petit résumé chiffré d'une liste de frais : montants et nombres par « famille » de statut
     * (en attente = soumis ; à rembourser = validé ; remboursé).
     */
    public function resume(array $fraisList): array {
        $r = ['enAttente' => ['n' => 0, 'montant' => 0.0], 'aRembourser' => ['n' => 0, 'montant' => 0.0], 'rembourse' => ['n' => 0, 'montant' => 0.0], 'refuse' => ['n' => 0, 'montant' => 0.0]];
        $key = ['soumis' => 'enAttente', 'valide' => 'aRembourser', 'rembourse' => 'rembourse', 'refuse' => 'refuse'];
        foreach ($fraisList as $f) {
            $k = $key[$f->getStatut()] ?? null;
            if ($k === null) {
                continue;
            }
            $r[$k]['n']++;
            $r[$k]['montant'] = round($r[$k]['montant'] + $f->getMontantTtc(), 2);
        }
        return $r;
    }
}
