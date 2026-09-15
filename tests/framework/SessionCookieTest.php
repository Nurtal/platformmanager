<?php

require_once 'Framework/Session.php';

use PHPUnit\Framework\TestCase;

/**
 * The attributes the session cookie must carry, as a pure function of the
 * request environment. What actually reaches the browser is asserted against a
 * running instance by bin/check-deployment.sh.
 */
class SessionCookieTest extends TestCase
{
    public function testTheCookieIsAlwaysHiddenFromPageScripts()
    {
        $options = Session::secureCookieOptions([]);

        $this->assertTrue($options['httponly']);
    }

    public function testTheCookieIsAlwaysRestrictedOnCrossSiteNavigations()
    {
        $options = Session::secureCookieOptions([]);

        $this->assertEquals('Lax', $options['samesite']);
    }

    public function testOverPlainHttpTheCookieIsNotTransportRestricted()
    {
        // the supplied container stack serves plain HTTP; marking the cookie
        // Secure there would make it unusable on a laptop
        $this->assertFalse(Session::secureCookieOptions([])['secure']);
        $this->assertFalse(Session::secureCookieOptions(['HTTPS' => 'off'])['secure']);
        $this->assertFalse(Session::secureCookieOptions(['HTTPS' => ''])['secure']);
        $this->assertFalse(Session::secureCookieOptions(['SERVER_PORT' => '80'])['secure']);
    }

    public function testOverDirectHttpsTheCookieIsTransportRestricted()
    {
        $this->assertTrue(Session::secureCookieOptions(['HTTPS' => 'on'])['secure']);
        $this->assertTrue(Session::secureCookieOptions(['HTTPS' => 'On'])['secure']);
        $this->assertTrue(Session::secureCookieOptions(['SERVER_PORT' => '443'])['secure']);
    }

    public function testBehindAProxyForwardingHttpsTheCookieIsTransportRestricted()
    {
        $this->assertTrue(Session::secureCookieOptions(['HTTP_X_FORWARDED_PROTO' => 'https'])['secure']);
        // a chain of proxies appends to the header; the client-facing one comes first
        $this->assertTrue(Session::secureCookieOptions(['HTTP_X_FORWARDED_PROTO' => 'https, http'])['secure']);
        $this->assertTrue(Session::secureCookieOptions(['HTTP_X_FORWARDED_SSL' => 'on'])['secure']);
    }

    public function testAProxyForwardingPlainHttpLeavesTheCookieUnrestricted()
    {
        $this->assertFalse(Session::secureCookieOptions(['HTTP_X_FORWARDED_PROTO' => 'http'])['secure']);
        $this->assertFalse(Session::secureCookieOptions(['HTTP_X_FORWARDED_SSL' => 'off'])['secure']);
    }
}
