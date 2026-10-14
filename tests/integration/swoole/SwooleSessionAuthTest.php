<?php

namespace KanboardTests\integration\swoole;

use PHPUnit\Framework\TestCase;

/**
 * Real-HTTP session, authentication and isolation checks on one worker.
 * Every test builds its own jars and fixtures, so file order is free.
 */
class SwooleSessionAuthTest extends TestCase
{
    private $http;

    protected function setUp(): void
    {
        $this->http = new SwooleHttpClient(getenv('SWOOLE_BASE_URL') ?: 'http://127.0.0.1:18080');

        if (! $this->http->isContainerReachable()) {
            $this->markTestSkipped('Swoole container not reachable');
        }
    }

    public function testLoginFailureThenSuccessOnSameWorker()
    {
        $jar = 'login-flow';
        $page = $this->http->jarRequest($jar, 'GET', '/?controller=AuthController&action=login');
        $this->assertSame(200, $page['status']);

        $denied = $this->http->loginPost($jar, 'admin', 'wrong-password', false);
        $this->assertSame(200, $denied['status']);
        $this->assertStringContainsString('login', strtolower($denied['body']));

        $accepted = $this->http->loginPost($jar, 'admin', 'admin', false);
        $this->assertSame(302, $accepted['status']);
    }

    public function testLoginRotatesSessionIdAndLogoutDestroysIt()
    {
        $jar = 'rotation';
        $this->http->jarRequest($jar, 'GET', '/?controller=AuthController&action=login');
        $anonymousId = $this->http->jarValue($jar, 'KB_SID');
        $this->assertNotEmpty($anonymousId);

        $login = $this->http->loginPost($jar, 'admin', 'admin', false);
        $this->assertSame(302, $login['status']);
        $this->assertNotSame($anonymousId, $this->http->jarValue($jar, 'KB_SID'));

        $dashboard = $this->http->jarRequest($jar, 'GET', '/dashboard/1');
        $this->assertSame(200, $dashboard['status']);

        $csrf = $this->http->extractCsrfToken($dashboard['body']);
        $this->assertNotEmpty($csrf);
        $logout = $this->http->jarRequest($jar, 'GET', '/?controller=AuthController&action=logout&csrf_token='.$csrf);
        $this->assertSame(302, $logout['status']);
        $this->assertNull($this->http->jarValue($jar, 'KB_SID'));

        $after = $this->http->jarRequest($jar, 'GET', '/dashboard/1');
        $this->assertSame(302, $after['status']);
    }

    public function testAlternatingUsersStayIsolatedOnSameWorker()
    {
        $this->http->apiCall('createUser', array('swoole-user-alt', 'swoole-pass'));

        $adminJar = 'admin-user';
        $this->http->loginPost($adminJar, 'admin', 'admin', false);
        $adminPage = $this->http->jarRequest($adminJar, 'GET', '/dashboard/1');
        $this->assertSame(200, $adminPage['status']);

        $userJar = 'second-user';
        $this->http->loginPost($userJar, 'swoole-user-alt', 'swoole-pass', false);
        $userPage = $this->http->jarRequest($userJar, 'GET', '/dashboard/1');
        $this->assertSame(200, $userPage['status']);

        $this->assertNotSame($this->http->jarValue($adminJar, 'KB_SID'), $this->http->jarValue($userJar, 'KB_SID'));

        $adminAgain = $this->http->jarRequest($adminJar, 'GET', '/dashboard/1');
        $this->assertSame(200, $adminAgain['status']);
        $this->assertStringContainsString('admin', strtolower($adminAgain['body']));
        $this->assertStringContainsString('swoole-user-alt', strtolower($userPage['body']));
        $this->assertStringNotContainsString('swoole-user-alt', strtolower($adminAgain['body']));
    }

    public function testMalformedSessionCookieIsReplaced()
    {
        $exchange = $this->http->request('GET', '/dashboard/1', array('Cookie: KB_SID=!!!not-an-id!!!'));

        $this->assertSame(302, $exchange['status']);

        $freshId = null;

        foreach ($exchange['setCookies'] as $setCookie) {
            $parts = explode(';', $setCookie);
            list($cookieName, $value) = array_pad(explode('=', trim($parts[0]), 2), 2, '');

            if ($cookieName === 'KB_SID') {
                $freshId = $value;
            }
        }

        $this->assertNotEmpty($freshId);
        $this->assertNotSame('!!!not-an-id!!!', $freshId);
    }

    public function testFixationCookieIdDoesNotSurviveLogin()
    {
        $jar = 'fixation';
        $this->http->jarRequest($jar, 'GET', '/?controller=AuthController&action=login', array(), null, array('KB_SID' => 'fixedattackerid'));
        $login = $this->http->loginPost($jar, 'admin', 'admin', false);

        $this->assertSame(302, $login['status']);
        $this->assertNotSame('fixedattackerid', $this->http->jarValue($jar, 'KB_SID'));
    }

    public function testRememberMeAuthenticatesWithoutSession()
    {
        $jar = 'remember';
        $this->http->loginPost($jar, 'admin', 'admin', true);

        // Transports URL-encode cookie values on the wire; decode first.
        $rememberValue = urldecode($this->http->jarValue($jar, 'KB_RM'));
        $this->assertNotEmpty($rememberValue);
        $this->assertStringContainsString('|', $rememberValue);

        $dashboard = $this->http->jarRequest('remember-bare', 'GET', '/dashboard/1', array(), null, array('KB_RM' => $rememberValue));

        $this->assertSame(200, $dashboard['status']);
    }

    public function testLanguageStaysScopedPerUserWithTimezoneStored()
    {
        $this->http->apiCall('createUser', array('swoole-user-lang', 'swoole-pass'));
        $userId = $this->http->apiCall('getUserByName', array('swoole-user-lang'))['result']['id'];

        $adminJar = 'lang-admin';
        $this->http->loginPost($adminJar, 'admin', 'admin', false);

        $editForm = $this->http->jarRequest($adminJar, 'GET', '/?controller=UserModificationController&action=show&user_id='.$userId);
        $this->assertSame(200, $editForm['status']);
        $update = $this->http->jarRequest(
            $adminJar,
            'POST',
            '/?controller=UserModificationController&action=save&user_id='.$userId,
            array('Content-Type: application/x-www-form-urlencoded'),
            'id='.$userId.'&username=swoole-user-lang&language='.urlencode('fr_FR').'&timezone='.urlencode('America/New_York')
                .'&csrf_token='.urlencode($this->http->extractCsrfToken($editForm['body']))
        );
        $this->assertSame(302, $update['status']);

        $stored = $this->http->apiCall('getUserByName', array('swoole-user-lang'));
        $this->assertSame('fr_FR', $stored['result']['language']);
        $this->assertSame('America/New_York', $stored['result']['timezone']);

        $userJar = 'lang-user';
        $this->http->loginPost($userJar, 'swoole-user-lang', 'swoole-pass', false);
        $frenchPage = $this->http->jarRequest($userJar, 'GET', '/dashboard/1');
        $this->assertSame(200, $frenchPage['status']);
        $this->assertStringContainsString('Tableau de bord', $frenchPage['body']);

        $englishPage = $this->http->jarRequest($adminJar, 'GET', '/dashboard/1');
        $this->assertSame(200, $englishPage['status']);
        $this->assertStringNotContainsString('Tableau de bord', $englishPage['body']);
    }
}
