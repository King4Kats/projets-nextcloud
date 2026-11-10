<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

// Force le typage strict : PHP refuse de convertir « en douce » un type en un autre
// (ex. passer "5" là où un int est attendu lèvera une erreur au lieu de deviner). Plus sûr.
declare(strict_types=1);

// Le « namespace » = l'adresse logique de la classe. Nextcloud impose la forme
// OCA\<NomApp>\<Dossier>. Ici : app « l'associationSaisie », dossier « Controller ».
namespace OCA\TemoniaSaisie\Controller;

// « use » = on importe des classes d'ailleurs pour pouvoir les nommer court ensuite
// (sinon il faudrait écrire leur chemin complet à chaque fois).
use OCA\TemoniaPilotage\Service\AccessService;      // service de droits (dans l'app compagnon Pilotage)
use OCA\TemoniaPilotage\Service\Libelles;           // mots propres à la structure (« Équipe »…)
use OCA\TemoniaSaisie\AppInfo\Application;           // contient l'APP_ID ('temonia_saisie')
use OCP\AppFramework\Controller;                     // classe mère de tout contrôleur Nextcloud
use OCP\AppFramework\Http\Attribute\NoAdminRequired; // attribut : « pas besoin d'être admin NC »
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;  // attribut : « pas de jeton anti-CSRF requis »
use OCP\AppFramework\Http\TemplateResponse;          // réponse = une page HTML (un « template »)
use OCP\INavigationManager;                           // le bandeau d'applis (entrée active)
use OCP\IUserSession;                                 // pour connaître l'utilisateur connecté
use OCP\Util;                                         // utilitaires NC (charger un JS, un CSS…)

/**
 * Sert l'unique page HTML de l'app et charge le JS/CSS. On passe au template
 * un drapeau `isManager` (direction) pour que le front affiche ou non les
 * fonctions d'administration ; côté serveur, chaque endpoint revérifie les droits.
 */
class PageController extends Controller {
    // Ces deux « propriétés » sont des dépendances : des objets fournis par Nextcloud
    // dont la classe a besoin pour travailler. On les range ici pour les réutiliser.
    private AccessService $access;      // sait dire si un utilisateur est « direction »
    private IUserSession $userSession;  // donne l'utilisateur actuellement connecté
    private INavigationManager $navigation; // le bandeau d'applis de Nextcloud (pour allumer la bonne entrée)
    private Libelles $libelles;             // « Équipe X », « Partenaires »… (réglables, voir Pilotage)

    // Le constructeur reçoit ses dépendances en paramètres : c'est l'« injection de
    // dépendances ». On ne fabrique rien soi-même (pas de « new ») ; Nextcloud regarde
    // le TYPE de chaque paramètre et fournit automatiquement le bon objet (autowiring).
    public function __construct(string $appName, \OCP\IRequest $request, AccessService $access, IUserSession $userSession, INavigationManager $navigation, Libelles $libelles) {
        // On transmet les 2 premiers paramètres à la classe mère (elle en a besoin).
        parent::__construct($appName, $request);
        // On mémorise les autres dans nos propriétés pour les utiliser dans index() et heures().
        $this->access = $access;
        $this->userSession = $userSession;
        $this->navigation = $navigation;
        $this->libelles = $libelles;
    }

    // Ces « attributs » (#[…]) au-dessus de la méthode modifient son comportement :
    //  - NoAdminRequired : un utilisateur normal (pas seulement l'admin NC) peut y accéder.
    //  - NoCSRFRequired  : on autorise l'ouverture directe de la page (une navigation GET
    //                      simple n'a pas de jeton anti-CSRF ; c'est acceptable pour afficher
    //                      une page, les écritures passeront par l'API qui, elle, l'exige).
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // Demande à Nextcloud de charger, dans cette page, notre JavaScript (app/js/main.js)
        // et notre feuille de style (app/css/main.css). APP_ID = 'temonia_saisie', 'main' = nom du fichier.
        Util::addScript(Application::APP_ID, 'main');
        Util::addStyle(Application::APP_ID, 'main');

        // Qui est connecté ? getUser() peut renvoyer null (ex. session expirée), d'où la prudence.
        $u = $this->userSession->getUser();
        // isManager = vrai si l'utilisateur fait partie de la direction. L'opérateur ternaire
        // « condition ? valeurSiVrai : valeurSiFaux » évite d'appeler isDirection() sur un null.
        $isManager = $u ? $this->access->isDirection($u->getUID()) : false;
        // isAdmin = administrateur Nextcloud : donne accès à l'écran d'ADMINISTRATION
        // (détail des heures par personne). Plus restreint que isManager.
        $isAdmin = $u ? $this->access->isAdmin($u->getUID()) : false;

        // On renvoie la page : app 'temonia_saisie', template 'main' (app/templates/main.php),
        // en lui passant un tableau de variables. Le template pourra lire $_['isManager'] et
        // $_['isAdmin'] pour afficher (ou masquer) les fonctions réservées côté navigateur.
        // libelles : les mots propres à la structure, envoyés en JSON au navigateur (attribut data-libelles).
        return new TemplateResponse(Application::APP_ID, 'main', ['isManager' => $isManager, 'isAdmin' => $isAdmin, 'libelles' => json_encode($this->libelles->tous())]);
    }

    // Écran « Heures » : pointer ses heures depuis le téléphone (app/js/heures.js, app/css/heures.css).
    // Pas de drapeau direction ici : l'écran ne montre que MES heures, l'API vérifie les droits.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function heures(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'heures');
        Util::addStyle(Application::APP_ID, 'heures');
        // L'app a DEUX entrées dans le bandeau (Projets et Heures). Par défaut Nextcloud allume celle
        // qui porte l'id de l'app (Projets) ; ici on allume celle de l'écran Heures (id défini dans info.xml).
        $this->navigation->setActiveEntry('temonia_saisie_heures');
        return new TemplateResponse(Application::APP_ID, 'heures');
    }
}
