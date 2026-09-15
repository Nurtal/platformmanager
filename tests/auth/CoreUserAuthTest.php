<?php

require_once 'tests/BaseTest.php';

require_once 'Framework/Errors.php';
require_once 'Modules/core/Model/CoreUser.php';

/**
 * Local authentication through the CoreUser model, without HTTP.
 *
 * No test covered authentication before the hardening pass; these are the first.
 */
class CoreUserAuthTest extends BaseTest
{
    private CoreUser $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new CoreUser();
    }

    /**
     * An account as it existed before bcrypt was introduced: an MD5 hash and the
     * matching algorithm marker. Written straight to the table, because the model
     * deliberately offers no way to create one today.
     */
    private function legacyMd5Account(string $login, string $pwd): array
    {
        $id = $this->users->add($login, $pwd, $login, $login, $login.'@pfm.org', CoreStatus::$USER, '', 1);
        $db = CoreUser::getDatabase();
        $stmt = $db->prepare('UPDATE core_users SET pwd=?, hash=? WHERE id=?');
        $stmt->execute([md5($pwd), CoreUser::$HASH_MD5, $id]);
        return $this->users->getUser($id);
    }

    private function bcryptAccount(string $login, string $pwd, int $isActive = 1): array
    {
        $id = $this->users->add($login, $pwd, $login, $login, $login.'@pfm.org', CoreStatus::$USER, '', $isActive);
        return $this->users->getUser($id);
    }

    public function testMd5AccountSignsInWithItsExistingPassword()
    {
        $this->legacyMd5Account('legacy1', 'legacypwd1');

        $this->assertTrue($this->users->connect('legacy1', 'legacypwd1'));
    }

    public function testMd5AccountIsUpgradedToBcryptOnSignIn()
    {
        $user = $this->legacyMd5Account('legacy2', 'legacypwd2');
        $this->assertEquals(CoreUser::$HASH_MD5, intval($user['hash']));
        $this->assertEquals(md5('legacypwd2'), $user['pwd']);

        $this->users->connect('legacy2', 'legacypwd2');

        $upgraded = $this->users->getUser($user['id']);
        $this->assertEquals(CoreUser::$HASH_BCRYPT, intval($upgraded['hash']));
        $this->assertNotEquals(md5('legacypwd2'), $upgraded['pwd']);
        $this->assertTrue(password_verify('legacypwd2', $upgraded['pwd']));
    }

    public function testUpgradedAccountSignsInAgainWithTheSamePassword()
    {
        $this->legacyMd5Account('legacy3', 'legacypwd3');

        $this->users->connect('legacy3', 'legacypwd3');

        $this->assertTrue($this->users->connect('legacy3', 'legacypwd3'));
    }

    public function testBcryptAccountIsLeftAlone()
    {
        $user = $this->bcryptAccount('modern1', 'modernpwd1');

        $this->users->connect('modern1', 'modernpwd1');

        $after = $this->users->getUser($user['id']);
        $this->assertEquals($user['pwd'], $after['pwd']);
        $this->assertEquals(CoreUser::$HASH_BCRYPT, intval($after['hash']));
    }

    public function testWrongPasswordIsRefusedOnAnMd5Account()
    {
        $user = $this->legacyMd5Account('legacy4', 'legacypwd4');

        try {
            $this->users->connect('legacy4', 'wrong');
            $this->fail('expected the wrong password to be refused');
        } catch (PfmAuthException $e) {
            $this->assertEquals(strval(CoreUser::$CNX_WRONG_PWD), $e->getMessage());
        }

        // and a refused attempt must not touch the stored hash
        $after = $this->users->getUser($user['id']);
        $this->assertEquals(md5('legacypwd4'), $after['pwd']);
        $this->assertEquals(CoreUser::$HASH_MD5, intval($after['hash']));
    }

    public function testWrongPasswordIsRefusedOnABcryptAccount()
    {
        $this->bcryptAccount('modern2', 'modernpwd2');

        $this->expectException(PfmAuthException::class);
        $this->expectExceptionMessage(strval(CoreUser::$CNX_WRONG_PWD));
        $this->users->connect('modern2', 'wrong');
    }

    public function testInactiveAccountIsRefused()
    {
        $this->bcryptAccount('inactive1', 'inactivepwd1', 0);

        $this->expectException(PfmAuthException::class);
        $this->expectExceptionMessage(strval(CoreUser::$CNX_INACTIVE));
        $this->users->connect('inactive1', 'inactivepwd1');
    }

    public function testUnknownLoginIsRefused()
    {
        $this->expectException(PfmAuthException::class);
        $this->expectExceptionMessage(strval(CoreUser::$CNX_INVALID_LOGIN));
        $this->users->connect('nosuchuser', 'whatever');
    }

    public function testMd5AccountIsStillUpgradedWhenTheAccountIsInactive()
    {
        $user = $this->legacyMd5Account('legacy5', 'legacypwd5');
        $db = CoreUser::getDatabase();
        $db->prepare('UPDATE core_users SET is_active=0 WHERE id=?')->execute([$user['id']]);

        try {
            $this->users->connect('legacy5', 'legacypwd5');
        } catch (PfmAuthException $e) {
            $this->assertEquals(strval(CoreUser::$CNX_INACTIVE), $e->getMessage());
        }

        $after = $this->users->getUser($user['id']);
        $this->assertEquals(CoreUser::$HASH_BCRYPT, intval($after['hash']));
        $this->assertTrue(password_verify('legacypwd5', $after['pwd']));
    }
}
