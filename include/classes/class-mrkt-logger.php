<?php

/**
 * markight logger class
 * used this class for log job starting time and exceptions
 * @class markight
 * @since 1.1.0
 */
class Mrkt_Markight_logger
{

    /**
     * site Domain name
     * @var string
     * @since 1.2.0
     */
    private string $siteName;

    /**
     * api token
     * @var string
     * @since 1.3.0
     */
    private string $token;

    public function __construct()
    {
        $parse = parse_url(get_site_url());
        $this->siteName = $parse['host'];
        $this->token = get_option(MRKT_PLUGIN_NAME . '_token')??'';
    }

    /**
     * send log data to remote panel
     * @since 1.1.0
     */
    private function log(array $logData)
    {
        add_filter('https_ssl_verify', '__return_false');

        $logData['Id'] = 0;
        $logData['ProjectTitle'] = $this->siteName;
        $logData['ErrorAmount'] = $logData['ErrorAmount'] ?? 0;
        $logData['RecordAmount'] = $logData['RecordAmount'] ?? 0;
        $logData['LastUpdateDate'] = (new DateTime())->format('Y-m-d\TH:i:s');

        wp_remote_post(esc_url_raw("https://reports.markight.com/Log/AddLogModules"), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Token' => $this->token
            ],
            'body' => json_encode($logData),
            'timeout' => 5,
            'method' => 'POST'
        ]);
    }

    /**
     * ping endpoint
     * @since 1.2.0
     */
    public function ping()
    {
        $this->log(['EntityName' => "Ping", 'ErrorDetail' => "Sync Job started"]);
    }

    /**
     * log exception to log endpoint
     * @since 1.2.0
     */
    public function exception($message)
    {
        $this->log(['EntityName' => "SyncException", 'ErrorDetail' => $message]);
    }

    /**
     * ping log endpoint with 0 error or new data
     * @since 1.2.0
     */
    public function emptyData()
    {
        $this->log(['EntityName' => "Invoice", 'ErrorDetail' => "no new or updated data available for sync"]);
    }

    /**
     * log order count and error count
     * @since 1.2.0
     */
    public function synced(array $errors, $syncCount, string $message = '')
    {
        $this->log([
            'EntityName' => "Invoice",
            'ErrorAmount' => count($errors),
            'RecordAmount' => $syncCount - count($errors),
            'ErrorDetail' => json_encode($errors) . " Message => " . $message
        ]);
    }
}