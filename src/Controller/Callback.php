<?php

namespace Drupal\iq_hootsuite_publisher\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\iq_hootsuite_publisher\Service\HootsuiteAPIClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Google Client Callback Controller.
 *
 * @package Drupal\iq_hootsuite_publisher\Controller
 */
class Callback extends ControllerBase {

  /**
   * Google API Client.
   *
   * @var \Drupal\iq_hootsuite_publisher\Service\HootsuiteAPIClient
   */
  private $hootsuiteAPIClient;

  /**
   * Callback constructor.
   *
   * @param \Drupal\iq_hootsuite_publisher\Service\HootsuiteAPIClient $hootsuiteAPIClient
   *   Google API Client.
   */
  public function __construct(HootsuiteAPIClient $hootsuiteAPIClient) {
    $this->hootsuiteAPIClient = $hootsuiteAPIClient;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('iq_hootsuite_publisher.client')
    );
  }

  /**
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request.
   *
   * @return array
   *   Return markup for the page.
   */
  public function callbackUrl(Request $request) {
    $code = $request->get('code');
    $token = $this->hootsuiteAPIClient->getAccessTokenByAuthCode($code);

    // If token valid.
    if ( $token->getToken() ) {
      $this->messenger()->addMessage($this->t('Access tokens saved'));
    }
    else {
      $this->messenger()->addError($this->t('Failed to get access token. Check log messages.'));
    }

    return new RedirectResponse(Url::fromRoute('iq_hootsuite_publisher.settings')->toString());
  }

}
