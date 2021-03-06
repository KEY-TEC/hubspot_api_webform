<?php

namespace Drupal\hubspot_api_webform;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\hubspot_api\Manager;

class HubspotFormManager {

  /**
   * The Hubspot api mananger.
   *
   * @var \Drupal\hubspot_api\Manager
   */
  protected $hubspotManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, Manager $hubspot_manager) {
    $this->loggerFactory = $logger_factory;
    $this->hubspotManager = $hubspot_manager;
  }

  /**
   * Execute a remote post.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  public function submit($portal_id, $form_guid, array $fields, $page_uri = NULL, $page_title = NULL) {
    $json_fields = [];
    foreach ($fields as $name => $value) {
      $json_fields[] = [
        'name' => $name,
        'value' => $value,
      ];
    }
    $cookie = \Drupal::request()->cookies->get('hubspotutk');
    $json = [
      'fields' => $json_fields,
      'context' => [
        "hutk" => $cookie,
        "pageUri" => $page_uri,
        "pageName" => $page_title,
        "ipAddress" => \Drupal::request()->getClientIp()
      ],
      'legalConsentOptions' => [
        'consent' => [
          "consentToProcess" => TRUE,
          "text" => "I agree to allow Example Company to store and process my personal data.",
          "communications" => [
            [
              "value" => TRUE,
              "subscriptionTypeId" => 999,
              "text" => "I agree to receive marketing communications from Example Company.",
            ],
          ],
        ],
      ],
    ];
    $endpoint = "https://api.hsforms.com/submissions/v3/integration/submit/{$portal_id}/{$form_guid}";
    $options['json'] = $json;
    try {
      $this->hubspotManager->getHandler()->client->client->request('post', $endpoint, $options, NULL, FALSE);
      $this->loggerFactory->get('hubspot_api_webform')->notice('Webform "%form" results succesfully submitted to HubSpot. Response: @msg', [
          '@msg' => 'strip_tags($data)',
          '%form' => $page_title,
        ]
      );
      $this->loggerFactory->get('hubspot_api_webform')
        ->info('Hubspot Form submitted successfull');
    } catch (\Exception $e) {
      $this->loggerFactory->get('hubspot_api_webform')
        ->critical($e->getMessage());
    }

  }

}
