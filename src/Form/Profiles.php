<?php

namespace Drupal\iq_hootsuite_publisher\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\iq_hootsuite_publisher\Service\HootSuiteAPIClient;
use Drupal\iq_publisher\Entity\AssignmentType;
use Drupal\migrate\Plugin\migrate\process\MachineName;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Google API Settings.
 */
class Profiles extends ConfigFormBase {

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
        return 'iq_hootsuite_publisher_profiles';
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
        $hootsuite_client = \Drupal::service('iq_hootsuite_publisher.client');

        $response = $hootsuite_client->connect('get', $config->get('url_social_profiles_endpoint'));
        if ($response != null) {
            $profiles = json_decode($response->getContents(),true)['data'];
            $form['profiles'] = [
                '#type' => 'fieldset',
                '#title' => $this->t('Social Profiles'),
            ];
            foreach ($profiles as $profile) {
                $form['profiles']['social_profile_' . $profile['id']] = [
                    '#type' => 'checkbox',
                    '#title' => $profile['type'],
                    '#default_value' => $config->get('social_profile_' . $profile['id']),
                    '#description' => $profile['socialNetworkUsername'],
                ];
            }

        }
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $profiles = $form['profiles'];
        // Set profiles in the config.
        foreach ($form_state->getValues() as $key => $value) {
            if(substr($key, 0, strlen('social_profile'))==='social_profile') {
                if ($value == 1) {
                    if (!$this->checkExistingAssignmentType(explode('_', $key)[2])) {
                        // Create assignment type.
                        $assignment_type = AssignmentType::create([
                            'label' => $profiles[$key]['#title'] .  ' - ' . $profiles[$key]['#description'],
                            'id' => $key,
                        ])->save();
                        // Add the fields with adjusted settings.
                        if ($fields = $this->baseFieldsSocialProfile($key)) {
                            foreach ($fields as $field) {
                                FieldConfig::create($field)->save();
                            }
                        }
                    }
                }
                $this->config('iq_hootsuite_publisher.settings')
                    ->set($key, $value)
                    ->save();
            }
        }
        parent::submitForm($form, $form_state);
    }

    /**
     * Constant fields thar are needed for the hootsuite integration.
     *
     * @param $id
     *   The id of the assignment type.
     * @return array
     *   The base fields configuration for a social profile.
     */
    private function baseFieldsSocialProfile($id) {
        $field_media_image = [
            "langcode"=> "de",
            "status"=> true,
            "dependencies" => [
                "config" => ["field.storage.assignment.field_image", "iq_publisher.assignment_type." . $id]
            ],
            "id" => "assignment." . $id . ".field_image",
            "field_name" => "field_image",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Image",
            "description" => "MUST RETURN FID (FILE ENTITY ID)!!!",
            "required" => false,
            "translatable" => true,
            "default_value" => ["value"=> "[node:field_image:entity:field_media_image:target_id]"],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string_long"
        ];
        $field_post = [
            "langcode" => "de",
            "status" =>true,
            "dependencies" => [
                "config" => ["field.storage.assignment.field_post", "iq_publisher.assignment_type." . $id]
            ],
            "id" => "assignment." . $id . ".field_post",
            "field_name" => "field_post",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Post",
            "description" => "",
            "required" => false,
            "translatable" => true,
            "default_value" => ["value" => "[node:title] [node:field_lead] [node:field_tg_entry]"],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string_long"
        ];

        $field_date = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => [
                "config" => ["field.storage.assignment.field_tg_date","iq_publisher.assignment_type." . $id],
                "module" => ["datetime"],
            ],
            "id" => "assignment." . $id . ".field_tg_date",
            "field_name" => "field_tg_date",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Datum der Schaltung",
            "description" => "",
            "required" => false,
            "translatable" => true,
            "default_value" => [],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "datetime"
        ];
        $field_hootsuite_post_id = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => ["config" => ["field.storage.assignment.field_tg_hootsuite_post_id", "iq_publisher.assignment_type." . $id]],
            "id" => "assignment." . $id . ".field_tg_hootsuite_post_id",
            "field_name" => "field_tg_hootsuite_post_id",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Hootsuite Post ID",
            "description" => "",
            "required" => false,
            "translatable" => false,
            "default_value" => [],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string"
        ];
        $field_social_profile_id = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => ["config"=> ["field.storage.assignment.field_tg_social_profile_id", "iq_publisher.assignment_type." . $id ]],
            "id" => "assignment." . $id . ".field_tg_social_profile_id",
            "field_name" => "field_tg_social_profile_id",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Profile ID",
            "description" => "",
            "required" => false,
            "translatable" => false,
            "default_value" => ["value" => explode('_', $id)[2]],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" =>"string"
        ];

        return [$field_media_image, $field_post, $field_date, $field_hootsuite_post_id, $field_social_profile_id];
    }

    /**
     * Check if the assignment type already exists.
     *
     * @return bool
     *   TRUE if the assignment type already exists.
     */
    private function checkExistingAssignmentType($id) {
        $nids = \Drupal::entityQuery('assignment_type')->execute();
        $assignment_types = \Drupal::entityTypeManager()->getStorage('assignment_type')->loadMultiple($nids);
        if ($fields = \Drupal::entityTypeManager()->getStorage('field_config')->loadByProperties(array('field_name' => 'field_tg_social_profile_id'))) {
            foreach ($fields as $field) {
                if ($field->toArray()['default_value'][0]['value'] == $id)
                    return true;
            }
        }
        return false;
    }
}
