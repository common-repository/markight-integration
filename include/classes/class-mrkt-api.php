<?php

/**
 * markight api class
 * used this class for called markight api
 * @class markight
 * @since 1.1.0
 */
class Mrkt_Markight_api
{

    /**
     * markight api token
     * @var string
     * @since 1.2.0
     */
    private string $token;

    /**
     * markight api base url
     * @var string
     * @since 1.2.0
     */
    private string $baseUrl;

    /**
     * markight api endpoints
     * @var array
     * @since 1.2.0
     */
    private array $endpoints = [
        'token' => 'users/authenticate/',
        'order' => 'data-entry/flat-invoice/'
    ];

    public function __construct()
    {
        $this->token = get_option(MRKT_PLUGIN_NAME . '_token');
        $this->baseUrl = get_option(MRKT_PLUGIN_NAME . '_api_url');
    }

    /**
     * api request based on wp_remote_post
     * @param $post
     * encoded json string for request body
     * @param string $endpoint
     * @return array
     * return request response and error
     * @since 1.2.0
     */
    public function apiRequest($post, string $endpoint = "order"): array
    {

        add_filter('https_ssl_verify', '__return_false');

        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $post,
            'timeout' => 10,
            'method' => 'POST'
        ];

        if (!empty($this->token)) {
            $args['headers']['Authorization'] = "Token " . $this->token;
        }

        $url = $this->baseUrl . $this->endpoints[$endpoint];
        $response = wp_remote_post(esc_url_raw($url), $args);
        $http_response_code = wp_remote_retrieve_response_code($response);
        $res = wp_remote_retrieve_body($response);
        $err = is_wp_error($response) ? $response->get_error_message() : '';

        return ['http_code' => $http_response_code, 'result' => $res, 'error' => $err];
    }

    /**
     * get api response and return true if response code is 200 or 201
     * @param array $result
     * @return bool
     * @since 1.2.0
     */
    public function isSuccess(array $result): bool
    {
        return in_array($result['http_code'], [201, 200, 202]);
    }

    /**
     * get api response and return true if response code not in range 200 until 400
     * @param array $result
     * @return bool
     * @since 1.2.0
     */
    public function isServerError(array $result): bool
    {
        return !in_array($result['http_code'], [201, 202, 200, 401]);
    }

    /**
     * get api response and return true if request is unauthorized
     * @param array $result
     * @return bool
     * @since 1.2.0
     */
    public function isUnauthorized(array $result): bool
    {

        if (isset($result['http_code'])) {
            return $result['http_code'] == 401;
        }

        return $result['unauthorized'] == true;
    }

    /**
     * send data to markight in bulk mode
     * every time bulk mode request failed this function chunked input array to two array and retry
     * @param $data
     * @return array
     * @since 1.2.0
     */
    public function sendItems($data): array
    {
        $errors = [];
        $result = $this->apiRequest(json_encode($data));

        if ($this->isSuccess($result)) {
            return ['unauthorized' => false, 'errors' => $errors, 'response' => $result['result']];
        }

        if ($this->isUnauthorized($result)) {
            return ['unauthorized' => true, 'errors' => $errors];
        }

        $chunked = array_chunk($data, ceil(count($data) / 2));

        $task_ids = [];

        foreach ($chunked as $newList) {

            $result = $this->apiRequest(json_encode($newList));

            if ($this->isSuccess($result)) {
                array_push($task_ids, $result['result']);
                continue;
            }

            $newChunked = array_chunk($newList, ceil(count($newList) / 2));

            foreach ($newChunked as $chunkedList) {

                $result = $this->apiRequest(json_encode($chunkedList));
                if ($this->isSuccess($result)) {
                    array_push($task_ids, $result['result']);
                    continue;
                }

                foreach ($chunkedList as $item) {

                    $result = $this->apiRequest(json_encode([$item]));
                    if ($this->isSuccess($result)) {
                        array_push($task_ids, $result['result']);
                        continue;
                    }

                    array_push($errors, "('{$item['item_id']}','" . json_encode($result) . "','" . json_encode($item) . "')");

                }
            }
        }
        return ['unauthorized' => false, 'errors' => $errors, 'response' => $task_ids];
    }
}