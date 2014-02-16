<?php
/**
 * A hierarchical users and rights system plugin for Pico.
 *
 * @author  Philipp Schmitt
 * @link    http://lxl.io
 * @link    http://philecms.github.io/Phile
 * @license http://opensource.org/licenses/MIT
 */
class PhileUsers extends \Phile\Plugin\AbstractPlugin implements \Phile\EventObserverInterface {

    private $user;
    private $users;
    private $rights;
    private $base_url;
    private $hash_type;

    private $config;

    public function __construct() {
        \Phile\Event::registerEvent('config_loaded', $this);
        \Phile\Event::registerEvent('before_render_template', $this);
        \Phile\Event::registerEvent('request_uri', $this);
        $this->config = \Phile\Registry::get('Phile_Settings');
    }

    public function on($eventKey, $data = null) {
        if ($eventKey == 'config_loaded') {
            $this->config_loaded($data);
        } elseif ($eventKey == 'request_uri') {
            $this->request_uri($data['uri']);
        } elseif ($eventKey == 'before_render_template') {
            $this->export_twig_vars();
        }
        // TODO call getPages() somewhere
    }

    /**
     * Store settings and define the current user.
     */
    private function config_loaded(&$data) {
        // merge the arrays to bind the settings to the view
        // Note: this->config takes precedence
        $this->config = array_merge($this->settings, $this->config);

        if (isset($this->config['base_url'])) {
            $this->base_url = $this->config['base_url'];
        }
        if (isset($this->config['users'])) {
            $this->users = $this->config['users'];
        }
        if (isset($this->config['rights'])) {
            $this->rights = $this->config['rights'];
        }
        if (isset($this->config['hash_type'])
            && in_array($this->config['hash_type'], hash_algos())) {
            $this->hash_type = $this->config['hash_type'];
        } else {
            $this->hash_type = 'sha512';
        }

        $this->user = '';
        $this->check_login();
    }

    /**
     * If the requested url is unauthorized for the current user
     * display page "403" and send 403 headers.
     */
    private function request_uri(&$uri) {
        // If requesting 403 send an actual 403 response
        if ($uri == '/403') {
            header('HTTP/1.1 403 Forbidden');
            return;
        }
        $page_url = rtrim($uri, '/');
        if (!$this->is_authorized($this->base_url . $page_url)) {
            // Redirect to 403 page (content/403)
            $uri = '/403';
            header('location:' . $this->base_url . $uri);
        }
    }

    /**
     * Filter the list of pages according to rights of current user.
     */
    private function get_pages(&$pages, &$current_page, &$prev_page, &$next_page) {
        // get sorted list of urls, for :
        // TODO prev_page & next_page as prev and next allowed pages
        $pages_urls = array();
        foreach ($pages as $p) {
            $pages_urls[] = $p['url'];
        }
        asort($pages_urls);

        foreach ($pages_urls as $page_id => $page_url ) {
            if (!$this->is_authorized(rtrim($page_url, '/'))) {
                unset($pages[$page_id]);
            }
        }
    }
    /**
     * Register a basic login form and user path in Twig variables.
     */
    private function export_twig_vars() {
        if (\Phile\Registry::isRegistered('templateVars')) {
            $twig_vars = \Phile\Registry::get('templateVars');
        } else {
            $twig_vars = array();
        }
        $twig_vars['login_form'] = $this->html_form();
        $twig_vars['user'] = $this->user;
        \Phile\Registry::set('templateVars', $twig_vars);
    }


    // CORE ---------------

    /*
     * Check logout/login actions and session login.
     */
    private function check_login() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $fp = $this->fingerprint();

        // to sanitize?
        $username = isset($_POST['username']) ? $_POST['username'] : null;
        $password = isset($_POST['password']) ? $_POST['password'] : null;

        // logout action
        if (isset($_POST['logout'])) {
            unset($_SESSION[$fp]);
            return;
        }

        // login action
        if (isset($username)
                && isset($password)) {
            return $this->login($username, $password, $fp);
        }

        // session login (already logged)

        if (!isset($_SESSION[$fp])) return;

        $name = $_SESSION[$fp]['username'];
        $pass = $_SESSION[$fp]['password'];

        $logged = $this->login($name, $pass, $fp);
        if ($logged) return true;

        unset($_SESSION[$fp]);
        return;
    }

    /**
     * Return session fingerprint hash.
     * @return string
     */
    private function fingerprint() {
        return hash($this->hash_type, 'phile'
                .$_SERVER['HTTP_USER_AGENT']
                .$_SERVER['REMOTE_ADDR']
                .$_SERVER['SCRIPT_NAME']
                .session_id());
    }

    /**
     * Try to login with the given name and password.
     * @param string $name the login name
     * @param string $pass the login password
     * @param string $fp session fingerprint hash
     * @return boolean operation result
     */
    private function login($name, $pass, $fp) {
        $users = $this->search_users($name, hash($this->hash_type, $pass));
        if (!$users) return false;
        // register
        $this->user = $users[0];
        $_SESSION[$fp]['username'] = $name;
        $_SESSION[$fp]['password'] = $pass;
        return true;
    }

    /*
     * Return a simple login / logout form.
     */
    private function html_form() {
        if (!$this->user) return '
            <form method="post" action="" class="login_form">
                <input type="text" name="username" placeholder="Username" autofocus required/>
                <input type="password" name="password" placeholder="Password" required />
                <input type="submit" value="Sign in" />
            </form>';

        return basename($this->user) . ' (' . dirname($this->user) . ')
            <form method="post" action="" class="logout_form">
                <input type="submit" value="logout" />
            </form>';
    }

    /**
     * Return a list of users and passwords from the configuration file,
     * corresponding to the given user name.
     * @param  string $name  the user name, like "username"
     * @param  string $pass  the user password hash (hash)
     * @return array        the list of results in pairs "path/group/username" => "hash"
     */
    private function search_users($name, $pass = null, $users = null, $path = '') {
        if (!$users) $users = $this->users;
        if ($path) $path .= '/';
        $results = array();
        foreach ($users as $key => $val) {
            if (is_array($val)) {
                $results = array_merge(
                        $results,
                        $this->search_users($name, $pass, $val, $path.$key)
                        );
                continue;
            }
            if (($name === null || $name === $key )
                    && ($pass === null || $pass === $val )) {
                $results[] = $path.$name;
            }
        }

        return $results;
    }

    /**
     * Return if the user is allowed to see the given page url.
     * @param  string  $url a page url
     * @return boolean
     */
    private function is_authorized($url) {
        if (!$this->rights) return true;
        foreach ($this->rights as $auth_path => $auth_user) {
            // url is concerned by this rule and user is not (unauthorized)
            if ($this->is_parent_path($this->base_url.'/'.$auth_path, $url)) {
                if (!$this->is_parent_path($auth_user, $this->user)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Return if a path is parent of another.
     *     some/path is parent of some/path/child
     *  some/path is not parent of some/another/path
     * @param  string  $parent the parent (shorter) path
     * @param  string  $child  the child (longer) path
     * @return boolean
     */
    private static function is_parent_path($parent, $child) {
        if (!$parent || !$child) return false;
        if ($parent == $child) return true;

        if (strpos($child, $parent) === 0) {
            if (substr($parent,-1) == '/') return true;
            elseif ($child[strlen($parent)] == '/') return true;
        }
        return false;
    }
}
?>
