<?php

namespace Drupal\assignments_hootsuite\Service;

use GuzzleHttp\Client;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Utility\Token;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\assignments\Entity\Assignment;
use Drupal\node\NodeInterface;
use Drupal\file\Entity\File;
use GuzzleHttp\RequestOptions;

/**
 * Class Hootsuite Post Manager.
 *
 * @package Drupal\assignments_hootsuite\Service
 */
class HootsuitePostManager {

  /**
   * The api client for hootsuite.
   *
   * @var HootsuiteAPIClient
   */
  protected $hootsuiteClient = NULL;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $tokenService = NULL;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config = NULL;

  /**
   * The http client for image upload to aws.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient = NULL;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger = NULL;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger = NULL;

  /**
   * The field name for assignment.
   *
   * @var string
   */
  protected $assignmentField = 'field_hs_assignment';

  /**
   * The images to upload.
   *
   * @var array
   */
  protected $images = [];

  /**
   * Create a new instance.
   *
   * @param HootsuiteAPIClient $hootsuite_client
   *   The api client for hootsuite.
   * @param \Drupal\Core\Utility\Token $token_service
   *   The token service.
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   The configuration.
   * @param \GuzzleHttp\Client $http_client
   *   The http client for image upload to aws.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger service.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The messenger service.
   */
  public function __construct(
    HootsuiteAPIClient $hootsuite_client,
    Token $token_service,
    ConfigFactory $config,
    Client $http_client,
    LoggerChannelFactoryInterface $loggerFactory,
    Messenger $messenger
  ) {
    $this->hootsuiteClient = $hootsuite_client;
    $this->tokenService = $token_service;
    $this->config = $config->get('assignments_hootsuite.settings');
    $this->httpClient = $http_client;
    $this->logger = $loggerFactory->get('assignments_hootsuite');
    $this->messenger = $messenger;
  }

  /**
   * Handle a node.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The node to handle.
   */
  public function handleNode(NodeInterface $entity) {
    if ($entity->hasField($this->assignmentField)) {
      $assignments = $entity->get($this->assignmentField);
      if (count($assignments) > 0) {
        foreach ($assignments as $item) {
          if ($item->entity != NULL && $item->entity->hasField('field_hs_profile_id')) {
            if ($this->validate($entity, $item->entity)) {
              $this->sendPost($entity, $item->entity);
            }
          }
        }
      }
    }
  }

  /**
   * Send an assignment to hootsuite.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The node to post the assignment of.
   * @param \Drupal\assignments\Entity\Assignment $assignment
   *   The assignment to post.
   */
  public function sendPost(NodeInterface &$entity, Assignment &$assignment) {
    // Delete post from hootsuite if already exists.
    if (!$assignment->field_hs_post_id->isEmpty()) {
      $this->deletePost($assignment, TRUE);
      $assignment->field_hs_post_id->value = NULL;
    }

    $entityData = [
      'node' => $entity,
    ];

    $requestBody = [
      'text' => html_entity_decode(strip_tags($this->tokenService->replace($assignment->field_hs_post->value, $entityData, ['clear' => TRUE]))),
      'socialProfileIds' => [$assignment->field_hs_profile_id->value],
      'scheduledSendTime' => $assignment->field_hs_date->value . 'Z',
    ];

    if (!$assignment->field_hs_image->isEmpty()) {
      $imageId = $this->tokenService->replace($assignment->field_hs_image->value, $entityData, ['clear' => TRUE]);
      $image = File::load($imageId);

      if ($image != NULL) {
        if (($id = $this->uploadImage($image)) !== FALSE) {
          $requestBody['media'] = [['id' => $id]];
        }
        else {
          $this->messenger->addError(
              t('Post for @profile has not been posted/changed on Hootsuite due to error on image processing.',
              ['@profile' => $assignment->field_hs_profile_name->value])
            );
          return;
        }
      }
    }

    if (!empty($extendedInfo = $this->augmentForPinterest($entity, $assignment))) {
      $requestBody['extendedInfo'] = $extendedInfo;
    }

    $response = $this->hootsuiteClient->connect('post', $this->config->get('url_post_message_endpoint'), NULL, $requestBody);
    if (empty($response)) {
      return;
    }

    $data = json_decode($response, TRUE)['data'][0];

    if ($data && $data['state'] == 'SCHEDULED') {
      $hootsuite_post_id = $data['id'];
      $assignment->field_hs_post_id = $hootsuite_post_id;
      /** @var \Drupal\Core\Entity\Entity $entity */
      $assignment->save();
      $this->logger->notice(
        t('Created post for @profile with id @id.'),
        [
          '@profile' => $assignment->field_hs_profile_name->value,
          '@id' => $assignment->field_hs_post_id->value,
        ]
      );
      $this->messenger->addMessage(
        'The post for @profile has been successfully scheduled.',
        ['@profile' => $assignment->field_hs_profile_name->value]
      );
    }
    else {
      $this->messenger->addWarning(
        'Failed posting for @profile.',
        ['@profile' => $assignment->field_hs_profile_name->value]
      );
    }
  }

  /**
   * Delete an assignment from Hootsuite.
   *
   * @param \Drupal\assignments\Entity\Assignment $assignment
   *   The assignment to delete.
   * @param bool $update
   *   Whether to post info about the deletion.
   */
  public function deletePost(Assignment &$assignment, $update = FALSE) {
    if (!$assignment->hasField('field_hs_post_id') || $assignment->field_hs_post_id->isEmpty()) {
      return;
    }
    if ($assignment->field_hs_date->date->getTimestamp() < time()) {
      return;
    }
    $url = $this->config->get('url_post_message_endpoint') . '/' . $assignment->field_hs_post_id->value;
    $this->hootsuiteClient->connect('delete', $url);
    if (!$update) {
      $this->logger->notice(
        t('Deleted post for @profile with id @id.'),
        [
          '@profile' => $assignment->field_hs_profile_name->value,
          '@id' => $assignment->field_hs_post_id->value,
        ]
      );
      $this->messenger->addMessage(
        t('Deleted post for @profile.'),
        ['@profile' => $assignment->field_hs_profile_name->value]
      );
    }
  }

  /**
   * Get extended info for Pinterested.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   * @param \Drupal\assignments\Entity\Assignment $assignment
   *   The assignment.
   */
  protected function augmentForPinterest(NodeInterface $entity, Assignment $assignment) {
    // Add special data for pinterest.
    if ($assignment->hasField('field_hs_pinterest_board')) {
      if (!$assignment->field_hs_pinterest_board->isEmpty()) {
        $pinterestUrl = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
        if (!$assignment->field_hs_pinterest_url->isEmpty()) {
          $pinterestUrl = $assignment->field_hs_pinterest_url->first()->getUrl()->toString();
        }
        $extendedInfo = [
            [
              'socialProfileType' => 'PINTEREST',
              'socialProfileId' => $assignment->field_hs_profile_id->value,
              'data' => [
                'boardId' => $assignment->field_hs_pinterest_board->value,
                'destinationUrl' => $pinterestUrl,
              ],
            ],
        ];
        return $extendedInfo;
      }
      else {
        $this->messenger->addError(
          t('Post for @profile has not been posted/changed on Hootsuite due to missing board id.',
         ['@profile' => $assignment->field_hs_profile_name->value])
        );
        return NULL;
      }
    }
    return NULL;
  }

  /**
   * Upload image to hootsuite.
   *
   * @param \Drupal\file\Entity\File $image
   *   The file to upload.
   */
  public function uploadImage(File $image) {
    if (!empty($this->images[$image->id()])) {
      return $this->images[$image->id()];
    }
    else {
      $id = $this->registerImage($image);
      if ($id) {
        $this->images[$image->id()] = $id;
        return $id;
      }
    }
    return FALSE;
  }

  /**
   * Register image with hootsuite.
   *
   * @param \Drupal\file\Entity\File $image
   *   The file to register.
   */
  protected function registerImage(File $image) {
    $body = [
      'mimeType' => $image->getMimeType(),
      'sizeBytes' => filesize($image->getFileUri()),
    ];
    $result = $this->hootsuiteClient->connect('post', $this->config->get('url_post_media_endpoint'), NULL, $body);
    if (empty($result)) {
      return FALSE;
    }
    $data = json_decode($result, TRUE)['data'];
    $id = $data['id'];
    if ($this->uploadToAws($image, $data['uploadUrl'])) {
      $i = 0;
      do {
        sleep(1);
        $i++;
        if ($i > 20) {
          $this->messenger->addMessage('Image could not be uploaded, waited for 20 seconds', 'warning');
          $this->logger->warning('Timeout on ready state for image with id @id.', ['@id' => $id]);
          return FALSE;
        }
        $response = $this->hootsuiteClient->connect('get', $this->config->get('url_post_media_endpoint') . '/' . $id);
        if (empty($response)) {
          return FALSE;
        }
        $state = json_decode($response->getContents(), TRUE)['data']['state'];
      } while ($state != 'READY');
      $this->logger->notice('Ready state for image id @id.', ['@id' => $id]);
      return $id;
    }
    return FALSE;
  }

  /**
   * Upload an image to aws.
   *
   * @param \Drupal\file\Entity\File $image
   *   The file to upload.
   * @param string $url
   *   The endpoint to send it to.
   */
  protected function uploadToAws(File $image, string $url) {
    $requestOptions = [
      RequestOptions::HEADERS => [
        'Content-Type' => $image->getMimeType(),
        'Content-Length' => filesize($image->getFileUri()),
      ],
      RequestOptions::BODY => fopen($image->getFileUri(), 'r'),
    ];
    try {
      $response = $this->httpClient->put($url, $requestOptions);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->messenger->addMessage($e->getMessage());
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Check, if entity is or will be published by post time.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   * @param \Drupal\assignments\Entity\Assignment $assignment
   *   The assignment.
   */
  protected function validate(NodeInterface &$entity, Assignment &$assignment) {
    if (!$entity->isPublished()) {
      if ($entity->hasField('publish_on') && !$entity->publish_on->isEmpty()) {
        if ($assignment->field_hs_date->date->getTimestamp() < $entity->publish_on->value) {
          $this->messenger->addWarning(t('Cannot schedule post for @profile before the entry is being published.', ['@name' => $assignment->field_hs_profile_name->value]));
          return FALSE;
        }
      }
      else {
        $this->messenger->addWarning(t('Cannot schedule post for @profile for unpublished entry.', ['@name' => $assignment->field_hs_profile_name->value]));
        return FALSE;
      }
    }
    if ($assignment->field_hs_date->date->getTimestamp() < time()) {
      return FALSE;
    }
    return TRUE;
  }

}
