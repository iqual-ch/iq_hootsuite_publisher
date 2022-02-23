<?php

namespace Drupal\iq_hootsuite_publisher\Service;

use Drupal\Core\Messenger\Messenger;
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
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Uneditable Config.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * Editable Tokens Config.
   *
   * @var \Drupal\Core\Config\Config
   */
  private $configTokens;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * Create a new instance.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The http client.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The messenger.
   */
  public function __construct(ConfigFactory $config,
        LoggerChannelFactoryInterface $loggerFactory,
        ClientInterface $http_client,
        Messenger $messenger) {
    $this->config = $config->get('iq_hootsuite_publisher.settings');
    $this->configTokens = $config->getEditable('iq_hootsuite_publisher.tokens');
    $this->logger = $loggerFactory->get('iq_hootsuite_publisher');
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
  }

  /**
   * Create the authentication url based on config.
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
   * Get and save access tokens.
   *
   * @param string $code
   *   The code (optional).
   */
  public function getAccessTokenByAuthCode(string $code = NULL) {
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
        $token = $this->httpClient->request('POST',
              $this->config->get('url_token_endpoint'), $request_options);
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Could not acquire token due to "%error"',
          ['%error' => $e->getMessage()]
        );
        $this->messenger->addError(
          t(
            'Could not acquire token due to "%error"',
            ['%error' => $e->getMessage()]
          )
        );
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
        $token = $this->httpClient->request('post', $this->config->get('url_token_endpoint'), $request_options);
      }
      catch (\Exception $e) {
        $this->logger->error('Could not refresh token due to "%error"', ['%error' => $e->getMessage()]);
        $this->messenger->addError(t('Could not refresh token due to "%error"', ['%error' => $e->getMessage()]));
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
   * Connect to api to send or retrieve data.
   *
   * @param string $method
   *   The method to use (get, post, put etc.)
   * @param string $endpoint
   *   The endpoint to call.
   * @param string $query
   *   The query parameters to send (optional).
   * @param array $body
   *   The body to send (optional).
   */
  public function connect(string $method, string $endpoint, string $query = NULL, array $body = NULL) {

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
      $response = $this->httpClient->{$method}(
            $endpoint,
            $request_options
        );
    }
    catch (\Exception $exception) {
      if (strpos($exception->getMessage(), "400 Bad Request") !== FALSE) {
        $this->logger->error(
          'Failed to complete taks "%method" with error "%error"',
          [
            '%method' => $method,
            '%error' => $exception->getMessage(),
          ]
        );
        return FALSE;
      }
      if (strpos($exception->getMessage(), "401 Unauthorized") !== FALSE) {
        // Refresh token and resend request.
        if ($this->getAccessTokenByAuthCode()) {
          return $this->connect($method, $endpoint, $query, $body);
        }
      }
      $this->logger->error('Failed to complete Planning Center Task "%error"', ['%error' => $exception->getMessage()]);
      return FALSE;
    }

    // Token expired.
    if ($response->getStatusCode() == 400 || $response->getStatusCode() == 401 || $response->getStatusCode() == 403) {
      // Refresh token and resend request.
      if ($this->getAccessTokenByAuthCode()) {
        return $this->connect($method, $endpoint, $query, $body);
      }
    }
    // @todo Possibly allow returning the whole body.
    return $response->getBody();
  }

}
