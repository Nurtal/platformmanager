<?php

require_once 'tests/BaseTest.php';

require_once 'Framework/Errors.php';
require_once 'Modules/core/Model/CoreUser.php';

/**
 * "Remember me" token issue and verification through the CoreUser model,
 * without HTTP.
 */
class CoreUserRememberMeTest extends BaseTest
{
    private CoreUser $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new CoreUser();
    }

    private function account(string $login): int
    {
        return intval(
            $this->users->add($login, 'pwd-'.$login, $login, $login, $login.'@pfm.org', CoreStatus::$USER, '', 1)
        );
    }

    private function storedToken(int $id): ?string
    {
        $db = CoreUser::getDatabase();
        $stmt = $db->prepare('SELECT remember_key FROM core_users WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $row['remember_key'] : null;
    }

    public function testAFreshlyIssuedTokenVerifies()
    {
        $id = $this->account('remember1');

        $token = $this->users->issueRememberToken($id);

        $this->assertTrue($this->users->verifyRememberToken($id, $token));
    }

    public function testTokenIsLongAndUnpredictable()
    {
        $id = $this->account('remember2');

        $first = $this->users->issueRememberToken($id);
        $second = $this->users->issueRememberToken($id);

        // 32 random bytes, hex encoded
        $this->assertEquals(64, strlen($first));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertNotEquals($first, $second);
    }

    public function testATamperedTokenDoesNotVerify()
    {
        $id = $this->account('remember3');
        $token = $this->users->issueRememberToken($id);

        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

        $this->assertFalse($this->users->verifyRememberToken($id, $tampered));
        $this->assertFalse($this->users->verifyRememberToken($id, ''));
        $this->assertFalse($this->users->verifyRememberToken($id, 'not-a-token'));
    }

    public function testWhatIsStoredIsNotWhatTheBrowserHolds()
    {
        $id = $this->account('remember4');

        $token = $this->users->issueRememberToken($id);

        $stored = $this->storedToken($id);
        $this->assertNotEmpty($stored);
        $this->assertNotEquals($token, $stored);
        // and the stored form is useless on its own: presenting it fails verification
        $this->assertFalse($this->users->verifyRememberToken($id, $stored));
    }

    public function testIssuingAgainInvalidatesThePreviousToken()
    {
        $id = $this->account('remember5');
        $first = $this->users->issueRememberToken($id);

        $second = $this->users->issueRememberToken($id);

        $this->assertFalse($this->users->verifyRememberToken($id, $first));
        $this->assertTrue($this->users->verifyRememberToken($id, $second));
    }

    public function testClearingInvalidatesTheToken()
    {
        $id = $this->account('remember6');
        $token = $this->users->issueRememberToken($id);

        $this->users->clearRememberToken($id);

        $this->assertFalse($this->users->verifyRememberToken($id, $token));
        $this->assertEmpty($this->storedToken($id));
    }

    public function testAnAccountWithNoTokenVerifiesNothing()
    {
        $id = $this->account('remember7');

        $this->assertFalse($this->users->verifyRememberToken($id, ''));
        $this->assertFalse($this->users->verifyRememberToken($id, str_repeat('a', 64)));
    }

    public function testAnUnknownAccountVerifiesNothing()
    {
        $this->assertFalse($this->users->verifyRememberToken(999999, str_repeat('a', 64)));
    }

    public function testTokensIssuedBeforeThisChangeNoLongerVerify()
    {
        // one-off cost of the change: an 8-character key stored in the clear
        $id = $this->account('remember8');
        $legacy = hash('sha512', 'abcd1234');
        $db = CoreUser::getDatabase();
        $db->prepare('UPDATE core_users SET remember_key=? WHERE id=?')->execute([$legacy, $id]);

        $this->assertFalse($this->users->verifyRememberToken($id, $legacy));
    }
}
