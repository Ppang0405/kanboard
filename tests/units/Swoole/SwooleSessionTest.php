<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Swoole\Bootstrap;
use Kanboard\Swoole\ContainerFactory;
use Kanboard\Swoole\SwooleSession;
use PHPUnit\Framework\TestCase;
use SessionHandlerInterface;

class SwooleSessionTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::initializeOnce();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id('');
        $_SESSION = array();
    }

    private function findCookieOrNull(array $cookies, $name)
    {
        foreach ($cookies as $cookie) {
            if ($cookie['name'] === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function testBindAdoptsWellFormedIncomingId()
    {
        SwooleSession::bind(array('KB_SID' => 'abc123XYZ'));

        $this->assertSame('abc123XYZ', session_id());
        $this->assertSame(array(), $_SESSION);
    }

    public function testBindRejectsMalformedIdWithoutWarning()
    {
        SwooleSession::bind(array('KB_SID' => '!!!not-an-id!!!'));

        $this->assertSame('', session_id());
    }

    public function testSessionRoundTripAcrossRequests()
    {
        $container = ContainerFactory::create();

        SwooleSession::bind(array());
        $container['sessionManager']->open();
        $_SESSION['user'] = array('id' => 1, 'username' => 'admin');
        $cookies = SwooleSession::finalize(array(), array());

        $sessionCookie = $this->findCookie($cookies, 'KB_SID');
        $this->assertNotEmpty($sessionCookie['value']);
        $issuedId = $sessionCookie['value'];

        SwooleSession::bind(array('KB_SID' => $issuedId));
        $container['sessionManager']->open();

        $this->assertSame(1, $_SESSION['user']['id']);

        SwooleSession::bind(array());
        $container['sessionManager']->open();

        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testUnknownCookieIdStartsEmptyAndRotatesOnLogin()
    {
        $container = ContainerFactory::create();

        SwooleSession::bind(array('KB_SID' => 'unknown-id-zzz'));
        $container['sessionManager']->open();

        // The storage layer reports missing sessions as empty (not as a
        // read failure), so strict mode cannot reject the id here. The
        // guarantee is isolation plus rotation at login, not rejection.
        $this->assertArrayNotHasKey('user', $_SESSION);

        session_regenerate_id(false);
        $_SESSION['user'] = array('id' => 5, 'username' => 'attacker-target');
        $cookies = SwooleSession::finalize(array('KB_SID' => 'unknown-id-zzz'), array());

        $sessionCookie = $this->findCookie($cookies, 'KB_SID');
        $this->assertNotSame('unknown-id-zzz', $sessionCookie['value']);
        $this->assertNotEmpty($sessionCookie['value']);
    }

    public function testRegeneratedIdIsEmitted()
    {
        $container = ContainerFactory::create();

        SwooleSession::bind(array());
        $container['sessionManager']->open();
        $firstId = session_id();
        session_regenerate_id(false);
        $secondId = session_id();
        $cookies = SwooleSession::finalize(array(), array());

        $sessionCookie = $this->findCookie($cookies, 'KB_SID');
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame($secondId, $sessionCookie['value']);
    }

    public function testDestroyedSessionEmitsDeletionCookie()
    {
        $container = ContainerFactory::create();

        SwooleSession::bind(array());
        $container['sessionManager']->open();
        $_SESSION['user'] = array('id' => 1);
        $loginCookies = SwooleSession::finalize(array(), array());
        $issuedId = $this->findCookie($loginCookies, 'KB_SID')['value'];

        SwooleSession::bind(array('KB_SID' => $issuedId));
        $container['sessionManager']->open();
        $this->assertSame(1, $_SESSION['user']['id']);
        $container['sessionManager']->close();
        // The router attaches this listener per request; the unit call
        // below stands in for that wiring.
        SwooleSession::markDestroyed();

        $cookies = SwooleSession::finalize(array('KB_SID' => $issuedId), array());
        $sessionCookie = $this->findCookie($cookies, 'KB_SID');

        $this->assertSame('', $sessionCookie['value']);
        $this->assertLessThan(time(), $sessionCookie['expires']);
    }

    public function testNeverStartedSessionEmitsNoSessionCookieButForwardsRememberMe()
    {
        $pending = array(
            array('name' => 'KB_RM', 'value' => 'token-abc|seq-123', 'expires' => time() + 3600, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => ''),
        );

        // No session was ever started and none was destroyed: not even an
        // incoming id may produce a session or deletion cookie.
        SwooleSession::bind(array('KB_SID' => 'some-previous-id'));
        $cookies = SwooleSession::finalize(array('KB_SID' => 'some-previous-id'), $pending);

        $this->assertNull($this->findCookieOrNull($cookies, 'KB_SID'));
        $this->assertSame('token-abc|seq-123', $this->findCookie($cookies, 'KB_RM')['value']);

        // Same when there is no incoming id at all.
        SwooleSession::bind(array());
        $cookies = SwooleSession::finalize(array(), $pending);

        $this->assertNull($this->findCookieOrNull($cookies, 'KB_SID'));
        $this->assertCount(1, $cookies);
    }

    public function testActiveSessionCookieUsesAbsoluteExpiry()
    {
        // Cookie parameters cannot change while a session is active, so
        // set a positive lifetime before starting the session directly.
        SwooleSession::bind(array());
        session_set_cookie_params(3600, '/', '', false, true);
        session_name('KB_SID');
        session_start();
        $_SESSION['user'] = array('id' => 1);

        // A positive lifetime is a duration: the emitted expiry must be
        // an absolute timestamp, not the raw duration (which would read
        // as already expired in 1970).
        $before = time();
        $cookies = SwooleSession::finalize(array(), array());
        $sessionCookie = $this->findCookie($cookies, 'KB_SID');

        $this->assertGreaterThanOrEqual($before + 3600, $sessionCookie['expires']);
        $this->assertLessThanOrEqual(time() + 3600, $sessionCookie['expires']);

        session_set_cookie_params(0);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testRememberMeOutboxIsForwardedWithEncodingIntact()
    {
        // Isolated process: the legacy RememberMeCookieTest file declares a
        // namespaced setcookie() override that is absent here, so the
        // global setcookie() no-op applies and only the outbox is observed.
        // Bootstrap runs first because no earlier suite test defined the
        // application constants in this process.
        Bootstrap::initializeOnce();
        $container = ContainerFactory::create();
        SwooleSession::bind(array());
        $container['sessionManager']->open();

        /** @var RememberMeCookie $rememberMe */
        $rememberMe = $container['rememberMeCookie'];
        $rememberMe->write('token-abc', 'seq-123', time() + 3600);
        $cookies = SwooleSession::finalize(array(), $rememberMe->getPendingCookies());

        $rememberCookie = $this->findCookie($cookies, 'KB_RM');
        $this->assertSame('token-abc|seq-123', $rememberCookie['value']);
        $this->assertGreaterThan(time(), $rememberCookie['expires']);
    }

    public function testFinalizeSignalsWriteFailureWithoutSessionCookie()
    {
        SwooleSession::bind(array());
        session_set_save_handler(new FailingSessionSaveHandler(), true);
        session_start();
        $_SESSION['user'] = array('id' => 1);

        $this->assertFalse(SwooleSession::finalize(array(), array()));
    }

    private function findCookie(array $cookies, $name)
    {
        foreach ($cookies as $cookie) {
            if ($cookie['name'] === $name) {
                return $cookie;
            }
        }

        $this->fail('Cookie not emitted: '.$name);
    }
}

class FailingSessionSaveHandler implements SessionHandlerInterface
{
    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string|false
    {
        return '';
    }

    public function write($id, $data): bool
    {
        return false;
    }

    public function destroy($id): bool
    {
        return true;
    }

    public function gc($maxLifetime): int|false
    {
        return 0;
    }
}
