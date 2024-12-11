<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Exception;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsTriplestoreComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS OpenGDB service actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsOpenGdbServiceActions;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsServiceRequestInterface $sodaScsOpenGdbServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsOpenGdbServiceActions = $sodaScsOpenGdbServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create Triplestore Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array
   *   The created component.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    try {

      // Create Triplestore component.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface $bundle */
      $bundle = $this->entityTypeManager->getStorage('soda_scs_component_bundle')->load('triplestore');
      $subdomain = $entity->get('subdomain')->value;
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $triplestoreComponent */
      $triplestoreComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'triplestore',
          'label' => $subdomain . '.' . $this->settings->get('scsHost') . ' (Triplestore)',
          'subdomain' => $subdomain,
          'user'  => $entity->getOwner(),
          'description' => $bundle->getDescription(),
          'imageUrl' => $bundle->getImageUrl(),
        ]
      );
      // Create service key if it does not exist.
      $keyProps = [
        'bundle'  => 'triplestore',
        'type'  => 'password',
        'userId'    => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
      ];

      $triplestoreComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $triplestoreComponent->serviceKey[] = $triplestoreComponentServiceKey;

      $username = $triplestoreComponent->getOwner()->getDisplayName();
      $subdomain = $triplestoreComponent->get('subdomain')->value;
      // Build repo request.
      $createRepoRequestParams = [
        'type' => 'repository',
        'queryParams' => [],
        'routeParams' => [],
        'body' => [
          'subdomain' => $subdomain,
          'title' => $triplestoreComponent->label(),
          'publicRead' => FALSE,
          'publicWrite' => FALSE,
        ],
      ];
      // Create triplestore repository.
      $openGdbCreateRepoRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($createRepoRequestParams);
      $createRepoResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateRepoRequest);
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot assemble Request: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'createRepoResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
      // Look for existing user.
      $getUserRequestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => [$triplestoreComponent->getOwner()->getDisplayName()],
      ];

      $openGdbgetUserRequest = $this->sodaScsOpenGdbServiceActions->buildGetRequest($getUserRequestParams);
      $getUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbgetUserRequest);

      // @todo Make good error handling
      if ($getUserResponse['success'] === FALSE) {
        $response = $getUserResponse['data']['openGdbResponse']->getResponse();
        if ($response->getStatusCode() === 404) {
          // If there is none create repo user.
          try {
            $createUserRequestParams = [
              'type' => 'user',
              'queryParams' => [],
              'routeParams' => [$username],
              'body' => [
                'password' => $triplestoreComponentServiceKey->get('servicePassword')->value,
                'subdomain' => $subdomain,
              ],
            ];
            $openGdbCreateUserRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($createUserRequestParams);
            $createUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateUserRequest);
            $updateUserResponse = NULL;

            if (!$createUserResponse['success']) {
              return [
                'message' => 'Could not create user.',
                'data' => [
                  'createRepoResponse' => $createRepoResponse,
                  'getUserResponse' => $getUserResponse,
                  'createUserResponse' => $createUserResponse,
                  'updateUserResponse' => NULL,
                ],
                'success' => FALSE,
                'error' => $createUserResponse['error'],
              ];
            }

            // Create user token.
            try {
              $createUserTokenRequestParams = [
                'type' => 'user',
                'queryParams' => [],
                'routeParams' => [],
                'body' => [
                  'username' => $username,
                  'password' => $triplestoreComponentServiceKey->get('servicePassword')->value,
                ],
              ];
              $openGdbCreateUserTokenRequest = $this->sodaScsOpenGdbServiceActions->buildTokenRequest($createUserTokenRequestParams);
              $createUserTokenResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateUserTokenRequest);
              if (!$createUserTokenResponse['success']) {
                return [
                  'message' => 'Could not create user token.',
                  'data' => [
                    'createRepoResponse' => $createRepoResponse,
                    'getUserResponse' => $getUserResponse,
                    'createUserResponse' => $createUserResponse,
                    'updateUserResponse' => NULL,
                  ],
                  'success' => FALSE,
                  'error' => $createUserResponse['error'],
                ];
              }
              $tokenProps = [
                'bundle'  => 'triplestore',
                'type'  => 'token',
                'token' => $createUserTokenResponse['data']['openGdbResponse']['token'],
                'userId'    => $entity->getOwnerId(),
                'username' => $entity->getOwner()->getDisplayName(),
              ];
              $triplestoreComponentServiceToken = $this->sodaScsServiceKeyActions->createServiceKey($tokenProps);
              $triplestoreComponent->serviceKey[] = $triplestoreComponentServiceToken;
            }
            catch (Exception $e) {
              $this->loggerFactory->get('soda_scs_manager')
                ->error("Cannot assemble Request: @error", [
                  '@error' => $e->getMessage(),
                ]);
              $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
              return [
                'message' => 'Cannot assemble Request.',
                'data' => [
                  'createRepoResponse' => $createRepoResponse,
                  'getUserResponse' => $getUserResponse,
                  'createUserResponse' => $createUserResponse,
                  'updateUserResponse' => NULL,
                ],
                'success' => FALSE,
                'error' => $e->getMessage(),
              ];
            }

          }
          catch (MissingDataException $e) {
            $this->loggerFactory->get('soda_scs_manager')
              ->error("Cannot assemble Request: @error", [
                '@error' => $e->getMessage(),
              ]);
            $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
            return [
              'message' => 'Cannot assemble Request.',
              'data' => [
                'createRepoResponse' => $createRepoResponse,
                'getUserResponse' => $getUserResponse,
                'createUserResponse' => NULL,
                'updateUserResponse' => NULL,
              ],
              'success' => FALSE,
              'error' => $e->getMessage(),
            ];
          }
        }
      }
      else {
        try {
          // Update existing user.
          $roleBefore = ['ROLE_USER'];
          $readRightsBefore = [];
          $writeRightsBefore = [];
          foreach ($getUserResponse['data']['openGdbResponse']['grantedAuthorities'] as $authority) {
            if (strpos($authority, 'ROLE_') === 0) {
              $roleBefore = [$authority];
            }
            elseif (strpos($authority, 'READ_') === 0) {
              $readRightsBefore[] = $authority;
            }
            elseif (strpos($authority, 'WRITE_') === 0) {
              $writeRightsBefore[] = $authority;
            }
          }
          $updateUserRequestParams = [
            'type' => 'user',
            'queryParams' => [],
            'routeParams' => [$username],
            'body' => [

              'grantedAuthorities' => array_merge(
                $roleBefore,
                $readRightsBefore,
                $writeRightsBefore,
                [
                  "READ_REPO_$subdomain",
                  "WRITE_REPO_$subdomain",
                ]),
              "appSettings" => [
                "DEFAULT_INFERENCE" => TRUE,
                "DEFAULT_SAMEAS" => TRUE,
                "DEFAULT_VIS_GRAPH_SCHEMA" => TRUE,
                "EXECUTE_COUNT" => TRUE,
                "IGNORE_SHARED_QUERIES" => FALSE,
              ],
              // "dateUpdated" => \Drupal::time()->getRequestTime(),
            ],
          ];
          $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
          $updateUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);
          $createUserResponse = NULL;
        }
        catch (MissingDataException $e) {
          $this->loggerFactory->get('soda_scs_manager')
            ->error("Cannot assemble Request: @error", [
              '@error' => $e->getMessage(),
            ]);
          $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
          return [
            'message' => 'Cannot assemble Request.',
            'data' => [
              'createRepoResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
      }
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot assemble Request: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'createRepoResponse' => $createRepoResponse,
          'getUserResponse' => NULL,
          'createUserResponse' => NULL,
          'updateUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    // Save the component.
    $triplestoreComponent->save();

    $triplestoreComponentServiceKey->scsComponent[] = $triplestoreComponent->id();
    $triplestoreComponentServiceKey->save();
    $triplestoreComponentServiceToken->scsComponent[] = $triplestoreComponent->id();
    $triplestoreComponentServiceToken->save();

    return [
      'message' => $this->t('Created triplestore component %subdomain.', ['%subdomain' => $subdomain]),
      'data' => [
        'triplestoreComponent' => $triplestoreComponent,
        'createRepoResponse' => $createRepoResponse,
        'getUserResponse' => $getUserResponse,
        'createUserResponse' => $createUserResponse,
        'updateUserResponse' => $updateUserResponse,

      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Get all Triplestore Components.
   *
   * @return array
   *   The result array with the Triplestore components.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Read all Triplestore components.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the created component.
   */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Update Triplestore component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the created component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Delete Triplestore component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the created component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {

    try {
      $username = $component->getOwner()->getAccountName();
      $subdomain = $component->get('subdomain')->value;
      $requestParams = [
        'type' => 'repository',
        'queryParams' => [],
        'routeParams' => [$subdomain],
        'body' => [],
      ];

      $openGdbDeleteRepositoryRequest = $this->sodaScsOpenGdbServiceActions->buildDeleteRequest($requestParams);
      $openGdbDeleteRepositoryResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbDeleteRepositoryRequest);

      if (!$openGdbDeleteRepositoryResponse['success']) {
        /** @var \GuzzleHttp\Exception\ClientException $clientException */
        $clientException = $openGdbDeleteRepositoryResponse['data']['openGdbResponse'];
        if (!$clientException->getResponse()->getStatusCode() === 404) {
          return [
            'message' => 'Could not delete triplestore component.',
            'data' => [
              'openGdbResponse' => $openGdbDeleteRepositoryResponse,
              'openGdbUpdateUserResponse' => NULL,
              'openGdbGetUserResponse' => NULL,
              'openGdbDeleteUserResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $openGdbDeleteRepositoryResponse['error'],
          ];
        }
      }
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t('Could not delete triplestore component %component', ['%component' => $subdomain]),
        'data' => [
          'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
          'openGdbUpdateUserResponse' => NULL,
          'openGdbGetUserResponse' => NULL,
          'openGdbDeleteUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
      $requestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => [$username],
        'body' => [],
      ];

      $openGdbGetUserRequest = $this->sodaScsOpenGdbServiceActions->buildGetRequest($requestParams);
      $openGdbGetUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbGetUserRequest);
      if (!$openGdbGetUserResponse['success']) {
        /** @var \GuzzleHttp\Exception\ClientException $clientException */
        $clientException = $openGdbGetUserResponse['data']['openGdbResponse'];
        if ($clientException->getResponse()->getStatusCode() === 404) {

          return [
            'message' => 'Could not get User information for triplestore component.',
            'data' => [
              'openGdbResponse' => $openGdbGetUserResponse,
              'openGdbUpdateUserResponse' => NULL,
              'openGdbGetUserResponse' => $clientException,
              'openGdbDeleteUserResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $openGdbGetUserResponse['error'],
          ];
        }
      }
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t('Could not delete triplestore component %component', ['%component' => $subdomain]),
        'data' => [
          'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
          'openGdbUpdateUserResponse' => NULL,
          'openGdbGetUserResponse' => NULL,
          'openGdbDeleteUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    $authorities = $openGdbGetUserResponse['data']['openGdbResponse']['grantedAuthorities'];
    if ($authorities > 3) {

      try {
        $authorities = array_filter($authorities, function ($authority) use ($subdomain) {
          return !in_array($authority, [
            "READ_REPO_$subdomain",
            "WRITE_REPO_$subdomain",
          ]);
        });

        $updateUserRequestParams = [
          'type' => 'user',
          'queryParams' => [],
          'routeParams' => [$username],
          'body' => [

            'grantedAuthorities' => $authorities,
            "appSettings" => [
              "DEFAULT_INFERENCE" => TRUE,
              "DEFAULT_SAMEAS" => TRUE,
              "DEFAULT_VIS_GRAPH_SCHEMA" => TRUE,
              "EXECUTE_COUNT" => TRUE,
              "IGNORE_SHARED_QUERIES" => FALSE,
            ],
              // "dateUpdated" => \Drupal::time()->getRequestTime(),
          ],
        ];

        $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
        $openGdbUpdateUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);
        $openGdbDeleteUserResponse = NULL;

      }
      catch (\Exception $e) {

        return [
          'message' => $this->t('Could not delete triplestore component %component', ['%component' => $subdomain]),
          'data' => [
            'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
            'openGdbGetUserResponse' => $openGdbGetUserResponse,
            'openGdbUpdateUserResponse' => NULL,
            'openGdbDeleteUserResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }
    elseif ($authorities === 3) {

      try {
        $deleteUserRequestParams = [
          'type' => 'user',
          'queryParams' => [],
          'routeParams' => [$username],
          'body' => [],
        ];

        $openGdbDeleteUserRequest = $this->sodaScsOpenGdbServiceActions->buildDeleteRequest($deleteUserRequestParams);
        $openGdbDeleteUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbDeleteUserRequest);
        $openGdbUpdateUserResponse = NULL;
      }
      catch (\Exception $e) {
        return [
          'message' => $this->t('Could not delete OpenGDB user %username', ['%username' => $username]),
          'data' => [
            'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
            'openGdbGetUserResponse' => $openGdbGetUserResponse,
            'openGdbUpdateUserResponse' => NULL,
            'openGdbDeleteUserResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }
    $component->delete();
    return [
      'message' => $this->t('Deleted triplestore component %component', ['%component' => $subdomain]),
      'data' => [
        'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
        'openGdbGetUserResponse' => $openGdbGetUserResponse,
        'openGdbUpdateUserResponse' => $openGdbUpdateUserResponse,
        'openGdbDeleteUserResponse' => $openGdbDeleteUserResponse,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

}
