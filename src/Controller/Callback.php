<?php

namespace Drupal\assignments_hootsuite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\assignments_hootsuite\Service\HootsuiteAPIClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Google Client Callback Controller.
 *
 * @package Drupal\assignments_hootsuite\Controller
 */
class Callback extends ControllerBase {

  /**
   * Google API Client.
   *
   * @var \Drupal\assignments_hootsuite\Service\HootsuiteAPIClient
   */
  private $hootsuiteAPIClient;

  /**
   * Callback constructor.
   *
   * @param \Drupal\assignments_hootsuite\Service\HootsuiteAPIClient $hootsuiteAPIClient
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
      $container->get('assignments_hootsuite.client')
    );
  }

  /**
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request.
   *
   * @return RedirectResponse
   *   Return markup for the page.
   */
  public function callbackUrl(Request $request) {
    $code = $request->get('code');

    $token = $this->hootsuiteAPIClient->getAccessTokenByAuthCode($code);
    // If token valid.
    if ($token == true) {
      $this->messenger()->addMessage($this->t('Access tokens saved'));
    }
    else {
      $this->messenger()->addError($this->t('Failed to get access token. Check log messages.'));
    }

    return new RedirectResponse(Url::fromRoute('assignments_hootsuite.settings')->toString());
  }
}
