<?php

namespace QUI\Cron;

use QUI;
use QUI\System\Log;

use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function curl_setopt_array;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function mkdir;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function urlencode;

class CronService
{
    private string $domain;
    private bool $https;
    private string $packageDir;
    private string $baseUrl;

    /**
     * CronService constructor.
     * @throws \QUI\Exception
     */
    public function __construct()
    {
        $host = '';
        $cms_dir = '';
        $opt_dir = '';
        $url_dir = '';

        if (QUI::$Conf) {
            $host = (string)QUI::$Conf->get("globals", "host");
            $cms_dir = (string)QUI::$Conf->get("globals", "cms_dir");
            $opt_dir = (string)QUI::$Conf->get("globals", "opt_dir");
            $url_dir = (string)QUI::$Conf->get("globals", "url_dir");
        }

        // VHost Domain
        $vhost = '';
        $Standard = QUI::getProjectManager()->getStandard();

        if ($Standard) {
            $standardVhost = $Standard->getVHost(true, true);

            if (is_string($standardVhost)) {
                $vhost = $standardVhost;
            }
        }

        // Check if https should be used.
        if (str_starts_with($vhost, 'https://')) {
            $this->https = true;
        } else {
            $this->https = false;
        }

        $this->domain = str_replace("https://", "", $vhost);

        // Read the domain from the config file if no vhost could be detected.
        if (empty($vhost)) {
            // Parse Domain and protocol
            if (str_contains($host, "https://")) {
                $this->https = true;
                $this->domain = str_replace("https://", "", $host);
            } elseif (str_contains($host, "http://")) {
                $this->https = false;
                $this->domain = str_replace("http://", "", $host);
            } else {
                $this->https = false;
                $this->domain = $host;
            }
        }

        // Parse Package dir
        $this->packageDir = $url_dir . str_replace($cms_dir, "", $opt_dir);

        $config = QUI::getPackage('quiqqer/cron')->getConfig();
        $baseUrl = '';

        if ($config) {
            $configuredBaseUrl = $config->get('cronservice', 'base_url');

            if (is_string($configuredBaseUrl)) {
                $baseUrl = $configuredBaseUrl;
            }
        }

        $this->baseUrl = !empty($baseUrl) ? rtrim($baseUrl, '/') : 'https://cron.quiqqer.com';
    }

    /**
     * Will register this quiqqer instance.
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    public function register(string $email): void
    {
        $this->sendRegistrationRequest($this->domain, $email, $this->packageDir, $this->https);
    }

    /**
     * Gets the status of the given domain.
     *
     * Return format :
     * array(
     *       'status'           => 0,  (0=unregistered; 1=active; 2=inactive)
     *       'current_failures' => int,
     *       'total_failures'   => int,
     *       'last_execution'   => string (mysql dateformat | Localized 'never')
     * )
     *
     * @return mixed
     * @throws \QUI\Exception
     */
    public function getStatus(): mixed
    {
        $status = $this->makeServerAjaxCall('package_pcsg_cronservice_ajax_getStatus', [
            'domain' => $this->domain
        ]);

        if (empty($status['last_execution'])) {
            $status['last_execution'] = QUI::getLocale()->get(
                'quiqqer/cron',
                'cron.window.cronservice.status.text.last_execution.never'
            );
        }


        # Get local last execution
        $CronManager = new Manager();
        $history = $CronManager->getHistoryList();


        $status['last_local_execution'] = QUI::getLocale()->get(
            'quiqqer/cron',
            'cron.window.cronservice.status.text.last_execution.never'
        );

        if (empty($history) || !isset($history[0])) {
            return $status;
        }

        if (empty($history[0]['lastexec'])) {
            return $status;
        }

        $lastLocalExecution = $history[0]['lastexec'];
        $status['last_local_execution'] = $lastLocalExecution;

        return $status;
    }

    /**
     * Revoked the registration for this quiqqer instance
     * @throws \QUI\Exception
     */
    public function revokeRegistration(): void
    {
        $token = $this->readRevokeToken();

        $this->makeServerAjaxCall('package_pcsg_cronservice_ajax_revokeRegistration', [
            'domain' => $this->domain,
            'token' => $token
        ]);

        $Config = QUI::getPackage("quiqqer/cron")->getConfig();

        if ($Config) {
            $Config->set("settings", "executeOnAdminLogin", 1);
            $Config->save();
        }
    }

    /**
     * Requests the cronservice to resend the activation email
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    public function resendActivationMail(): void
    {
        if (empty($this->domain)) {
            throw new Exception("Could not get the instances domain.");
        }

        $this->makeServerAjaxCall(
            "package_pcsg_cronservice_ajax_resendActivationMail",
            [
                "domain" => $this->domain
            ]
        );
    }

    /**
     * Attempts to cancel the registration on the server
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    public function cancelRegistration(): void
    {
        if (empty($this->domain)) {
            throw new Exception("Could not get the instances domain.");
        }

        $this->makeServerAjaxCall(
            "package_pcsg_cronservice_ajax_cancelRegistration",
            [
                "domain" => $this->domain
            ]
        );

        $Config = QUI::getPackage("quiqqer/cron")->getConfig();

        if ($Config) {
            $Config->set("settings", "executeOnAdminLogin", 1);
            $Config->save();
        }
    }

    /**
     * Sends an ajax request to the cron service server.
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    private function sendRegistrationRequest(string $domain, string $email, string $packageDir, bool $https): void
    {
        if (empty($domain)) {
            throw new CronServiceException(["quiqqer/cron", "exception.registration.empty.domain"]);
        }

        if (empty($email)) {
            throw new CronServiceException(["quiqqer/cron", "exception.registration.empty.email"]);
        }

        if (empty($packageDir)) {
            throw new CronServiceException(["quiqqer/cron", "exception.registration.empty.packageDir"]);
        }

        $url = $this->baseUrl . "/admin/ajax.php?" .
            "_rf=" . urlencode("[\"package_pcsg_cronservice_ajax_register\"]") .
            "&package=" . urlencode("pcsg/cronservice") .
            "&lang=" . QUI::getUserBySession()->getLang() .
            "&domain=" . urlencode($domain) .
            "&email=" . urlencode($email) .
            "&packageDir=" . urlencode($packageDir) .
            "&https=" . ($https ? "1" : "0") .
            "&user=" . QUI::getUserBySession()->getName();

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'QUIQQER'
        ]);

        if (isset($_SERVER['SERVER_ADDR'])) {
            curl_setopt($curl, CURLOPT_INTERFACE, $_SERVER['SERVER_ADDR']);
        }

        $response = curl_exec($curl);

        if (!is_string($response)) {
            curl_close($curl);
            throw new Exception("Could not contact cron service.");
        }

        $response = substr($response, 9, -10);
        $data = json_decode($response, true);

        if (json_last_error() != JSON_ERROR_NONE) {
            Log::addDebug($response);
            throw new Exception(json_last_error_msg());
        }

        if (!isset($data['package_pcsg_cronservice_ajax_register']['result'])) {
            Log::addDebug($response);
            Log::writeRecursive($data, Log::LEVEL_ERROR);
            throw new Exception("Something went wrong!");
        }

        $data = $data['package_pcsg_cronservice_ajax_register']['result'];

        if (!isset($data['status']) || $data['status'] != 1) {
            Log::addDebug($response);
            Log::writeRecursive($data, Log::LEVEL_ERROR);
            if (isset($data['message'])) {
                throw new Exception($data['message']);
            }

            throw new Exception("Something went wrong!");
        }

        if (!isset($data['revokeCode']) || !is_string($data['revokeCode'])) {
            throw new Exception("Missing revoke code.");
        }

        $revokeCode = $data['revokeCode'];
        $this->saveRevokeToken($revokeCode);

        curl_close($curl);

        $Config = QUI::getPackage("quiqqer/cron")->getConfig();

        if ($Config) {
            $Config->set("settings", "executeOnAdminLogin", 0);
            $Config->save();
        }
    }

    /**
     * Calls the given ajax function on the Cron service server and returns its output
     *
     * @param array<string, scalar> $params
     * @return mixed
     * @throws QUI\Exception
     */
    private function makeServerAjaxCall(string $function, array $params): mixed
    {
        $url = $this->baseUrl . "/admin/ajax.php?" .
            "_rf=" . urlencode('["' . $function . '"]') .
            "&package=" . urlencode("pcsg/cronservice") .
            "&lang=" . QUI::getUserBySession()->getLang();

        foreach ($params as $param => $value) {
            $url .= '&' . $param . '=' . urlencode((string)$value);
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'QUIQQER'
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        if (!is_string($response)) {
            throw new QUI\Exception('Could not contact cron service.');
        }

        // Process raw ajax response
        $response = substr($response, 9, -10);
        $response = json_decode($response, true);

        if (!is_array($response)) {
            throw new QUI\Exception('Invalid cron service response.');
        }

        if (isset($response[$function]['Exception'])) {
            throw new QUI\Exception($response[$function]['Exception']['message']);
        }

        return $response[$function]['result'];
    }

    /**
     * Saves the revoke token into a file
     *
     * @throws \QUI\Exception
     */
    private function saveRevokeToken(string $token): void
    {
        $varDir = QUI::getPackage('quiqqer/cron')->getVarDir() . '/cronservice';
        $fileName = $varDir . '/.revoketoken';

        if (!is_dir($varDir)) {
            mkdir($varDir, 0700, true);
        }

        file_put_contents($fileName, $token);
    }

    /**
     * Reads the revoke token from the filesystem
     *
     * @return string
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    private function readRevokeToken(): string
    {
        $varDir = QUI::getPackage('quiqqer/cron')->getVarDir() . '/cronservice';
        $fileName = $varDir . '/.revoketoken';

        if (!file_exists($fileName)) {
            throw new Exception("Tokenfile not present");
        }

        $token = file_get_contents($fileName);

        if ($token === false) {
            throw new Exception("Could not read tokenfile.");
        }

        return $token;
    }
}
