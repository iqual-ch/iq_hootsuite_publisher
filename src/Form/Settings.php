<?php

namespace Drupal\iq_hootsuite_publisher\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\iq_hootsuite_publisher\Service\HootSuiteAPIClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Google API Settings.
 */
class Settings extends ConfigFormBase {

  /**
   * Google API Client.
   *
   * @var \Drupal\iq_hootsuite_publisher\Service\HootSuiteAPIClient
   */
  private $hootSuiteAPIClient;

  /**
   * Settings constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory.
   * @param \Drupal\iq_hootsuite_publisher\Service\HootSuiteAPIClient $hootSuiteAPIClient
   *   Google Api Client.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              HootSuiteAPIClient $hootSuiteAPIClient) {
    parent::__construct($config_factory);
    $this->hootSuiteAPIClient = $hootSuiteAPIClient;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('iq_hootsuite_publisher.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'iq_hootsuite_publisher_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['iq_hootsuite_publisher.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('iq_hootsuite_publisher.settings');
    $tokenConf = $this->config('iq_hootsuite_publisher.tokens');
    $options = ['attributes' => ['target' => '_self']];

    $form['client'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Client Settings'),
    ];

    $form['client']['help'] = [
      '#type' => '#markup',
      '#markup' => $this->t('To get your Hootsuite Client ID, you need to register your application. See details on @link.',
        [
          '@link' => Link::fromTextAndUrl('https://developer.hootsuite.com/docs',
            Url::fromUri('https://developer.hootsuite.com/docs'))->toString(),
        ]),
    ];

    $form['client']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hootsuite Client ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];


    $form['client']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hootsuite Client secret'),
      '#default_value' => $config->get('client_secret'),
      '#required' => TRUE,
    ];

    if ($config->get('client_id') != '') {
      $link = Link::fromTextAndUrl('click here', Url::fromUri($this->accessUrl(), $options))->toString();

      // Just check if any of the tokens are set, if not set a message.
      if ($tokenConf->get('access_token') == NULL && $tokenConf->get('refresh_token') == NULL) {
        $msg = $this->t('Access and Refresh Tokens are not set, to get your Tokens, @link.',
          ['@link' => $link]
        );

        $this->messenger()->addError($msg);
      }


      $form['client']['tokens'] = [
        '#type' => 'details',
        '#title' => $this->t('Access and Refresh Tokens'),
        '#description' => $this->t('To get your Tokens, @link.',
          ['@link' => $link]
        ),
        '#open' => TRUE,
        '#access' => TRUE,
      ];
    }



    $form['auth_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OAuth2 Settings'),
    ];

    $form['auth_settings']['url_auth_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server OAuth2 Authorize endpoint'),
      '#default_value' => $config->get('url_auth_endpoint'),
      '#required' => TRUE,
    ];

    $form['auth_settings']['url_token_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server OAuth2 Token endpoint'),
      '#default_value' => $config->get('url_token_endpoint'),
      '#required' => TRUE,
    ];

    $form['api_endpoints'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Endpoints'),
    ];

    $form['api_endpoints']['url_post_message_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Post message'),
      '#default_value' => $config->get('url_post_message_endpoint'),
      '#required' => TRUE,
    ];


    $form['api_endpoints']['url_post_media_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Post media'),
      '#default_value' => $config->get('url_post_media_endpoint'),
      '#required' => TRUE,
    ];
      $form['api_endpoints']['url_social_profiles_endpoint'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Social Profiles'),
          '#default_value' => $config->get('url_social_profiles_endpoint'),
          '#required' => TRUE,
      ];

    $form['api_endpoints']['url_delete_message_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete message'),
      '#default_value' => $config->get('url_delete_message_endpoint'),
      '#required' => TRUE,
    ];


    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('iq_hootsuite_publisher.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('url_auth_endpoint', $form_state->getValue('url_auth_endpoint'))
      ->set('url_token_endpoint', $form_state->getValue('url_token_endpoint'))
      ->set('url_post_message_endpoint', $form_state->getValue('url_post_message_endpoint'))
      ->set('url_post_media_endpoint', $form_state->getValue('url_post_media_endpoint'))
      ->set('url_social_profiles_endpoint', $form_state->getValue('url_social_profiles_endpoint'))
      ->set('url_delete_message_endpoint', $form_state->getValue('url_delete_message_endpoint'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Generate the Access Url.
   *
   * See details at
   * https://developers.google.com/identity/protocols/OAuth2WebServer?csw=1#formingtheurl.
   *
   * @return string
   *   URL.
   */
  private function accessUrl() {

    // Generate a URL to request access from Hootsuite's OAuth 2.0 server.
    return $this->hootSuiteAPIClient->createAuthUrl();
  }

}
