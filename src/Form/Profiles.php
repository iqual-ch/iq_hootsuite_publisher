<?php

namespace Drupal\assignments_hootsuite\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\assignments_hootsuite\Service\HootSuiteAPIClient;
use Drupal\assignments\Entity\AssignmentType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Google API Settings.
 */
class Profiles extends ConfigFormBase
{

    /**
     * Google API Client.
     *
     * @var \Drupal\assignments_hootsuite\Service\HootSuiteAPIClient
     */
    private $hootSuiteAPIClient;

    /**
     * Settings constructor.
     *
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
     *   Config Factory.
     * @param \Drupal\assignments_hootsuite\Service\HootSuiteAPIClient $hootSuiteAPIClient
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
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('config.factory'),
            $container->get('assignments_hootsuite.client')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'assignments_hootsuite_profiles';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return ['assignments_hootsuite.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {

        $config = $this->config('assignments_hootsuite.settings');
        $hootsuite_client = \Drupal::service('assignments_hootsuite.client');

        $response = $hootsuite_client->connect('get', $config->get('url_social_profiles_endpoint'));
        if (!empty($response)) {
            $profiles = json_decode($response->getContents(), true)['data'];
            $form['profiles'] = [
                '#type' => 'fieldset',
                '#title' => $this->t('Social Profiles'),
            ];
            foreach ($profiles as $profile) {

                $form['profiles']['social_profile_' . $profile['id']] = [
                    '#type' => 'checkbox',
                    '#title' => $profile['socialNetworkUsername'],
                    '#default_value' => $config->get('social_profile_' . $profile['id']),
                    '#description' => $profile['type'] . ' (' . $profile['id'] . ')',
                    '#disabled' => $this->checkExistingAssignmentType($profile['id']),
                ];
            }

        }
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $profiles = $form['profiles'];
        // Set profiles in the config.
        foreach ($form_state->getValues() as $key => $value) {
            if (substr($key, 0, strlen('social_profile')) === 'social_profile') {
                if ($value == 1) {
                    if (!$this->checkExistingAssignmentType(explode('_', $key)[2])) {
                        // Create assignment type.
                        $assignment_type = AssignmentType::create([
                            'label' => $profiles[$key]['#title'] . ' - ' . $profiles[$key]['#description'],
                            'id' => $key,
                        ])->save();
                        // Add the fields with adjusted settings.
                        $this->addBaseFieldsSocialProfile($key, $profiles[$key]['#title']);
                    }
                }
                $this->config('assignments_hootsuite.settings')
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
    protected function addBaseFieldsSocialProfile($id, $name)
    {
        $fields = [];
        $fields[] = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => [
                "config" => ["field.storage.assignment.field_hs_image", "assignments.assignment_type." . $id],
            ],
            "id" => "assignment." . $id . ".field_hs_image",
            "field_name" => "field_hs_image",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Image",
            "description" => "MUST RETURN FID (FILE ENTITY ID)!!!",
            "required" => false,
            "translatable" => true,
            "default_value" => ["value" => ""],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string_long",
        ];
        $fields[] = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => [
                "config" => ["field.storage.assignment.field_hs_post", "assignments.assignment_type." . $id],
            ],
            "id" => "assignment." . $id . ".field_hs_post",
            "field_name" => "field_hs_post",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Post",
            "description" => "",
            "required" => false,
            "translatable" => true,
            "default_value" => ["value" => ""],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string_long",
        ];

        $fields[] = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => [
                "config" => ["field.storage.assignment.field_hs_date", "assignments.assignment_type." . $id],
                "module" => ["datetime"],
            ],
            "id" => "assignment." . $id . ".field_hs_date",
            "field_name" => "field_hs_date",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Datum der Schaltung",
            "description" => "",
            "required" => false,
            "translatable" => true,
            "default_value" => [],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "datetime",
        ];
        $fields[] = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => ["config" => ["field.storage.assignment.field_hs_post_id", "assignments.assignment_type." . $id]],
            "id" => "assignment." . $id . ".field_hs_post_id",
            "field_name" => "field_hs_post_id",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Hootsuite Post ID",
            "description" => "",
            "required" => false,
            "translatable" => false,
            "default_value" => [],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string",
        ];
        $fields[] = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => ["config" => ["field.storage.assignment.field_hs_profile_id", "assignments.assignment_type." . $id]],
            "id" => "assignment." . $id . ".field_hs_profile_id",
            "field_name" => "field_hs_profile_id",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Profile ID",
            "description" => "",
            "required" => false,
            "translatable" => false,
            "default_value" => ["value" => explode('_', $id)[2]],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string",
        ];
        $fields[] = [
            "langcode" => "de",
            "status" => true,
            "dependencies" => ["config" => ["field.storage.assignment.field_hs_profile_name", "assignments.assignment_type." . $id]],
            "id" => "assignment." . $id . ".field_hs_profile_name",
            "field_name" => "field_hs_profile_name",
            "entity_type" => "assignment",
            "bundle" => $id,
            "label" => "Profile Name",
            "description" => "",
            "required" => false,
            "translatable" => false,
            "default_value" => ["value" => $name],
            "default_value_callback" => "",
            "settings" => [],
            "field_type" => "string",
        ];

        foreach ($fields as $field) {
            FieldConfig::create($field)->save();
        }
    }

    /**
     * Check if the assignment type already exists.
     *
     * @return bool
     *   TRUE if the assignment type already exists.
     */
    private function checkExistingAssignmentType($id)
    {
        $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('assignment');
        foreach ($bundles as $bundleId => $bundle) {
            if ('social_profile_' . $id == $bundleId) {
                return true;
            }
        }
        return false;
    }
}
