<?php

require_once 'Framework/Controller.php';
require_once 'Framework/Errors.php';
require_once 'Framework/Session.php';

require_once 'Modules/core/Model/CoreUser.php';
require_once 'Modules/core/Model/CoreConfig.php';
require_once 'Modules/core/Model/CoreSpace.php';
require_once 'Modules/core/Model/CoreStatus.php';

require_once 'Modules/core/Model/CoreUserSettings.php';

/**
 * Mother class for controller using secure connection
 *
 * @author Sylvain Prigent
 */
abstract class CorecookiesecureController extends Controller
{
    /** Name of the "remember me" cookie */
    protected const REMEMBER_COOKIE = 'auth';

    /** How long a "remember me" cookie is honoured, in seconds */
    protected const REMEMBER_LIFETIME = 3600 * 24 * 3;

    /**
     * Hand a freshly issued "remember me" token to the browser.
     *
     * The cookie carries the same protections as the session cookie, because it is
     * the same kind of secret with a longer life: unreadable by page scripts, not
     * sent on cross-site navigations, and transport-restricted over HTTPS only.
     * The path is pinned to the installation root; left to PHP it would default to
     * the directory of whichever URL happened to rotate the token, scoping the
     * cookie to a subtree or leaving several of them behind.
     *
     * @param int|string $id_user User the token was issued for
     * @param string $token token as issued by CoreUser::issueRememberToken()
     * @return bool whether the cookie could be set
     */
    protected function setRememberMeCookie($id_user, string $token): bool
    {
        return setcookie(
            self::REMEMBER_COOKIE,
            $id_user . "-" . $token,
            $this->rememberMeCookieOptions(time() + self::REMEMBER_LIFETIME)
        );
    }

    /**
     * Drop the "remember me" cookie the browser holds. Clearing must repeat the
     * attributes it was set with, or the browser keeps the original cookie.
     */
    protected function clearRememberMeCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', $this->rememberMeCookieOptions(time() - 3600));
    }

    private function rememberMeCookieOptions(int $expires): array
    {
        return array_merge(
            Session::secureCookieOptions($_SERVER),
            ['expires' => $expires, 'path' => Configuration::get('rootWeb', '/')]
        );
    }

    public function initSession($login)
    {
        // open the session
        session_unset();
        // Renew the identifier now that the User is authenticated, so one planted
        // in their browser beforehand cannot be reused against their account. This
        // is the single point where a successful authentication populates the
        // session, so the password, remember me and API key paths are all covered.
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $modelUser = new CoreUser();
        $sessuser = $modelUser->getUserByLogin($login);

        if ($this->request->getSession() != null) {
            $this->request->getSession()->setAttribut("id_user", $sessuser['id']);
            $this->request->getSession()->setAttribut("login", $sessuser['login']);
            $this->request->getSession()->setAttribut("email", $sessuser['email']);
            $this->request->getSession()->setAttribut("company", Configuration::get("name"));
            $this->request->getSession()->setAttribut("user_status", $sessuser['status_id']);
            if (isset($_SERVER["HTTP_X_API_KEY"])) {
                $this->request->getSession()->setAttribut("logbyapi", 1);
            }
            // add the user settings to the session
            $modelUserSettings = new CoreUserSettings();
            $settings = $modelUserSettings->getUserSettings($sessuser['id']);
            $this->request->getSession()->setAttribut("user_settings", $settings);
        }


        // update the user last connection
        $modelUser->updateLastConnection($sessuser['id']);

        // update user active base if the user is manager or admin
        $this->runModuleConnectionActions();


        return $sessuser;
    }

    /**
     *
     */
    public function runModuleConnectionActions()
    {
        $modules = Configuration::get("modules");
        foreach ($modules as $module) {
            $controllerName = $module."connectscript";
            $classController = ucfirst(strtolower($controllerName)) . "Controller";
            $fileController = 'Modules/' . $module . "/Controller/" . $classController . ".php";
            //echo "controller file = " . $fileController . "<br/>";
            if (file_exists($fileController)) {
                //echo "controller file = " . $fileController . "<br/>";
                // Instantiate controler
                require($fileController);
                $controller = new $classController($this->request, $this->currentSpace);
                //$controller->setRequest($this->request);
                return $controller->runAction($module, "index");
                //echo "controller file = " . $fileController . "done <br/>";
            }
        }
    }

    /**
     *
     * @param type $minimumStatus
     * @throws Exception
     */
    public function checkAuthorization($minimumStatus)
    {
        $auth = $this->isUserAuthorized($minimumStatus);
        if ($auth == 0) {
            throw new PfmAuthException("Error 403: Permission denied", 403);
        }
        if ($auth == -1) {
            $this->redirect("coreconnection");
        }
    }

    /**
     *
     * @param type $status
     * @return boolean
     */
    public function isUserStatus($status)
    {
        if (intval($_SESSION["user_status"]) >= intval($status)) {
            return true;
        }
        return false;
    }

    /**
     * @deprecated
     * @param type $menuName
     * @throws Exception
     */
    /*
    public function checkAuthorizationMenu($menuName) {
        $auth = $this->isUserMenuAuthorized($menuName);
        if ($auth == 0) {
            throw new PfmAuthException("Error 403: Permission denied", 403);
        }
        if ($auth == -1) {
            $this->redirect("coreconnection");
        }
    }
    */

    /**
     *
     * @param string $menuName
     * @param int $id_space
     * @param int $id_user
     * @throws Exception
     */
    public function checkAuthorizationMenuSpace($menuName, $id_space, $id_user)
    {
        $modelSpace = new CoreSpace();
        $auth = $modelSpace->isUserMenuSpaceAuthorized($menuName, $id_space, $id_user);
        if ($auth == 0) {
            if (isset($_SESSION['id_user']) && $_SESSION['id_user'] > 0) {
                throw new PfmAuthException("Error 403: Permission denied", 403);
            }
            throw new PfmAuthException("Error 401: need to log", 401);
        }
        return true;
    }

    /**
     *
     * @param type $menuName
     * @param type $id_space
     * @param type $id_user
     * @throws Exception
     */
    public function checkAuthorizationMenuSpaceNoException($menuName, $id_space, $id_user)
    {
        $modelSpace = new CoreSpace();
        $auth = $modelSpace->isUserMenuSpaceAuthorized($menuName, $id_space, $id_user);
        if ($auth == 0) {
            return false;
        }
        return true;
    }

    /**
     *
     * @param type $minimumStatus
     * @return int
     */
    public function isUserAuthorized($minimumStatus)
    {
        if (isset($_SESSION["user_status"])) {
            if (intval($_SESSION["user_status"]) >= intval($minimumStatus)) {
                return 1;
            }
            return 0;
        }
        return -1;
    }


    /**
     *
     * @param type $id_space
     * @param type $id_user
     * @return int
     */
    public function getUserSpaceStatus($id_space, $id_user)
    {
        $modelUser = new CoreUser();
        $userAppStatus = $modelUser->getStatus($id_user);
        if ($userAppStatus > 1) {
            return 4;
        }
        $modelSpace = new CoreSpace();
        $spaceRole = $modelSpace->getUserSpaceRole($id_space, $id_user);
        return $spaceRole;
    }

    /**
     *
     * @param int $id_space
     * @param int $id_user
     * @return boolean
     * @throws Exception
     */
    public function checkSpaceAdmin($id_space, $id_user)
    {
        $modelUser = new CoreUser();
        $userAppStatus = $modelUser->getStatus($id_user);
        if ($userAppStatus > CoreStatus::$USER) {
            return true;
        }
        $modelSpace = new CoreSpace();
        $spaceRole = $modelSpace->getUserSpaceRole($id_space, $id_user);
        if ($spaceRole < CoreSpace::$ADMIN) {
            throw new PfmAuthException("Error 403: Permission denied", 403);
        }
    }

    /**
     *
     * @param int $id_space
     * @param int $id_user
     * @return boolean
     */
    public function isSpaceAdmin($id_space, $id_user)
    {
        $modelUser = new CoreUser();
        $userAppStatus = $modelUser->getStatus($id_user);
        if ($userAppStatus > CoreStatus::$USER) {
            return true;
        }
        $modelSpace = new CoreSpace();
        $spaceRole = $modelSpace->getUserSpaceRole($id_space, $id_user);
        if ($spaceRole < CoreSpace::$ADMIN) {
            return false;
        }
        return true;
    }
}
