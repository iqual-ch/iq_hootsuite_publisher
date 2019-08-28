<?php

namespace Drupal\iq_hootsuite_publisher\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\httpClient;

//use Google_Client;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
   * http client.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   An instance of ConfigFactory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   LoggerChannelFactoryInterface.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Cache Backend.
   */
  private $http_client;

  public function __construct(ConfigFactory $config,
                              LoggerChannelFactoryInterface $loggerFactory,
                              CacheBackendInterface $cacheBackend,
                              httpClient $http_client) {
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

  public function createAuthUrl() {
    $params = array(
      'response_type' =>  'code',
      'client_id'     =>  $this->config->get('client_id'),
      'redirect_uri'  =>  'http'.($_SERVER['HTTP_HOST'] ? 's' : '').':'.$_SERVER['HTTP_HOST'].'/iq_hootsuite_publisher/callback',
    );
    return $this->config->get('url_auth_endpoint').'?'.http_build_query($params);
  }

  public function getAccessTokenByAuthCode($code) {



    // $token = $this->OAuth2Client->getAccessToken('authorization_code', [
    //     'code' => $code
    // ]);

    //   print_r( $token );

    //   die();

    // if ( $token->getToken() ) {
    //   $this->setTokenCache('access_token', $token->getToken() );
    // }

    // // Refresh token is only set the first time.
    // if ( $token->getRefreshToken() ) {
    //   $this->setTokenCache('refresh_token', $token->getRefreshToken() );
    // }

    return $token;
  }


  public function connect($method, $endpoint, $query, $body) {
    try {
      $response = $this->http_client->{$method}(
        $this->base_uri . $endpoint,
        $this->buildOptions($query, $body)
      );
    }
    catch (RequestException $exception) {
      drupal_set_message(t('Failed to complete Planning Center Task "%error"', ['%error' => $exception->getMessage()]), 'error');
      \Drupal::logger('pco_api')->error('Failed to complete Planning Center Task "%error"', ['%error' => $exception->getMessage()]);
      return FALSE;
    }
    $headers = $response->getHeaders();
    $this->throttle($headers);
    // TODO: Possibly allow returning the whole body.
    return $response->getBody()->getContents();
  }

  private function setTokenCache($key, array $value) {
    // Save the token.
    $this->configTokens
      ->set($key, $value)
      ->save();

    return TRUE;
  }


}
