<?php

namespace Drupal\hubspot_api_webform\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\hubspot_api\Manager;
use Drupal\hubspot_api_webform\HubspotFormManager;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "hubspot_api_webform_handler",
 *   label = @Translation("HubSpot Webform Handler (hubspot_api)"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a Hubspot
 *   form."),
 *   cardinality =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class HubspotWebformHandler extends WebformHandlerBase {

  /**
   * The Hubspot api mananger.
   *
   * @var \Drupal\hubspot_api\Manager
   */
  protected $hubspotManager;

  /**
   * The Hubspot api mananger.
   *
   * @var \Drupal\hubspot_api_webform\HubspotFormManager
   */
  protected $hubspotFormManager;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, Manager $hubspot_manager, HubspotFormManager $hubspot_form_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);

    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->hubspotManager = $hubspot_manager;
    $this->hubspotFormManager = $hubspot_form_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('hubspot_api.manager'),
      $container->get('hubspot_api_webform.form_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $guid =
    $this->configuration['hubspot_form'] = $form_state->getValue('hubspot_form');
    $this->configuration['hubspot_portal_id'] = $form_state->getValue('hubspot_portal_id');
    $mapping = $form_state->getValue($guid);
    $this->configuration['hubspot_mapping'] = $mapping;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'hubspot_form' => NULL,
      'hubspot_mapping' => [],
      'hubspot_portal_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $hubspot_forms = $this->hubspotManager->getHandler()->forms()->all();
    $hubspot_form_options = [];
    foreach ($hubspot_forms->toArray() as $hubspot_form) {
      $hubspot_form_fields = $this->hubspotManager->getHandler()
        ->forms()
        ->getFields($hubspot_form['guid'])
        ->getData();
      $hubspot_form_options[$hubspot_form['guid']] = $hubspot_form['name'];
      $hubspot_field_options[$hubspot_form['guid']]['fields']['--donotmap--'] = "Do Not Map";
      foreach ($hubspot_form_fields as $hubspot_form_field) {
        $hubspot_field_options[$hubspot_form['guid']]['fields'][$hubspot_form_field->name] = ($hubspot_form_field->label ? $hubspot_form_field->label : $hubspot_form_field->name) . ' (' . $hubspot_form_field->fieldType . ')';
      }
    }
    $form['hubspot_form'] = [
      '#title' => $this->t('HubSpot form'),
      '#type' => 'select',
      '#options' => $hubspot_form_options,
      '#default_value' => $this->configuration['hubspot_form'],
      '#weight' => -10,
    ];
    $form['hubspot_portal_id'] = [
      '#title' => $this->t('HubSpot Portal ID'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['hubspot_portal_id'],
      '#weight' => -9,
      '#required' => TRUE,
    ];


    foreach ($hubspot_form_options as $key => $value) {
      if ($key != '--donotmap--') {
        $form[$key] = [
          '#title' => $this->t('Field mappings for @field', [
            '@field' => $value,
          ]),
          '#type' => 'details',
          '#tree' => TRUE,
          '#weight' => 0,
          '#states' => [
            'visible' => [
              ':input[name="settings[hubspot_form]"]' =>
                [
                  'value' => $key,
                ],
            ],
          ],
        ];

        $webform = $this->getWebform()->getElementsDecodedAndFlattened();

        foreach ($webform as $form_key => $component) {
          if ($component['#type'] !== 'markup') {
            $default_value = NULL;
            if ($this->configuration['hubspot_form'] == $key) {
              $default_value = isset($this->configuration['hubspot_mapping'][$form_key]) ? $this->configuration['hubspot_mapping'][$form_key] : NULL;
            }

            if (isset($component['#type']) && $component['#type'] === 'webform_address') {
              foreach (['address', 'address_2', 'city', 'postal_code', 'country'] as $sub_key) {
                $form[$key][$form_key. '___' . $sub_key] = [
                  '#title' => (isset($component['#title']) ? $component['#title'] : '') . '[' . $sub_key . '] (' . $component['#type'] . ')',
                  '#type' => 'select',
                  '#options' => $hubspot_field_options[$key]['fields'],
                  '#default_value' => $default_value,
                ];
              }
            }
            $form[$key][$form_key] = [
              '#title' => (isset($component['#title']) ? $component['#title'] : '') . ' (' . $component['#type'] . ')',
              '#type' => 'select',
              '#options' => $hubspot_field_options[$key]['fields'],
              '#default_value' => $default_value,
            ];
          }
        }
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $json_fields = [];
    $operation = ($update) ? 'update' : 'insert';
    $post_data = $this->getPostData($operation, $webform_submission);
    $entity_type = isset($post_data['entity_type']) ? $post_data['entity_type'] : NULL;
    $entity_id = isset($post_data['entity_id']) ? $post_data['entity_id'] : NULL;
    $url = '';
    $title = '';
    try {
      $entity = \Drupal::entityTypeManager()
        ->getStorage($entity_type)
        ->load($entity_id);
      $url = $entity->toUrl()->toString();
      $title = $entity->label();
    } catch (\Exception $e) {
      // DO NOTHING FOR NOW.
    }
    $form_guid = $this->configuration['hubspot_form'];
    $portal_id = $this->configuration['hubspot_portal_id'];;
    $fields = $this->configuration['hubspot_mapping'];

    foreach ($fields as $webform_name => $hubspot_name) {
      if ($hubspot_name !== '--donotmap--') {
        if (is_array($post_data[$webform_name])) {
          $post_data[$webform_name] = join(";", $post_data[$webform_name]);
        }
        // Handle sub element like
        if (strpos($webform_name, '___') !== FALSE) {
            $keys = explode('___', $webform_name);
            if (isset($post_data[$keys[0]][$keys[1]])) {
              $json_fields[$hubspot_name] =  $post_data[$keys[0]][$keys[1]];
            }
        } else {
          if (isset($post_data[$webform_name])) {
            $json_fields[$hubspot_name] = $post_data[$webform_name];
          }

        }

      }
    }
    $this->hubspotFormManager->submit($portal_id, $form_guid, $json_fields, $url, $title);
  }

  /**
   * Get a webform submission's post data.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getPostData($operation, WebformSubmissionInterface $webform_submission) {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Flatten data.
    // Prioritizing elements before the submissions fields.
    $data = $data['data'] + $data;
    unset($data['data']);

    return $data;
  }

}
