<?php

namespace SkyfishIntegration;

class Api
{
    public $token = '';
    public $url = 'https://api.colourbox.com';
    public $login, $password, $key, $secret;

    public function authenticate()
    {
        if (!empty($this->token)) {
            return true;
        }

        if (get_option('skyfish_authorised') && get_option('skyfish_authorised') == true && !empty(get_option('skyfish_credentials'))) {
            $credentials = get_option('skyfish_credentials');
            $encryptedFields = \SkyfishIntegration\Admin\AuthPage::$sensetiveAuthFormFields;

            //Decrypt sensetive fields
            foreach ($encryptedFields as $key) {
                $credentials[$key] = \SkyfishIntegration\Admin\AuthPage::decrypt($credentials[$key]);
            }

            $this->login = $credentials['skyfish_login'];
            $this->password = $credentials['skyfish_password'];
            $this->key = $credentials['api_key'];
            $this->secret = $credentials['api_secret'];
            $this->token = $this->generateToken();

            return true;
        }

        return false;
    }

    public function isValidToken($tokenString)
    {
        $request = json_decode($this->request('get', $this->url . '/authenticate/simpletoken/' . $tokenString));
        if (isset($request->TokenStatus) && $request->TokenStatus === 'valid') {
            return true;
        }

        return false;
    }

    public function generateToken()
    {
        if (get_transient('skyfishToken')) {
            //Validate token
            if ($this->isValidToken(get_transient('skyfishToken'))) {
                return get_transient('skyfishToken');
            }

            //Remove invalid token and generate new
            delete_transient('skyfishToken');
            $this->generateToken();
        }

        $params = array(
            'username' => $this->login,
            'password' => $this->password,
            'key' => $this->key,
            'ts' => time(),
            'hmac' => $this->generateHash()
        );

        $request = $this->request('post', $this->url . '/authenticate/userpasshmac', $params);
        $request = (is_string($request) && !empty($request)) ? json_decode($request) : false;

        if (is_object($request) && isset($request->token) && !empty($request->token)) {
            set_transient('skyfishToken', $request->token, 30 * DAY_IN_SECONDS);
        } else {
            return false;
        }

        return $this->generateToken();
    }

    public function getFolders()
    {
        if (!$this->token) {
            return;
        }

        $request = $this->request('get', $this->url . '/folder', null, 'json', ['Authorization: CBX-SIMPLE-TOKEN Token=' . $this->token]);
        $request = (is_string($request) && !empty($request)) ? json_decode($request) : false;

        return $request;
    }

    public function generateHash()
    {
        return hash_hmac('sha1', $this->key . ":" . time(), $this->secret);
    }

    public function request($type, $url, $data = null, $contentType = 'json', $headers = null)
    {
        //Arguments are stored here
        $arguments = null;

        switch (strtoupper($type)) {
            /**
             * Method: GET
             */
            case 'GET':
                // Append $data as querystring to $url
                if (is_array($data)) {
                    $url .= '?' . http_build_query($data);
                }

                // Set curl options for GET
                $arguments = array(
                    CURLOPT_RETURNTRANSFER      => true,
                    CURLOPT_HEADER              => false,
                    CURLOPT_FOLLOWLOCATION      => true,
                    CURLOPT_SSL_VERIFYPEER      => false,
                    CURLOPT_SSL_VERIFYHOST      => false,
                    CURLOPT_URL                 => $url,
                    CURLOPT_CONNECTTIMEOUT_MS  => 1500
                );

                break;

            /**
             * Method: POST
             */
            case 'POST':
                // Set curl options for POST
                $arguments = array(
                    CURLOPT_RETURNTRANSFER      => 1,
                    CURLOPT_URL                 => $url,
                    CURLOPT_POST                => 1,
                    CURLOPT_HEADER              => false,
                    CURLOPT_CONNECTTIMEOUT_MS   => 3000
                );

                if (in_array($contentType, array("json", "jsonp"))) {
                    $arguments[CURLOPT_POSTFIELDS] = json_encode($data);
                } else {
                    $arguments[CURLOPT_POSTFIELDS] = http_build_query($data) ;
                }

                break;
        }

        /**
         * Set up headers if given
         */
        if ($headers) {
            $arguments[CURLOPT_HTTPHEADER] = $headers;
        }

        /**
         * Do the actual curl
         */
        $ch = curl_init();
        curl_setopt_array($ch, $arguments);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response = curl_exec($ch);
        curl_close($ch);

        /**
         * Return the response
         */
        return $response;
    }
}

