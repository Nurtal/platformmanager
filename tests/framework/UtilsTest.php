<?php

require_once 'Framework/Utils.php';
require_once 'Framework/Errors.php';

use PHPUnit\Framework\TestCase;

/**
 * Pure helpers on Utils, exercised without HTTP or database.
 */
class UtilsTest extends TestCase
{
    public function testInternalPathPassesThrough()
    {
        $this->assertEquals('coretiles', Utils::safeRedirectPath('coretiles'));
        $this->assertEquals('/booking/1', Utils::safeRedirectPath('/booking/1'));
        $this->assertEquals('spaceadmin/2', Utils::safeRedirectPath('spaceadmin/2'));
    }

    public function testInternalPathKeepsItsQueryString()
    {
        $this->assertEquals(
            '/booking/1?id_resource=2&view=day',
            Utils::safeRedirectPath('/booking/1?id_resource=2&view=day')
        );
        $this->assertEquals('coretiles?tab=2', Utils::safeRedirectPath('coretiles?tab=2'));
    }

    public function testNoDestinationCollapsesToTheDefault()
    {
        $this->assertEquals('', Utils::safeRedirectPath(''));
        $this->assertEquals('', Utils::safeRedirectPath('   '));
    }

    public function testExternalDestinationIsRefused()
    {
        $this->assertEquals('', Utils::safeRedirectPath('http://evil.example.org/steal'));
        $this->assertEquals('', Utils::safeRedirectPath('https://evil.example.org/steal'));
        // protocol-relative: the browser would resolve this against the current scheme
        $this->assertEquals('', Utils::safeRedirectPath('//evil.example.org/steal'));
        $this->assertEquals('', Utils::safeRedirectPath('/\\evil.example.org/steal'));
        $this->assertEquals('', Utils::safeRedirectPath('\\\\evil.example.org/steal'));
    }

    public function testNonHttpSchemeIsRefused()
    {
        $this->assertEquals('', Utils::safeRedirectPath('javascript:alert(1)'));
        $this->assertEquals('', Utils::safeRedirectPath('data:text/html;base64,PHNjcmlwdD4='));
        $this->assertEquals('', Utils::safeRedirectPath('mailto:someone@example.org'));
    }

    public function testMarkupIsRefused()
    {
        $this->assertEquals('', Utils::safeRedirectPath('"><script>alert(1)</script>'));
        $this->assertEquals('', Utils::safeRedirectPath("' onmouseover='alert(1)"));
        $this->assertEquals('', Utils::safeRedirectPath('coretiles"><img src=x onerror=alert(1)>'));
    }

    public function testControlCharactersAreRefused()
    {
        $this->assertEquals('', Utils::safeRedirectPath("coretiles\nLocation: http://evil.example.org"));
        $this->assertEquals('', Utils::safeRedirectPath("coretiles\r\n"));
        $this->assertEquals('', Utils::safeRedirectPath("core\ttiles"));
        $this->assertEquals('', Utils::safeRedirectPath("coretiles\0"));
    }

    public function testNonStringDestinationIsRefused()
    {
        // ?redirect_url[]=x reaches the controller as an array
        $this->assertEquals('', Utils::safeRedirectPath(['coretiles']));
        $this->assertEquals('coretiles', Utils::safeRedirectPath(null, 'coretiles'));
    }

    public function testCallerSuppliedDefaultIsUsedWhenRefused()
    {
        $this->assertEquals('coretiles', Utils::safeRedirectPath('http://evil.example.org', 'coretiles'));
        $this->assertEquals('coretiles', Utils::safeRedirectPath('', 'coretiles'));
    }
}
