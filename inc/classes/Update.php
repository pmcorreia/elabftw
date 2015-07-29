<?php
/**
 * \Elabftw\Elabftw\Update
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see http://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
namespace Elabftw\Elabftw;

use \Exception;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \FilesystemIterator;

/**
 * Use this to check for latest version or update the database schema
 */
class Update
{
    /** 1.1.4 */
    private $version;
    /** the url line from the updates.ini file with link to archive */
    protected $url;
    /** sha512sum of the archive we should expect */
    protected $sha512;

    /** our favorite pdo object */
    private $pdo;

    /** this is used to check if we managed to get a version or not */
    public $success = false;

    /** where to get info from */
    const URL = 'https://get.elabftw.net/updates.ini';
    /** if we can't connect in https for some reason, use http */
    const URL_HTTP = 'http://get.elabftw.net/updates.ini';

    /**
     * ////////////////////////////
     * UPDATE THIS AFTER RELEASING
     * ///////////////////////////
     */
    const INSTALLED_VERSION = '1.1.5';

    /**
     * /////////////////////////////////////////////////////
     * UPDATE THIS AFTER ADDING A BLOCK TO runUpdateScript()
     * /////////////////////////////////////////////////////
     */
    const REQUIRED_SCHEMA = '3';

    /**
     * Create the pdo object
     *
     */
    public function __construct()
    {
        $this->pdo = Db::getConnection();
    }

    /**
     * Make a get request with cURL, using proxy setting if any
     *
     * @param string $url URL to hit
     * @param bool|string $toFile path where we want to save the file
     * @return string|boolean Return true if the download succeeded, else false
     */
    protected function get($url, $toFile = false)
    {
        if (!extension_loaded('curl')) {
            throw new Exception('Please install php5-curl package.');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        // this is to get content
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // add proxy if there is one
        if (strlen(get_config('proxy')) > 0) {
            curl_setopt($ch, CURLOPT_PROXY, get_config('proxy'));
        }
        // disable certificate check
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // add user agent
        // http://developer.github.com/v3/#user-agent-required
        curl_setopt($ch, CURLOPT_USERAGENT, "Elabftw/" . self::INSTALLED_VERSION);

        // add a timeout, because if you need proxy, but don't have it, it will mess up things
        // 5 seconds
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        // we don't want the header
        curl_setopt($ch, CURLOPT_HEADER, 0);

        if ($toFile) {
            $handle = fopen($toFile, 'w');
            curl_setopt($ch, CURLOPT_FILE, $handle);
        }

        // DO IT!
        return curl_exec($ch);
    }

    /**
     * Return the latest version of elabftw
     * Will fetch updates.ini file from elabftw.net
     *
     * @throws Exception the version we have doesn't look like one
     * @return string|bool|null latest version or false if error
     */
    public function getUpdatesIni()
    {
        $ini = self::get(self::URL);
        // try with http if https failed (see #176)
        if (!$ini) {
            $ini = self::get(self::URL_HTTP);
        }
        // convert ini into array. The `true` is for process_sections: to get multidimensionnal array.
        $versions = parse_ini_string($ini, true);
        // get the latest version (first item in array, an array itself with url and checksum)
        $this->version = array_keys($versions)[0];
        $this->sha512 = substr($versions[$this->version]['sha512'], 0, 128);
        $this->url = $versions[$this->version]['url'];

        if (!$this->validateVersion()) {
            throw new Exception('Error getting latest version information from server!');
        }
        $this->success = true;
    }

    /**
     * Check if the version string actually looks like a version
     *
     * @return int 1 if version match
     */
    private function validateVersion()
    {
        return preg_match('/[0-99]+\.[0-99]+\.[0-99]+.*/', $this->version);
    }

    /**
     * Return true if there is a new version out there
     *
     * @return bool
     */
    public function updateIsAvailable()
    {
        return self::INSTALLED_VERSION != $this->version;
    }

    /**
     * Return the latest version string
     *
     * @return string|int 1.1.4
     */
    public function getLatestVersion()
    {
        return $this->version;
    }

    /**
     * Update the database schema if needed.
     *
     * @return string[] $msg_arr
     */
    public function runUpdateScript()
    {
        // 20150727
        $this->schema2();
        // 20150728
        $this->schema3();

        // place new schema functions above this comment
        $this->updateSchema();
        $this->cleanTmp();
        $msg_arr = array();
        $msg_arr[] = "[SUCCESS] You are now running the latest version of eLabFTW. Have a great day! :)";
        return $msg_arr;
    }

    /**
     * Delete things in the tmp folder
     */
    private function cleanTmp()
    {
        // cleanup files in tmp
        $dir = ELAB_ROOT . '/uploads/tmp';
        $di = new \RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }
    }

    /**
     * Update the schema value in config to latest because we did the update functions before
     *
     */
    private function updateSchema()
    {
        $config_arr = array('schema' => self::REQUIRED_SCHEMA);
        if (!update_config($config_arr)) {
            throw new Exception('Failed at updating the schema!');
        }
    }

    /**
     * Add a default value to deletable_xp.
     * Can't do the same for link_href and link_name because they are text
     *
     * @throws Exception if there is a problem
     */
    private function schema2()
    {
        $sql = "ALTER TABLE teams CHANGE deletable_xp deletable_xp TINYINT(1) NOT NULL DEFAULT '1'";
        if (!$this->pdo->q($sql)) {
            throw new Exception('Problem updating!');
        }
    }

    /**
     * Change the experiments_revisions structure to allow code reuse
     *
     * @throws Exception if there is a problem
     */
    private function schema3()
    {
        $sql = "ALTER TABLE experiments_revisions CHANGE exp_id item_id INT(10) UNSIGNED NOT NULL";
        if (!$this->pdo->q($sql)) {
            throw new Exception('Problem updating!');
        }
    }
}