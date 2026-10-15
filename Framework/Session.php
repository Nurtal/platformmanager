<?php

/**
 * Class modeling a session.
 * Use the PHP variable $_SESSION.
 *
 * @author Sylvain Prigent
 */
class Session
{
    /**
     * Constructor.
     * Start or restor the session
     */
    public function __construct()
    {
        if (!isset($_SESSION)) {
            self::configureCookie();
            session_start();
        }
    }

    /**
     * Attributes the session cookie must carry, for a given request environment.
     *
     * HttpOnly and SameSite are unconditional. The transport restriction is applied
     * only when the request is genuinely served over HTTPS: applying it always would
     * make the supplied container stack, which serves plain HTTP, unusable.
     *
     * Pure function of $_SERVER, so the rule can be checked without a session.
     *
     * @param array $server request environment, normally $_SERVER
     * @return array options for session_set_cookie_params()
     */
    public static function secureCookieOptions(array $server): array
    {
        return [
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isRequestSecure($server),
        ];
    }

    /**
     * Apply the cookie attributes. Must run before the session starts; once one is
     * active the parameters are already baked into the cookie that was sent.
     */
    public static function configureCookie(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_set_cookie_params(
            array_merge(session_get_cookie_params(), self::secureCookieOptions($_SERVER))
        );
    }

    /**
     * Whether the request reached the User over HTTPS, either directly or through
     * what the reverse proxy in front of the application forwards.
     */
    private static function isRequestSecure(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower($server['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($server['HTTP_X_FORWARDED_PROTO'])) {
            // a chain of proxies appends to the header; the client-facing one comes first
            $proto = trim(explode(',', $server['HTTP_X_FORWARDED_PROTO'])[0]);
            if (strtolower($proto) === 'https') {
                return true;
            }
        }
        if (!empty($server['HTTP_X_FORWARDED_SSL']) && strtolower($server['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        if (isset($server['SERVER_PORT']) && intval($server['SERVER_PORT']) === 443) {
            return true;
        }
        return false;
    }

    /**
     * Destroy the session
     */
    public function destroy()
    {
        session_destroy();
    }

    /**
     * Add an attribut to the session
     *
     * @param string $name Name of the attribut
     * @param string $value Value of the attribut
     */
    public function setAttribut($name, $value)
    {
        $_SESSION[$name] = $value;
    }

    /**
     * Return true is the attribut exists in the session
     *
     * @param string $name Name of the attribut
     * @return bool True is the attribut exists and is not empty
     */
    public function isAttribut($name)
    {
        return (isset($_SESSION[$name]) && $_SESSION[$name] != "");
    }

    /**
     * Return the value of an attribut
     *
     * @param string $name Name of the attribut
     * @return string Value of the attribut
     */
    public function getAttribut($name)
    {
        if ($this->isAttribut($name)) {
            return $_SESSION[$name];
        } else {
            return "";
        }
    }

    public function unset($name)
    {
        unset($_SESSION[$name]);
    }
}
