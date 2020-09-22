<?php

namespace Drupal\iq_hootsuite_publisher\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Class Hootsuite API Client Service.
 *
 * @package Drupal\iq_hootsuite_publisher\Service
 */
class HootsuiteAPIClient {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Uneditable Config.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * Cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $cacheBackend;

  /**
   * Editable Tokens Config.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  private $configTokens;

  /**
   * Callback Controller constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   An instance of ConfigFactory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   LoggerChannelFactoryInterface.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Cache Backend.
   */

  /**
   * Http client.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   An instance of ConfigFactory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   LoggerChannelFactoryInterface.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Cache Backend.
   */
  private $http_client;

  /**
   *
   */
  public function __construct(ConfigFactory $config,
        LoggerChannelFactoryInterface $loggerFactory,
        CacheBackendInterface $cacheBackend,
        ClientInterface $http_client) {
    $this->config = $config->get('iq_hootsuite_publisher.settings');
    $this->configTokens = $config->getEditable('iq_hootsuite_publisher.tokens');

    $this->loggerFactory = $loggerFactory;
    $this->cacheBackend = $cacheBackend;

    // // Add the client without tokens.
    $this->http_client = $http_client;

    // // Check and add tokens.
    // // Tokens wont always be set or valid, so this is a 2 step process.
    // $this->setAccessToken();
  }

  /**
   *
   */
  public function createAuthUrl() {
    $params = [
      'response_type' => 'code',
      'client_id' => $this->config->get('client_id'),
      'redirect_uri' => 'http' . ($_SERVER['HTTP_HOST'] ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/iq_hootsuite_publisher/callback',
      'scope' => 'offline',
    ];
    return $this->config->get('url_auth_endpoint') . '?' . http_build_query($params);
  }

  /**
   *
   */
  public function getAccessTokenByAuthCode($code = NULL) {
    if ($code != NULL) {

      $request_options = [
        RequestOptions::HEADERS => [
          'Authorization' => 'Basic ' . base64_encode($this->config->get('client_id') . ':' . $this->config->get('client_secret')),
          'Accept' => '*/*',
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        RequestOptions::FORM_PARAMS => [
          'code' => $code,
          'redirect_uri' => 'http' . ($_SERVER['HTTP_HOST'] ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/iq_hootsuite_publisher/callback',
          'grant_type' => 'authorization_code',
          'scope' => 'offline',
        ],
      ];
      try {
        $token = $this->http_client->request('POST',
              $this->config->get('url_token_endpoint'), $request_options);
      }
      catch (\Exception $e) {
        \Drupal::logger('iq_hootsuite_publisher')->error('Could not acquire token due to "%error"', ['%error' => $exception->getMessage()]);
        drupal_set_message(t('Could not acquire token due to "%error"', ['%error' => $exception->getMessage()]), 'error');
        return FALSE;
      }
      $response = json_decode($token->getBody(), TRUE);
      if ($token->getStatusCode() == 200 && isset($response['access_token'])) {
        $this->configTokens->set('access_token', $response['access_token']);
        $this->configTokens->set('refresh_token', $response['refresh_token']);
        $this->configTokens->save();

        return TRUE;
      }
    }
    elseif (!empty($this->configTokens->get('refresh_token'))) {

      $request_options = [
        RequestOptions::HEADERS => [
          'Authorization' => 'Basic ' . base64_encode($this->config->get('client_id') . ':' . $this->config->get('client_secret')),
          'Accept' => '*/*',
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        RequestOptions::FORM_PARAMS => [
          'refresh_token' => $this->configTokens->get('refresh_token'),
          'redirect_uri' => 'http' . ($_SERVER['HTTP_HOST'] ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/iq_hootsuite_publisher/callback',
          'grant_type' => 'refresh_token',
          'scope' => 'offline',
        ],
      ];
      // Refresh token.
      try {
        $token = $this->http_client->request('post', $this->config->get('url_token_endpoint'), $request_options);
      }
      catch (\Exception $e) {
        \Drupal::logger('iq_hootsuite_publisher')->error('Could not refresh token due to "%error"', ['%error' => $e->getMessage()]);
        drupal_set_message(t('Could not refresh token due to "%error"', ['%error' => $e->getMessage()]), 'error');
        return FALSE;
      }
      $response = json_decode($token->getBody(), TRUE);
      if ($token->getStatusCode() == 200 && $response['access_token']) {
        $this->configTokens->set('access_token', $response['access_token']);
        $this->configTokens->set('refresh_token', $response['refresh_token']);
        $this->configTokens->save();
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   *
   */
  public function connect($method, $endpoint, $query = NULL, $body = NULL) {

    $accessToken = $this->configTokens->get('access_token');
    $request_options = [
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Accept' => '*/*',
      ],
    ];
    if (!empty($body)) {
      $data = json_encode($body);
      $request_options[RequestOptions::BODY] = $data;
    }
    if (!empty($query)) {
      $request_options[RequestOptions::QUERY] = $query;
    }
    try {
      $response = $this->http_client->{$method}(
            $endpoint,
            $request_options
        );
    }
    catch (\Exception $exception) {
      if (strpos($exception->getMessage(), "400 Bad Request") !== FALSE) {
        \Drupal::logger('iq_hootsuite_publisher')->error('Failed to complete taks "%method" with error "%error"', ['%method' => $method, '%error' => $exception->getMessage()]);
        return FALSE;
      }
      if (strpos($exception->getMessage(), "401 Unauthorized") !== FALSE) {
        // Refresh token and resend request.
        if ($this->getAccessTokenByAuthCode()) {
          return $this->connect($method, $endpoint, $query, $body);
        }
      }
      \Drupal::logger('iq_hootsuite_publisher')->error('Failed to complete Planning Center Task "%error"', ['%error' => $exception->getMessage()]);
      return FALSE;
    }

    // Token expired.
    if ($response->getStatusCode() == 400 || $response->getStatusCode() == 401 || $response->getStatusCode() == 403) {
      // Refresh token and resend request.
      if ($this->getAccessTokenByAuthCode()) {
        return $this->connect($method, $endpoint, $query, $body);
      }
    }
    // TODO: Possibly allow returning the whole body.
    return $response->getBody();
  }

  /**
   *
   */
  private function setTokenCache($key, array $value) {
    // Save the token.
    $this->configTokens
      ->set($key, $value)
      ->save();

    return TRUE;
  }

}
