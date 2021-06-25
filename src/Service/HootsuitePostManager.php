<?php

namespace Drupal\iq_hootsuite_publisher\Service;

use Drupal\Core\Utility\Token;
use Drupal\Core\Entity\EntityInterface;
use Drupal\iq_publisher\Entity\Assignment;
use Drupal\node\NodeInterface;
use Drupal\file\Entity\File;
use GuzzleHttp\RequestOptions;

/**
 * Class Hootsuite Post Manager.
 *
 * @package Drupal\iq_hootsuite_publisher\Service
 */
class HootsuitePostManager {

  protected $hootsuiteClient = NULL;
  protected $tokenService = NULL;
  protected $config = NULL;
  protected $assignmentField = 'field_hs_assignment';
  protected $images = [];

  /**
   * Create a new instance.
   *
   * @param \Drupal\pagedesigner\Service\HandlerPluginManager $handler_manager
   *   The handler manager from which to retrieve the element handlers.
   * @param Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher to dispatch events.
   */
  public function __construct(HootsuiteAPIClient $hootsuite_client, Token $token_service) {
    $this->hootsuiteClient = $hootsuite_client;
    $this->tokenService = $token_service;
    $this->config = \Drupal::config('iq_hootsuite_publisher.settings');
  }

  /**
   * Undocumented function.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return void
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
   *
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
          \Drupal::messenger()->addError(
              t('Post for @profile has not been posted/changed on Hootsuite due to error on image processing.',
              ['@profile' => $assignment->field_hs_profile_name->value])
            );
          return;
        }
      }
    }

    if (!empty($extendedInfo = $this->augmentForPinterest($assignment, $entity, $requestBody))) {
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
      \Drupal::logger('iq_hootsuite_publisher')->notice(t('Created post for @profile with id @id.'), ['@profile' => $assignment->field_hs_profile_name->value, '@id' => $assignment->field_hs_post_id->value]);
      \Drupal::messenger()->addMessage('The post for @profile has been successfully scheduled.', ['@profile' => $assignment->field_hs_profile_name->value]);
    }
    else {
      \Drupal::messenger()->addWarning('Failed posting for @profile.', ['@profile' => $assignment->field_hs_profile_name->value]);
    }
  }

  /**
   *
   */
  public function deletePost(Assignment &$assignment, $update = FALSE) {
    if (!$assignment->hasField('field_hs_post_id') || $assignment->field_hs_post_id->isEmpty()) {
      return;
    }
    if ($assignment->field_hs_date->date->getTimestamp() < time()) {
      return;
    }
    $url = $this->config->get('url_post_message_endpoint') . '/' . $assignment->field_hs_post_id->value;
    $response = $this->hootsuiteClient->connect('delete', $url);
    if (!$update) {
      \Drupal::logger('iq_hootsuite_publisher')->notice(t('Deleted post for @profile with id @id.'), ['@profile' => $assignment->field_hs_profile_name->value, '@id' => $assignment->field_hs_post_id->value]);
      \Drupal::messenger()->addMessage(t('Deleted post for @profile.'), ['@profile' => $assignment->field_hs_profile_name->value]);
    }
  }

  /**
   *
   */
  protected function augmentForPinterest(Assignment $assignment, NodeInterface $entity) {
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
        \Drupal::messenger()->addError(
          t('Post for @profile has not been posted/changed on Hootsuite due to missing board id.',
         ['@profile' => $assignment->field_hs_profile_name->value])
        );
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   *
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
   *
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
    if ($this->uploadToAWS($image, $data['uploadUrl'])) {
      $i = 0;
      do {
        sleep(1);
        $i++;
        if ($i > 20) {
          \Drupal::messenger()->addMessage('Image could not be uploaded, waited for 20 seconds', 'warning');
          \Drupal::logger('iq_hootsuite_publisher')->warning('Timeout on ready state for image with id @id.', ['@id' => $id]);
          return FALSE;
        }
        $response = $this->hootsuiteClient->connect('get', $this->config->get('url_post_media_endpoint') . '/' . $id);
        if (empty($response)) {
          return FALSE;
        }
        $state = json_decode($response->getContents(), TRUE)['data']['state'];
      } while ($state != 'READY');
      \Drupal::logger('iq_hootsuite_publisher')->notice('Ready state for image id @id.', ['@id' => $id]);
      return $id;
    }
    return FALSE;
  }

  /**
   *
   */
  protected function uploadToAWS(File $image, $url) {
    $requestOptions = [
      RequestOptions::HEADERS => [
        'Content-Type' => $image->getMimeType(),
        'Content-Length' => filesize($image->getFileUri()),
      ],
      RequestOptions::BODY => fopen($image->getFileUri(), 'r'),
    ];
    $client = \Drupal::httpClient();
    try {
      $response = $client->put($url, $requestOptions);
      return TRUE;
    }
    catch (Exception $e) {
      \Drupal::messenger()->addMessage($e->getMessage());
      return FALSE;
    }
    return TRUE;
  }

  /**
   *
   */
  protected function validate(EntityInterface &$entity, &$assignment) {
    if (!$entity->isPublished()) {
      if ($entity->hasField('publish_on') && !$entity->publish_on->isEmpty()) {
        if ($assignment->field_hs_date->date->getTimestamp() < $entity->publish_on->value) {
          \Drupal::messenger()->addWarning(t('Cannot schedule post for @profile before the entry is being published.', ['@name' => $assignment->field_hs_profile_name->value]));
          return FALSE;
        }
      }
      else {
        \Drupal::messenger()->addWarning(t('Cannot schedule post for @profile for unpublished entry.', ['@name' => $assignment->field_hs_profile_name->value]));
        return FALSE;
      }
    }
    if ($assignment->field_hs_date->date->getTimestamp() < time()) {
      return FALSE;
    }
    return TRUE;
  }

}
